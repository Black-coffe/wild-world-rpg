<?php

namespace App\Services\Player;

use App\Models\ActionLogModel;
use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\ResourceModel;
use App\Services\GameSettings\GameSettingsService;
use App\Services\Player\Death\DeathPenaltyCalculator;
use App\Services\Player\Death\InsuranceCalculator;
use App\Services\Player\Death\LootProcessor;
use App\Services\Player\Death\PlayerRespawner;
use CodeIgniter\Database\ResultInterface;
use Config\Database;
use Longman\TelegramBot\Exception\TelegramException;
use App\Services\Telegram\Request;
use Longman\TelegramBot\Telegram;
use Throwable;

/**
 * F2.8 + F2.8b — thin orchestrator поверх 4 сервисов.
 *
 * До декомпозиции: 415 LOC god-класса с 4 моделями и 12 методами.
 * После: ~110 LOC orchestrator + 4 testable сервиса:
 *   - InsuranceCalculator        (стоимость страховки, чистая)
 *   - DeathPenaltyCalculator     (выбор % потерь, чистая)
 *   - LootProcessor              (compute + apply + transfer ресурсов/крафта/золота)
 *   - PlayerRespawner            (выбор клетки respawn'а)
 *
 * Точка входа `handlePlayerDeathAndReward()` сохранила 1:1 контракт
 * (входы/выходы) — caller'ы (DeathRouletteHandler, AttackPlayerAction,
 * прочие PvP-flows) не нуждаются в правках.
 */
class DeathService
{
    private CharacterModel         $characterModel;
    private CharacterResourceModel $characterResourceModel;
    private CraftedItemsLogModel   $craftedItemsLogModel;

    private InsuranceCalculator    $insuranceCalculator;
    private DeathPenaltyCalculator $penaltyCalculator;
    private LootProcessor          $lootProcessor;
    private PlayerRespawner        $respawner;
    private GameSettingsService    $settings;

    /** transport-14: lazy Telegram-мост для отдельного уведомления «машина разбита». */
    private ?Telegram $telegram = null;

    public function __construct(
        ?InsuranceCalculator $insuranceCalculator = null,
        ?DeathPenaltyCalculator $penaltyCalculator = null,
        ?LootProcessor $lootProcessor = null,
        ?PlayerRespawner $respawner = null,
        ?GameSettingsService $settings = null
    ) {
        $this->characterModel         = new CharacterModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();

        $this->insuranceCalculator = $insuranceCalculator ?? new InsuranceCalculator();
        $this->penaltyCalculator   = $penaltyCalculator   ?? new DeathPenaltyCalculator();
        $this->lootProcessor       = $lootProcessor       ?? new LootProcessor();
        $this->respawner           = $respawner           ?? new PlayerRespawner();
        $this->settings            = $settings            ?? new GameSettingsService();
    }

    /**
     * E31 (ADR-131) — конфиг смягчения потерь новичкам из GameSettings (категория combat).
     * Возвращает null, если выключено (newbie_level_max<=0) → dormant (легаси штраф).
     *
     * @return array{level_max:int,penalty_no_base:float,penalty_with_base:float}|null
     */
    private function newbieConfig(): ?array
    {
        $rawMax  = $this->settings->get('combat.death.newbie_level_max', 0);
        $levelMax = is_numeric($rawMax) ? (int) $rawMax : 0;
        if ($levelMax <= 0) {
            return null;
        }
        $rawNoBase   = $this->settings->get('combat.death.newbie_penalty_no_base', 0.10);
        $rawWithBase = $this->settings->get('combat.death.newbie_penalty_with_base', 0.0);

        return [
            'level_max'         => $levelMax,
            'penalty_no_base'   => is_numeric($rawNoBase) ? (float) $rawNoBase : 0.10,
            'penalty_with_base' => is_numeric($rawWithBase) ? (float) $rawWithBase : 0.0,
        ];
    }

