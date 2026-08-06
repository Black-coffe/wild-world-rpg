<?php

namespace App\Controllers\Telegram\Commands\Actions\Sell;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Models\CharacterModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Notifications\MediaSender;

class SellCraftItemAction extends BaseAction
{
    protected $characterModel;
    protected $craftedItemsLogModel;
    protected $craftedItemsModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterModel = new CharacterModel();
        $this->craftedItemsLogModel = new CraftedItemsLogModel();
        $this->craftedItemsModel = new CraftedItemsModel();
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        // Извлечение ID предмета из колбека
        $callbackData = $this->callbackQuery->getData();
        $craftedItemId = str_replace('sellCraftItem_', '', $callbackData);

        // Проверка наличия предмета у персонажа
        $craftedItemLog = $this->craftedItemsLogModel
            ->where('character_id', $character['id'])
            ->where('crafted_item_id', $craftedItemId)
            ->first();

        if (!$craftedItemLog) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Извини, но данного предмета в твоем инвентаре нет!',
            ]);
        }

        // Получение информации о предмете
        $craftedItem = $this->craftedItemsModel->find($craftedItemId);
        $itemName = $craftedItem['name_rus'];
        $rawQuantity  = $craftedItemLog['quantity'] ?? 0;
        $itemQuantity = is_numeric($rawQuantity) ? (int) $rawQuantity : 0;

        // Цена — реальная выплата торговца (карма + складской бонус + инвариант спреда),
        // а не сырое `crafted_items.price`. Единая формула на все экраны лавки —
        // см. CraftTradeService; тот же приём, что у экипировки в ADR-165.
        $craftTrade    = new \App\Services\Economy\CraftTradeService();
        $characterId   = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $karma         = $craftTrade->karmaOf($character);
        $warehouseMult = $craftTrade->warehouseMultiplier($characterId);
        $rawPrice      = $craftedItem['price'] ?? 0;
        $basePrice     = is_numeric($rawPrice) ? (float) $rawPrice : 0.0;

        $itemPrice  = $craftTrade->displaySellPrice($basePrice, $karma, $warehouseMult);
        $totalPrice = $itemPrice * $itemQuantity;

        // Формирование сообщения и кнопок
        $text = "Ты собираешься продать:\n";
        $text .= "*Предмет:* _{$itemName}_\n";
        $text .= "*В наличии:* {$itemQuantity} штук\n";
        $text .= "*Дадут за одну:* {$itemPrice} 💰\n";
        $text .= "*Дадут за все:* {$totalPrice} 💰\n";
        $text .= "\n" . $craftTrade->sellPriceHint($warehouseMult) . "\n";

        // ADR-157 шаг 2: суточный счёт торговца — только тем, кто за сутки уже продавал.
        // Иначе игрок узнаёт о существовании лимита в момент отказа, а не заранее.
        $budgetHint = (new \App\Services\Economy\VendorDailyLimitService())->budgetHint($characterId);
        if ($budgetHint !== '') {
            $text .= "\n" . $budgetHint . "\n";
        }

        $text .= "\n_Укажи желаемое количество на продажу:_\n";

        // Кнопки только на то количество, которое реально есть: раньше «50 шт» висела
        // при двух предметах в сумке и приводила к отказу «нет достаточного количества».
        // Экипировка так не делает (SellGearItemAction) — выравниваем.
        $keyboardButtons = [];
        foreach ([1, 5, 10, 50] as $qty) {
            if ($qty > $itemQuantity) {
                continue;
            }
            $keyboardButtons[] = [
                'text'          => "{$qty} шт",
                'callback_data' => 'sellCraftConfirm_' . $qty . '_' . $craftedItemId,
            ];
        }
        // «Все N» — чтобы некруглый остаток не пришлось добивать по одной штуке.
        if ($itemQuantity > 1 && ! in_array($itemQuantity, [1, 5, 10, 50], true)) {
            $keyboardButtons[] = [
                'text'          => "Все {$itemQuantity} шт",
                'callback_data' => 'sellCraftConfirm_' . $itemQuantity . '_' . $craftedItemId,
            ];
        }

        $keyboard = $keyboardButtons === [] ? [] : array_chunk($keyboardButtons, 3);
        // Arseny report 2026-05-26: «Нужна кнопка назад» — шаг назад на список той же категории
        // (а не на старт sellCraft через 2 шага). type берём из crafted_item.
        $rawType    = $craftedItem['type'] ?? '';
        $type       = is_string($rawType) ? $rawType : '';
        $backCb     = $type !== '' ? "sellCraftList_{$type}" : 'sellCraft';
        $keyboard[] = [
            ['text' => '⬅️ Назад',   'callback_data' => $backCb],
            ['text' => '🛒 Магазин', 'callback_data' => 'shop'],
        ];

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // #12 edit-in-place (ADR-018): экран выбора количества крафта на продажу — навигация →
        // редактируем сообщение, на котором нажата кнопка (fallback на новое при ошибке).
        return MediaSender::editTextOrSend($this->navTarget() + [
            'text' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ]);
    }
}
