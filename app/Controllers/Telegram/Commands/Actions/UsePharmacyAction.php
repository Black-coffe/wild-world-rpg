<?php

namespace App\Controllers\Telegram\Commands\Actions;

use App\Services\Telegram\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\CharacterModel;
use App\Services\Food\FoodBuffService;
use App\Services\GameSettings\GameSettingsService;
use App\Services\Craft\ConsumableExpiryService;

class UsePharmacyAction extends BaseAction
{
    protected $craftedItemsLogModel;
    protected $craftedItemsModel;
    protected $characterModel;

    /** ADR-094: множитель heal-эффекта при просрочке медикамента (1.0 = свежий). */
    private float $degradeMult = 1.0;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->craftedItemsLogModel = new CraftedItemsLogModel();
        $this->craftedItemsModel = new CraftedItemsModel();
        $this->characterModel = new CharacterModel();
    }

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendResponse('Пользователь не найден в базе данных или персонаж не определён.');
        }

        $callbackData = $this->callbackQuery->getData();

        // S19 (v0.51.201): берём ВСЁ после первого префикса, а не explode('_')[1].
        // Прежний split ломался на name_eng с подчёркиванием/пробелом
        // (headache_tablets → «headache»; «Common cold tincture»). Legacy 8
        // предметов без подчёркиваний — поведение не меняется.
        $prefix = 'usePharmacy_';
        if (!str_starts_with($callbackData, $prefix)) {
            return $this->sendResponse('Неправильные данные кнопки.');
        }
        $medicineName = substr($callbackData, strlen($prefix));
        if ($medicineName === '') {
            return $this->sendResponse('Неправильные данные кнопки.');
        }

        $itemId = $this->getCraftedItemId($medicineName);
        if (!$itemId) {
            return $this->sendResponse("Препарат '{$medicineName}' не найден.");
        }

        // Проверяем, есть ли вообще предмет (quantity > 0).
        $itemUsage = $this->craftedItemsLogModel
            ->where('character_id', $character['id'])
            ->where('crafted_item_id', $itemId)
            ->first();

        if (!$itemUsage || $itemUsage['quantity'] <= 0) {
            return $this->sendResponse("У тебя нет нужного препарата, или он закончился.");
        }

        // ADR-094: медикамент (type='drug') с durability_time в прошлом → heal-эффект
        // деградирует (× stale_effect_percent%). Предмет НЕ теряется, заряды не трогаются.
        // Еда деградирует свою «Сытость» отдельно (ниже, FoodBuffService).
        $this->degradeMult = 1.0;
        $craftedItemRow    = $this->craftedItemsModel->find($itemId);
        $isMedical         = is_array($craftedItemRow) && ($craftedItemRow['type'] ?? '') === 'drug';
        if ($isMedical) {
            $this->degradeMult = (new ConsumableExpiryService())
                ->effectMultiplier($itemUsage['durability_time'] ?? null);
        }

        // V9 (ADR-034): если съеденное — блюдо (food-buff), ставим «Сытость»
        // (well_fed_until = now + food.<snake>.well_fed_minutes). Медикаменты не дают.
        // V10 (ADR-035): длительность урезается, если блюдо зачерствело (durability_time).
        $this->maybeApplyWellFed((int) $character['id'], $medicineName, $itemUsage['durability_time'] ?? null);

        // S19 (v0.51.201): data-driven heal через GameSettings (admin-tunable,
        // constitutional ADR-024). Если для предмета заданы ключи
        // medical.<snake_name_eng>.heal_health / .heal_tired — применяем их.
        // Покрывает 3 новых T3 medical + 2 orphan'а (headache_tablets,
        // Common cold tincture). Legacy 8 предметов ключей не имеют → fall
        // through на switch ниже (0 регрессии).
        $settingsEffects = $this->resolveEffectsFromSettings($medicineName);
        if ($settingsEffects !== null) {
            return $this->applyMedicineEffect($character, $itemId, $settingsEffects);
        }

        // Здесь — логика, зависящая от имени (или сразу через БД)
        switch ($medicineName) {
            case 'TonicElixir':
                return $this->applyMedicineEffect($character, $itemId, [
                    'health' => 18,
                    'tired'  => 16,
                ]);
            case 'Antiseptic':
                return $this->applyMedicineEffect($character, $itemId, [
                    'health' => 4,
                    'tired'  => 2,
                ]);
            case 'Bandage':
                return $this->applyMedicineEffect($character, $itemId, [
                    'health' => 2,
                    'tired'  => 1,
                ]);
            case 'AnalgesicPowder':
                return $this->applyMedicineEffect($character, $itemId, [
                    'health' => 18,
                    'tired'  => -4,
                ]);
            case 'Sedative':
                return $this->applyMedicineEffect($character, $itemId, [
                    'health' => 5,
                    'tired'  => 30,
                ]);
            case 'Stimulator':
                return $this->applyMedicineEffect($character, $itemId, [
                    'health' => 25,
                    'tired'  => 15,
                ]);
            case 'Regenerator':
                return $this->applyMedicineEffect($character, $itemId, [
                    'health' => 30,
                    'tired'  => 20,
                ]);
            case 'FirstAidKit':
                return $this->applyMedicineEffect($character, $itemId, [
                    'health' => 40,
                    'tired'  => 20,
                ]);
            default:
                return $this->sendResponse("Препарат '{$medicineName}' не найден в списке доступных.");
        }
    }

    /**
     * S19 (v0.51.201): прочитать heal-эффект предмета из GameSettings
     * (admin-tunable). Ключи: medical.<snake_name_eng>.heal_health / .heal_tired.
     * Возвращает null если ключ heal_health не задан (→ legacy switch).
     *
     * @return array{health:int,tired:int}|null
     */
    private function resolveEffectsFromSettings(string $medicineName): ?array
    {
        $snake = $this->toSnakeCase($medicineName);
        $gs     = new GameSettingsService();
        $health = $gs->get("medical.{$snake}.heal_health", null);
        if ($health === null || !is_numeric($health)) {
            return null;
        }
        $tired = $gs->get("medical.{$snake}.heal_tired", 0);
        return [
            'health' => (int) $health,
            'tired'  => is_numeric($tired) ? (int) $tired : 0,
        ];
    }

    /**
     * name_eng → setting-key segment.
     *   SyntheticMedicine    → synthetic_medicine
     *   "Common cold tincture" → common_cold_tincture
     *   headache_tablets     → headache_tablets
     */
    private function toSnakeCase(string $name): string
    {
        $s = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name) ?? $name; // camelCase boundary
        $s = str_replace([' ', '-'], '_', $s);                             // spaces / dashes
        $s = preg_replace('/_+/', '_', $s) ?? $s;                          // collapse repeats
        return strtolower(trim($s, '_'));
    }

    /**
     * V9 (ADR-034): если съеденный предмет — блюдо с food.<snake>.well_fed_minutes,
     * выставляет characters.well_fed_until = now + minutes («Сытость»). Медикаменты
     * (minutes=0) не дают buff'а. Killswitch food.buffs.enabled.
     */
    private function maybeApplyWellFed(int $charId, string $medicineName, mixed $durabilityTime = null): void
    {
        $fb = new FoodBuffService();
        if (! $fb->isEnabled()) {
            return;
        }
        $base = $fb->mealWellFedMinutes($this->toSnakeCase($medicineName));
        if ($base <= 0) {
            return;
        }
        // V10: зачерствевшее блюдо даёт меньше сытости (durability_time в прошлом).
        $minutes = $fb->effectiveWellFedMinutes($base, $durabilityTime);
        if ($minutes <= 0) {
            return;
        }
        $until = (new \DateTime())->modify("+{$minutes} minutes")->format('Y-m-d H:i:s');
        $this->characterModel->update($charId, ['well_fed_until' => $until]);
    }

    private function applyMedicineEffect(array|\App\Entities\CharacterEntity $character, int $itemId, array $effects)
    {
        // ADR-094: просроченный медикамент лечит слабее (× degradeMult). Знак сохраняется,
        // ненулевой эффект не схлопывается в 0 (min |1|) — предмет всё ещё что-то делает.
        if ($this->degradeMult < 1.0) {
            foreach ($effects as $key => $val) {
                if (!is_numeric($val) || (int) $val === 0) {
                    continue;
                }
                $scaled = (int) round((int) $val * $this->degradeMult);
                if ($scaled === 0) {
                    $scaled = (int) $val > 0 ? 1 : -1;
                }
                $effects[$key] = $scaled;
            }
        }

        // 🔴 Fix lost-update (2026-07-13): раньше значения брались из снапшота
        // персонажа, прочитанного в НАЧАЛЕ запроса, и писались обратно
        // абсолютными числами по всем 7 полям. Два конкурентных запроса
        // (быстрые тапы «Успокоительное → Стимулятор», webhook-retry,
        // параллельный worker с XP/золотом) молча затирали друг друга
        // last-writer-wins: второй препарат откатывал эффект первого.
        // Теперь: CharacterStatsService — дельта от СВЕЖИХ значений под
        // row-lock'ом (SELECT ... FOR UPDATE), пишутся только меняемые поля.
        $charId = (int) $character['id'];
        $result = (new \App\Services\Player\CharacterStatsService())->adjust($charId, $effects);

        if ($result === null) {
            return $this->sendResponse('Персонаж не найден.');
        }

        $originalValues = $result['before'];
        $newValues      = $result['after'];

        // Списываем 1 единицу препарата (учитывая durability_count)
        if (!$this->decrementItemUsage($charId, $itemId)) {
            return $this->sendResponse('Ошибка при списании использования препарата.');
        }

        // Формируем «красивое» игровое сообщение
        return $this->sendUsageMessage($charId, $originalValues, $newValues, $itemId);
    }

    /**
     * Логика для списания 1 шт. препарата.
     */
    private function decrementItemUsage(int $characterId, int $itemId): bool
    {
        $itemUsage = $this->craftedItemsLogModel->where([
            'character_id' => $characterId,
            'crafted_item_id' => $itemId
        ])->first();

        if (!$itemUsage) {
            log_message('error', "Item usage not found for character_id={$characterId} and item_id={$itemId}");
            return false;
        }

        // 🔴 Fix «бесконечный медовый сбитень» (2026-08-09): раньше остаток доз брался
        // из `durability_count` КАК ЕСТЬ. У строк, выданных PvE-наградой, там лежала
        // константа 100 — при базе 1 это давало 100 бесплатных применений, а экран всё
        // это время честно писал «Остаток: 1 шт.». Теперь остаток зажат ёмкостью
        // шаблона: min(остаток, база).
        $baseDurability = CraftedItemsLogModel::baseCharges(
            $this->craftedItemsModel->find($itemId)['durability_count'] ?? null
        );
        $chargesLeft = CraftedItemsLogModel::effectiveCharges(
            $itemUsage['durability_count'] ?? null,
            $baseDurability
        );

        // Если в дозах ещё есть запас, уменьшим его
        if ($chargesLeft > 1) {
            $this->craftedItemsLogModel->update($itemUsage['id'], [
                'durability_count' => $chargesLeft - 1
            ]);
        } else {
            // Иначе уменьшаем quantity на 1
            if ($itemUsage['quantity'] > 1) {
                // Начинаем следующую единицу стака — доз снова полная база
                $this->craftedItemsLogModel->update($itemUsage['id'], [
                    'quantity' => $itemUsage['quantity'] - 1,
                    'durability_count' => $baseDurability
                ]);
            } else {
                // Если quantity = 1, то препарат заканчивается
                $this->craftedItemsLogModel->delete($itemUsage['id']);
            }
        }

        return true;
    }

    /**
     * Отправляем итоговое сообщение (более «игровое»).
     */
    private function sendUsageMessage(int $characterId, array $originalValues, array $newValues, int $itemId)
    {
        // Узнаем, сколько препарата осталось
        $itemUsage = $this->craftedItemsLogModel->where([
            'character_id' => $characterId,
            'crafted_item_id' => $itemId
        ])->first();

        $qtyLeft = $itemUsage['quantity'] ?? 0;

        // Узнаем название предмета
        $item = $this->craftedItemsModel->find($itemId);
        $itemName = $item ? ($item['name_rus'] ?? 'Препарат') : 'Препарат';

        // Собираем текст изменений
        $message  = "💊 *{$itemName} применён!* 💊\n\n";
        $message .= "Ты осторожно используешь «{$itemName}», надеясь, что остатки былых знаний медицины тебя не подведут...\n\n";

        // Подробно выводим изменения
        // (Например, если HP выросло, показать +X, если усталость выросла/уменьшилась, тоже показать разницу)
        $reportLines = [];

        // Мапа названий для красоты
        $attributeNames = [
            'health'    => 'Здоровье',
            'tired'     => 'Выносливость',
            'gold'      => 'Золото',
            'experience'=> 'Опыт',
            'strength'  => 'Сила',
            'agility'   => 'Ловкость',
            'intellect' => 'Интеллект'
        ];

        foreach ($newValues as $key => $newVal) {
            if (isset($originalValues[$key]) && $newVal != $originalValues[$key]) {
                $diff = $newVal - $originalValues[$key];
                $attrName = $attributeNames[$key] ?? ucfirst($key);
                $sign = ($diff > 0) ? '+' : ''; // если diff +, показываем "+"
                $reportLines[] = "• {$attrName}: {$originalValues[$key]} → *{$newVal}* (_{$sign}{$diff}_)";
            }
        }

        // Если никаких изменений нет (бывает, если уже был cap=100), можно добавить фразу
        if (empty($reportLines)) {
            $message .= "Кажется, твой организм уже достиг предела по этому параметру, и эффект не подействовал.\n";
        } else {
            $message .= "Вот как изменились твои характеристики:\n";
            foreach ($reportLines as $line) {
                $message .= $line . "\n";
            }
        }

        // Сколько осталось единиц препарата
        $message .= "\nОстаток «{$itemName}»: *{$qtyLeft} шт.*\n";

        // Если в упаковке несколько доз — говорим об этом прямо. Иначе повторное
        // применение «одной штуки» читается как баг «предмет не заканчивается».
        $baseCharges = CraftedItemsLogModel::baseCharges(
            is_array($item) ? ($item['durability_count'] ?? null) : null
        );
        if ($baseCharges > 1 && $itemUsage) {
            $left = CraftedItemsLogModel::effectiveCharges($itemUsage['durability_count'] ?? null, $baseCharges);
            $message .= "Доз в начатой упаковке: *{$left} из {$baseCharges}*\n";
        }

        // ADR-094: предупреждение о просрочке (эффект был снижен).
        if ($this->degradeMult < 1.0) {
            $lostPct = (int) round((1 - $this->degradeMult) * 100);
            $message .= "\n🕒 *Препарат просрочен* — лечебный эффект снижен на {$lostPct}%. Крафти свежее, чтобы лечило в полную силу.\n";
        }

        // V9 (ADR-034): если активна «Сытость» (поел блюдо) — сообщаем о buff'е.
        $fb      = new FoodBuffService();
        $charRow = $this->characterModel->find($characterId);
        $wfu     = $charRow !== null ? ($charRow['well_fed_until'] ?? null) : null;
        if ($fb->isWellFed($wfu)) {
            $tsEnd    = is_string($wfu) ? strtotime($wfu) : false;
            $minsLeft = $tsEnd !== false ? max(1, (int) ceil(($tsEnd - time()) / 60)) : 0;
            // E21 Ф1 (ADR-121): пока активно боевое измерение — сытость помогает и в бою.
            $combat   = $fb->combatEnabled()
                ? ', в бою сильнее и крепче'
                : '';
            $message .= "\n🍖 *Сытость активна* — крафт быстрее, добыча щедрее{$combat} (ещё ~{$minsLeft} мин).\n";
        }

        $message .= "\n_В этом жестоком пустоши каждый баф может спасти твою шкуру. Береги себя!_\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory']
                ]
            ]
        ];

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'       => $message,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    private function getCraftedItemId($itemName)
    {
        $item = $this->craftedItemsModel->where('name_eng', $itemName)->first();
        return $item ? $item['id'] : null;
    }

    private function sendResponse($text)
    {
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory']
                ]
            ]
        ];

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard)
        ]);
    }
}