    /**
     * ADR-172 — разыгрывать ли дробный остаток штрафа на крафт-предметах.
     *
     * Без этого floor() съедает весь штраф на строках с `quantity=1` (дрон,
     * робот, верстак, транспорт): −3% от одной штуки — ноль, и полис
     * страховки защищает от несуществующего риска. Ключ живёт в GameSettings,
     * чтобы механику можно было погасить из админки без релиза.
     */
    private function fractionalCraftLossEnabled(): bool
    {
        $v = $this->settings->get('combat.death.craft_fractional_loss', true);
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }

        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Обработать смерть персонажа: страховка → штраф → списание/передача
     * имущества → respawn.
     *
     * @return array{
     *   hasBase:bool, penalty:float, newbieProtected:bool,
     *   transferredResources:list<array{resourceId:int,amount:int}>,
     *   transferredCraftItems:list<array{craftedItemId:int,amount:int}>,
     *   transferredGold:int,
     *   vehicleBroken:?string,
     *   success:bool
     * }
     */
    public function handlePlayerDeathAndReward(int $loserId, ?int $winnerId = null, bool $deferVehicleNotice = false): array
    {
        $loserRow = $this->characterModel->find($loserId);
        if (!$loserRow) {
            // Полная форма (E31): early-return совпадает с объявленным типом → без baseline.
            return [
                'hasBase'               => false,
                'penalty'               => 0.0,
                'newbieProtected'       => false,
                'transferredResources'  => [],
                'transferredCraftItems' => [],
                'transferredGold'       => 0,
                'vehicleBroken'         => null,
                'success'               => false,
            ];
        }

        // 1) Insurance check (если страховка активна и хватает золота — штраф 0%).
        $insuranceCovered = $this->tryUseInsurance($loserId, $loserRow);

        // 2) Penalty rate (E31 ADR-131: level-scaled смягчение новичкам, dormant если выкл).
        $hasBase = $insuranceCovered ? false : $this->respawner->hasActiveBase($loserId);
        $loserArr = $loserRow instanceof \App\Entities\CharacterEntity ? $loserRow->toArray() : (is_array($loserRow) ? $loserRow : []);
        $rawLevel = $loserArr['level'] ?? null;
        $level    = is_numeric($rawLevel) ? (int) $rawLevel : 0;
        $newbie   = $insuranceCovered ? null : $this->newbieConfig();
        $deathPenalty = $this->penaltyCalculator->decide($insuranceCovered, $hasBase, $level, $newbie);
        $newbieProtected = $newbie !== null && $level > 0 && $level <= $newbie['level_max'];

        // 3) Сбор имущества проигравшего.
        $loserResources    = $this->characterResourceModel->where('id_characters', $loserId)->findAll();
        $loserGold         = (int) ($loserRow['gold'] ?? 0);
        $loserCraftedItems = $this->normalizeCraftedItemRows(
            $this->craftedItemsLogModel->where('character_id', $loserId)->findAll()
        );

        // 4) Расчёт потерь.
        $lostResources    = $this->lootProcessor->computeResourceLoss($loserResources, $deathPenalty);
        $lostGold         = (int) floor($loserGold * $deathPenalty);
        $lostCraftedItems = $this->lootProcessor->computeCraftLoss(
            $loserCraftedItems,
            $deathPenalty,
            $this->fractionalCraftLossEnabled()
        );

        // 5) Списание у проигравшего.
        $this->lootProcessor->applyLosses($loserId, $lostResources, $lostGold);
        $this->lootProcessor->applyCraftLosses($loserId, $lostCraftedItems);

        // Story chat-requests-batch-11: след потерь в action_log — рождается ЗДЕСЬ, а не
        // в PlayerRespawner (Notes story 05: respawn() видит только characterId и клетку,
        // состав/сумму потерь к тому моменту уже не восстановить — applyLosses() выше их
        // уже списал). Пишем ПОСЛЕ applyLosses()/applyCraftLosses() — золото/ресурсы/крафт
        // реально сняты. Крафт-предметы (роботы/дроны/верстаки/транспорт) — дополнение по
        // тому же запросу team-lead: без них самая дорогая пропажа не названа.
        //
        // Ревью §1: логируем ПОДТВЕРЖДЁННУЮ дельту, а не заказанную величину —
        // `$lostGold`/`$lostResources`/`$lostCraftedItems` это то, что МЫ ХОТЕЛИ списать
        // (посчитано на шаге 4, ДО фактического списания). `LootProcessor::applyLosses()`
        // вызывает `CharacterStatsService::adjust()` с полом `gold>=0` — параллельная
        // трата между шагом 3 (чтение) и шагом 5 (списание) могла срезать часть, а
        // `applyCraftLosses()::186` тихо пропускает строку, исчезнувшую гонкой (см.
        // ревью §5) — то есть заказанная величина систематически может разойтись с
        // реально произошедшей. `logDeathLoss()` поэтому сам перечитывает состояние
        // ПОСЛЕ списания и считает before−after — `$loserResources`/`$loserGold`/
        // `$loserCraftedItems` (шаг 3, снимок ДО) передаются как база сравнения,
        // `$lostResources`/`$lostCraftedItems` (шаг 4) — только СПИСОК КАНДИДАТОВ (какие
        // id вообще могли измениться, чтобы не перечитывать весь инвентарь). Не трогает
        // `LootProcessor` (вне `## Files`) — вся проверка на стороне DeathService.
        $this->logDeathLoss($loserId, $loserArr, $loserGold, $loserResources, $lostResources, $loserCraftedItems, $lostCraftedItems);

        // 5b) transport-14 (замыкает transport-09): смерть не изымает активную машину,
        // а разбивает её — `breakActiveVehicleOnDeath()` был написан и покрыт тестами
        // ещё в story 09, но не вызывался ниоткуда (BUILT-BUT-DEAD). Текст всегда
        // возвращается в результате (`vehicleBroken`); story 16 добавила явный опт-ин
        // `$deferVehicleNotice` — по умолчанию (false) поведение story 14 не меняется:
        // отдельное сообщение уходит через `notifyVehicleBroken()`. Вызывающая сторона,
        // которая берёт склейку на себя (см. DeathRouletteHandler), передаёт true и
        // сама решает, куда приклеить текст — здесь он не отправляется отдельно.
        $vehicleMessage = $this->lootProcessor->breakActiveVehicleOnDeath($loserId);
        if ($vehicleMessage !== null && !$deferVehicleNotice) {
            $this->notifyVehicleBroken($loserArr, $vehicleMessage);
        }

        // 6) Передача части победителю (factor 0.5 без базы / 1.0 с базой).
        $transferredResources = [];
        $transferredCraft     = [];
        $transferredGold      = 0;
        if ($winnerId !== null) {
            $factor = $hasBase ? 1.0 : 0.5;
            $transferredResources = $this->lootProcessor->transferResourcesToWinner($winnerId, $lostResources, $factor);
            $transferredCraft     = $this->lootProcessor->transferCraftToWinner($winnerId, $lostCraftedItems, $factor);
            $transferGold         = (int) floor($lostGold * $factor);
            if ($transferGold > 0) {
                $this->lootProcessor->transferGoldToWinner($winnerId, $transferGold);
                $transferredGold = $transferGold;
            }
        }

        // 7) Respawn.
        $this->respawner->respawn($loserId);

        return [
            'hasBase'               => $hasBase,
            'penalty'               => $deathPenalty,
            'newbieProtected'       => $newbieProtected,
            'transferredResources'  => $transferredResources,
            'transferredCraftItems' => $transferredCraft,
            'transferredGold'       => $transferredGold,
            'vehicleBroken'         => $vehicleMessage,
            'success'               => true,
        ];
    }

