<?php

namespace App\Services\Player;

use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
use App\Models\CraftedItemsLogModel;
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
