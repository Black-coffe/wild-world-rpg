<?php

namespace App\Controllers\Telegram\Commands\Actions;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\CharacterModel;
use App\Services\Food\FoodBuffService;
use App\Services\GameSettings\GameSettingsService;

class UsePharmacyAction extends BaseAction
{
    protected $craftedItemsLogModel;
    protected $craftedItemsModel;
    protected $characterModel;

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

        // V9 (ADR-034): если съеденное — блюдо (food-buff), ставим «Сытость»
        // (well_fed_until = now + food.<snake>.well_fed_minutes). Медикаменты не дают.
        $this->maybeApplyWellFed((int) $character['id'], $medicineName);

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
    private function maybeApplyWellFed(int $charId, string $medicineName): void
    {
        $fb = new FoodBuffService();
        if (! $fb->isEnabled()) {
            return;
        }
        $minutes = $fb->mealWellFedMinutes($this->toSnakeCase($medicineName));
        if ($minutes <= 0) {
            return;
        }
        $until = (new \DateTime())->modify("+{$minutes} minutes")->format('Y-m-d H:i:s');
        $this->characterModel->update($charId, ['well_fed_until' => $until]);
    }

    private function applyMedicineEffect(array|\App\Entities\CharacterEntity $character, int $itemId, array $effects)
    {
        // Сохраняем исходные значения
        $originalValues = [
            'health'    => $character['health']     ?? 0,
            'tired'     => $character['tired']      ?? 0,
            'gold'      => $character['gold']       ?? 0,
            'experience'=> $character['experience'] ?? 0,
            'strength'  => $character['strength']   ?? 0,
            'agility'   => $character['agility']    ?? 0,
            'intellect' => $character['intellect']  ?? 0,
        ];

        $newValues = $originalValues;

        // Применяем изменения (и ограничиваем health/tired максимумом 100)
        foreach ($effects as $key => $change) {
            if (array_key_exists($key, $newValues)) {
                $newValue = $newValues[$key] + $change;
                if ($key === 'health' || $key === 'tired') {
                    $newValues[$key] = min(100, max(0, $newValue));
                    // max(0, ...) чтобы не уходить в отрицательные значения
                } else {
                    $newValues[$key] = $newValue;
                }
            }
        }

        // Обновляем персонажа
        $this->characterModel->update($character['id'], $newValues);

        // Списываем 1 единицу препарата (учитывая durability_count)
        if (!$this->decrementItemUsage($character['id'], $itemId)) {
            return $this->sendResponse('Ошибка при списании использования препарата.');
        }

        // Формируем «красивое» игровое сообщение
        return $this->sendUsageMessage($character['id'], $originalValues, $newValues, $itemId);
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

        // Проверяем durability_count
        if (!isset($itemUsage['durability_count'])) {
            // Если нет поля, можем просто считать durability_count = 1
            $itemUsage['durability_count'] = 1;
        }

        // Если в durability_count ещё есть запас, уменьшим его
        if ($itemUsage['durability_count'] > 1) {
            $this->craftedItemsLogModel->update($itemUsage['id'], [
                'durability_count' => $itemUsage['durability_count'] - 1
            ]);
        } else {
            // Иначе уменьшаем quantity на 1
            if ($itemUsage['quantity'] > 1) {
                // Обнуляем счётчик durability и уменьшаем quantity
                $baseDurability = $this->craftedItemsModel->find($itemId)['durability_count'] ?? 1;
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

        // V9 (ADR-034): если активна «Сытость» (поел блюдо) — сообщаем о buff'е.
        $fb      = new FoodBuffService();
        $charRow = $this->characterModel->find($characterId);
        $wfu     = $charRow !== null ? ($charRow['well_fed_until'] ?? null) : null;
        if ($fb->isWellFed($wfu)) {
            $tsEnd    = is_string($wfu) ? strtotime($wfu) : false;
            $minsLeft = $tsEnd !== false ? max(1, (int) ceil(($tsEnd - time()) / 60)) : 0;
            $message .= "\n🍖 *Сытость активна* — крафт быстрее, добыча щедрее (ещё ~{$minsLeft} мин).\n";
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