    /**
     * Ремонт phpstan-репорта после story 09: `CraftedItemsLogModel::findAll()` отдаёт
     * рыхлый `array<int|string, bool|float|int|object|string|null>|object` на каждую
     * строку (стандартная неопределённость Model::findAll() без параметризации), а
     * `LootProcessor::computeCraftLoss()` начиная со story 09 (`type='transport'`
     * исключение) ожидает честную форму
     * `array{id:int, crafted_item_id:int, quantity:int|string, insured?:int|string, type?:string}`.
     * Сужаем на месте чтения, а не расширяем контракт `computeCraftLoss()` и не глушим
     * баселайном — форма реально известна на этом шаге (та же строка, что дальше пишет
     * `applyCraftLosses()`/`breakActiveVehicleOnDeath()`).
     *
     * @param list<array<int|string,mixed>|object> $rows
     * @return list<array{id:int, crafted_item_id:int, quantity:int|string, insured?:int|string, type?:string}>
     */
    private function normalizeCraftedItemRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $arr = $row instanceof \App\Entities\CharacterEntity ? $row->toArray() : (is_array($row) ? $row : null);
            if ($arr === null) {
                continue;
            }

            $idRaw            = $arr['id'] ?? null;
            $craftedItemIdRaw = $arr['crafted_item_id'] ?? null;
            $quantityRaw      = $arr['quantity'] ?? null;
            if (!is_numeric($idRaw) || !is_numeric($craftedItemIdRaw) || !is_numeric($quantityRaw) && !is_string($quantityRaw)) {
                continue;
            }

            $entry = [
                'id'              => (int) $idRaw,
                'crafted_item_id' => (int) $craftedItemIdRaw,
                'quantity'        => is_int($quantityRaw) ? $quantityRaw : (string) $quantityRaw,
            ];

            $insuredRaw = $arr['insured'] ?? null;
            if (is_int($insuredRaw) || is_string($insuredRaw)) {
                $entry['insured'] = $insuredRaw;
            }

            $typeRaw = $arr['type'] ?? null;
            if (is_string($typeRaw)) {
                $entry['type'] = $typeRaw;
            }

            $out[] = $entry;
        }

        return $out;
    }

    /**
     * @return bool true если страховка списалась успешно (штраф 0%).
     */
    private function tryUseInsurance(int $loserId, array|\App\Entities\CharacterEntity $loserRow): bool
    {
        if ((int) ($loserRow['insurance'] ?? 0) !== 1) {
            return false;
        }

        $totalResourceRows = $this->characterResourceModel
            ->where('id_characters', $loserId)
            ->countAllResults();
        $cost = $this->insuranceCalculator->calculate($loserRow, $totalResourceRows);

        if ((int) $loserRow['gold'] >= $cost) {
            // Fix 2026-07-13 (класс lost-update): списание от СВЕЖЕГО золота под
            // row-lock'ом; достаточность перепроверяется внутри (decreaseGold).
            if (! $this->characterModel->decreaseGold($loserId, $cost)) {
                // Золото исчезло параллельно — страховка сгорает без списания.
                $this->characterModel->update($loserId, ['insurance' => 0]);
                return false;
            }
            $this->characterModel->update($loserId, ['insurance' => 0]);
            return true;
        }

        // Недоступная страховка — сгорает без эффекта.
        $this->characterModel->update($loserId, ['insurance' => 0]);
        return false;
    }

    /**
     * transport-14 — доставка текста «машина разбита» игроку отдельным сообщением
     * (по образцу {@see \App\Services\Player\Progression\LevelUpNotifier}): вызывающие
     * стороны (`DeathRouletteHandler`, `AttackPlayerAction`, `BossEncounterService`) и
     * `DeathMessageBuilder` вне `## Files` этой story, поэтому текст не вклеивается в
     * чужое death-сообщение, а уходит своим — самодостаточным (media-off, ADR-020) и
     * markdown-safe (текст `LootProcessor` без `*`/`_`).
     *
     * @param array<int|string,mixed> $loserArr
     */
    private function notifyVehicleBroken(array $loserArr, string $message): void
    {
        $chatId = $this->chatIdFor($loserArr);
        if ($chatId === null) {
            return;
        }

        $this->sendVehicleBrokenMessage($chatId, $message);
    }

    /**
     * Story chat-requests-batch-11 — след потерь при смерти в `action_log` (экран «Куда
     * ушло», story 06): золото, состав ресурсов И крафт-предметов (роботы/дроны/
     * верстаки/транспорт) — не одна безымянная сумма (жалоба Max Syskov «исчезло 50%
     * ресурсов» — вопрос был именно про состав; крафт-предметы дописаны по тому же
     * запросу — без них самая дорогая пропажа осталась бы неназванной). Insert
     * оборачиваем в try/catch — сбой форензики не должен откатывать/блокировать уже
     * проведённое списание (тот же паттерн, что `TaxCollectionHandler::logTaxDeduction()`
     * / `PlayerRespawner::logDeathTrace()` из story 05). Нулевые потери по ВСЕМ трём
     * категориям (страховка сработала, либо просто нечего было терять) — запись-пустышку
     * не пишем.
     *
     * Вызывается ПОСЛЕ `applyLosses()`/`applyCraftLosses()` — перечитывает фактическое
     * состояние и логирует ПОДТВЕРЖДЁННУЮ (before−after) дельту, не заказанную (ревью
     * §1). `$loserGold`/`$loserResources`/`$loserCraftedItems` — снимок ДО списания
     * (шаг 3); `$lostResources`/`$lostCraftedItems` (шаг 4) используются только как
     * список id-кандидатов (что вообще могло измениться), не как источник суммы.
     *
     * @param list<array<int|string,mixed>|object>                             $loserResources
     * @param list<array{charResId:int,resourceId:int,lossAmount:int}>          $lostResources
     * @param list<array{id:int,crafted_item_id:int,quantity:int|string}>       $loserCraftedItems
     * @param list<array{logId:int,craftedItemId:int,lossAmount:int}>           $lostCraftedItems
     * @param array<int|string,mixed>                                          $loserArr
     */
    private function logDeathLoss(
        int $loserId,
        array $loserArr,
        int $loserGold,
        array $loserResources,
        array $lostResources,
        array $loserCraftedItems,
        array $lostCraftedItems
    ): void {
        $confirmedGold          = $this->confirmedGoldLoss($loserId, $loserGold);
        $confirmedResources     = $this->confirmedResourceLoss($loserResources, $lostResources);
        $confirmedCraftedItems  = $this->confirmedCraftedItemLoss($loserCraftedItems, $lostCraftedItems);

        if ($confirmedGold <= 0 && $confirmedResources === [] && $confirmedCraftedItems === []) {
            return;
        }

        try {
            $parts = [];
            if ($confirmedGold > 0) {
                $parts[] = "-{$confirmedGold} золота";
            }
            if ($confirmedResources !== []) {
                $parts[] = 'ресурсы: ' . $this->describeResourceLoss($confirmedResources);
            }
            if ($confirmedCraftedItems !== []) {
                $parts[] = 'предметы: ' . $this->describeCraftedItemLoss($confirmedCraftedItems);
            }

            (new ActionLogModel())->save([
                'character_id'  => $loserId,
                'chat_id'       => $this->chatIdFor($loserArr) ?? 0,
                'action_name'   => 'DEATH_LOSS',
                'action_status' => 'Completed',
                'description'   => mb_substr('Смерть персонажа: ' . implode('; ', $parts), 0, 500),
            ]);
        } catch (Throwable $e) {
            log_message('error', '[DeathService::logDeathLoss] insert failed: ' . $e->getMessage());
        }
    }

    /**
     * Подтверждённая потеря золота = свежее `characters.gold` минус то, что было ДО
     * списания. Персонаж исчез между шагом 3 и логом (крайний случай) — 0, не выдумываем.
     */
    private function confirmedGoldLoss(int $loserId, int $goldBefore): int
    {
        $fresh = $this->characterModel->find($loserId);
        if ($fresh === null) {
            return 0;
        }
        $freshArr  = $fresh instanceof \App\Entities\CharacterEntity ? $fresh->toArray() : (is_array($fresh) ? $fresh : []);
        $goldAfter = is_numeric($freshArr['gold'] ?? null) ? (int) $freshArr['gold'] : $goldBefore;

        return max(0, $goldBefore - $goldAfter);
    }

    /**
     * Подтверждённая потеря ресурсов: сравнивает снимок ДО (`$loserResources`, шаг 3) со
     * свежим состоянием строк-кандидатов (`$lostResources` называет их id, шаг 4) ПОСЛЕ
     * `applyLosses()` — одним batch-запросом (`whereIn`), не в цикле. Строка исчезла
     * (`decreaseQtyById()` удаляет при остатке ≤0) — считаем, что забрали всё, что было.
     *
     * @param list<array<int|string,mixed>|object>                       $loserResources
     * @param list<array{charResId:int,resourceId:int,lossAmount:int}>  $lostResources
     * @return list<array{charResId:int,resourceId:int,lossAmount:int}>
     */
    private function confirmedResourceLoss(array $loserResources, array $lostResources): array
    {
        if ($lostResources === []) {
            return [];
        }

        $beforeById = [];
        foreach ($loserResources as $row) {
            $arr = $row instanceof \App\Entities\CharacterEntity ? $row->toArray() : (is_array($row) ? $row : null);
            if ($arr === null || !is_numeric($arr['id'] ?? null)) {
                continue;
            }
            $beforeById[(int) $arr['id']] = is_numeric($arr['quantity'] ?? null) ? (int) $arr['quantity'] : 0;
        }

        $charResIds = array_column($lostResources, 'charResId');
        $afterById  = [];
        foreach ($this->characterResourceModel->whereIn('id', $charResIds)->findAll() as $row) {
            if (is_array($row) && is_numeric($row['id'] ?? null)) {
                $afterById[(int) $row['id']] = is_numeric($row['quantity'] ?? null) ? (int) $row['quantity'] : 0;
            }
        }

        $confirmed = [];
        foreach ($lostResources as $candidate) {
            $id     = $candidate['charResId'];
            $before = $beforeById[$id] ?? 0;
            $after  = $afterById[$id]  ?? 0; // строки нет — списано подчистую
            $delta  = $before - $after;
            if ($delta > 0) {
                $confirmed[] = [
                    'charResId'  => $id,
                    'resourceId' => $candidate['resourceId'],
                    'lossAmount' => $delta,
                ];
            }
        }

        return $confirmed;
    }

    /**
     * Подтверждённая потеря крафт-предметов — тот же приём, что и у ресурсов, только по
     * `crafted_items_log`. Закрывает и ревью §5 (`LootProcessor::applyCraftLosses()`
     * молча пропускает строку, исчезнувшую между расчётом и списанием, — `continue` без
     * следа): такая строка здесь получит `after=0` от `before=0` (её и в снимке ДО не
     * было бы, если её удалили раньше шага 3) либо `after=0` от реального `before` — в
     * любом случае считается фактическая, а не воображаемая величина.
     *
     * @param list<array{id:int,crafted_item_id:int,quantity:int|string}> $loserCraftedItems
     * @param list<array{logId:int,craftedItemId:int,lossAmount:int}>     $lostCraftedItems
     * @return list<array{logId:int,craftedItemId:int,lossAmount:int}>
     */
    private function confirmedCraftedItemLoss(array $loserCraftedItems, array $lostCraftedItems): array
    {
        if ($lostCraftedItems === []) {
            return [];
        }

        $beforeById = [];
        foreach ($loserCraftedItems as $row) {
            $beforeById[(int) $row['id']] = is_numeric($row['quantity']) ? (int) $row['quantity'] : 0;
        }

        $logIds    = array_column($lostCraftedItems, 'logId');
        $afterById = [];
        foreach ($this->craftedItemsLogModel->whereIn('id', $logIds)->findAll() as $row) {
            if (is_array($row) && is_numeric($row['id'] ?? null)) {
                $afterById[(int) $row['id']] = is_numeric($row['quantity'] ?? null) ? (int) $row['quantity'] : 0;
            }
        }

        $confirmed = [];
        foreach ($lostCraftedItems as $candidate) {
            $id     = $candidate['logId'];
            $before = $beforeById[$id] ?? 0;
            $after  = $afterById[$id]  ?? 0;
            $delta  = $before - $after;
            if ($delta > 0) {
                $confirmed[] = [
                    'logId'         => $id,
                    'craftedItemId' => $candidate['craftedItemId'],
                    'lossAmount'    => $delta,
                ];
            }
        }

        return $confirmed;
    }

    /** Максимум позиций, названных поимённо в одной строке `description` — дальше «и ещё N». */
    private const DESCRIPTION_ITEM_LIMIT = 6;

    /**
     * Человекочитаемый состав потерянных ресурсов: «Дерево ×5, Вода ×3». Имя берём из
     * `resources.name` (rus, см. `.claude/rules/db-schema.md`); не нашлось строки —
     * нейтральное «Ресурс#{id}», как и остальные `Res#{id}`-фолбэки в игре.
     *
     * @param list<array{charResId:int,resourceId:int,lossAmount:int}> $lostResources
     */
    private function describeResourceLoss(array $lostResources): string
    {
        $resourceIds = array_values(array_unique(array_column($lostResources, 'resourceId')));
        /** @var array<int,string> $nameById */
        $nameById = [];
        if ($resourceIds !== []) {
            foreach ((new ResourceModel())->whereIn('id', $resourceIds)->findAll() as $r) {
                $rid  = (int) $r->id;
                $name = $r->name;
                $nameById[$rid] = $name !== '' ? $name : "Ресурс#{$rid}";
            }
        }

        $parts = [];
        foreach ($lostResources as $lr) {
            $name    = $nameById[$lr['resourceId']] ?? "Ресурс#{$lr['resourceId']}";
            $parts[] = "{$name} ×{$lr['lossAmount']}";
        }

        return $this->joinWithLimit($parts, self::DESCRIPTION_ITEM_LIMIT);
    }

    /**
     * Человекочитаемый состав потерянных крафт-предметов (роботы/дроны/верстаки/
     * транспорт — то же, что списывает `applyCraftLosses()` чуть выше): «Промышленник
     * ×1, Дрон-разведчик ×1». Имя — `crafted_items.name_rus` (см.
     * `.claude/rules/db-schema.md`) батч-резолвом (`whereIn`), не запросом в цикле;
     * не нашлось строки — нейтральное «Предмет#{id}».
     *
     * @param list<array{logId:int,craftedItemId:int,lossAmount:int}> $lostCraftedItems
     */
    private function describeCraftedItemLoss(array $lostCraftedItems): string
    {
        $craftedItemIds = array_values(array_unique(array_column($lostCraftedItems, 'craftedItemId')));
        /** @var array<int,string> $nameById */
        $nameById = [];
        if ($craftedItemIds !== []) {
            foreach ((new CraftedItemsModel())->whereIn('id', $craftedItemIds)->findAll() as $row) {
                if (! is_array($row) || ! is_numeric($row['id'] ?? null)) {
                    continue;
                }
                $cid  = (int) $row['id'];
                $name = $row['name_rus'] ?? null;
                $nameById[$cid] = is_string($name) && $name !== '' ? $name : "Предмет#{$cid}";
            }
        }

        $parts = [];
        foreach ($lostCraftedItems as $lc) {
            $name    = $nameById[$lc['craftedItemId']] ?? "Предмет#{$lc['craftedItemId']}";
            $parts[] = "{$name} ×{$lc['lossAmount']}";
        }

        return $this->joinWithLimit($parts, self::DESCRIPTION_ITEM_LIMIT);
    }

    /**
     * Склеивает позиции через запятую, но не длиннее `$limit` поимённо — иначе строка
     * `action_log.description` (которую экран story 06 покажет игроку дословно)
     * разрастается до нечитаемой на персонаже с богатым инвентарём. Остаток называется
     * числом, не молчанием.
     *
     * @param list<string> $parts
     */
    private function joinWithLimit(array $parts, int $limit): string
    {
        $total = count($parts);
        if ($total <= $limit) {
            return implode(', ', $parts);
        }

        $rest = $total - $limit;

        return implode(', ', array_slice($parts, 0, $limit)) . " и ещё {$rest}";
    }

    /**
     * chat_id персонажа = telegram_id владельца.
     *
     * @param array<int|string,mixed> $loserArr
     */
    private function chatIdFor(array $loserArr): ?int
    {
        $userIdRaw = $loserArr['telegram_user_id'] ?? null;
        $userId    = is_numeric($userIdRaw) ? (int) $userIdRaw : 0;
        if ($userId <= 0) {
            return null;
        }

        try {
            $result = Database::connect()
                ->table('telegram_users')
                ->select('telegram_id')
                ->where('id', $userId)
                ->get();
            if (! $result instanceof ResultInterface) {
                return null;
            }
            $row = $result->getRowArray();
        } catch (Throwable $e) {
            log_message('warning', '[DeathService] chat lookup failed: ' . $e->getMessage());

            return null;
        }

        $tg = $row['telegram_id'] ?? null;

        return is_numeric($tg) && (int) $tg !== 0 ? (int) $tg : null;
    }

    /**
     * Seam для тестов: реальная отправка. Никогда не бросает наружу — обработка смерти
     * не должна падать из-за rate-limit или сетевой ошибки Telegram.
     */
    protected function sendVehicleBrokenMessage(int $chatId, string $text): void
    {
        try {
            $this->telegram();
            $response = Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => $text,
                'parse_mode' => 'Markdown',
            ]);
            if (! $response->isOk()) {
                log_message('warning', '[DeathService] sendMessage not ok: ' . $response->getDescription());
            }
        } catch (Throwable $e) {
            log_message('error', '[DeathService] sendMessage exception: ' . $e->getMessage());
        }
    }

    /** Ленивая инициализация Telegram-моста (как в BaseTaskHandler/LevelUpNotifier). */
    private function telegram(): Telegram
    {
        if ($this->telegram === null) {
            try {
                $this->telegram = new Telegram(
                    (string) getenv('telegram.API_KEY'),
                    (string) getenv('telegram.BOT_USERNAME')
                );
                Request::initialize($this->telegram);
            } catch (TelegramException $e) {
                log_message('error', '[DeathService] Telegram init: ' . $e->getMessage());
                $this->telegram = new Telegram('invalid', 'invalid');
            }
        }

        return $this->telegram;
    }
}
