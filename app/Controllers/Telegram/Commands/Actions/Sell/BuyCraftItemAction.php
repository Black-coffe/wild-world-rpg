<?php

namespace App\Controllers\Telegram\Commands\Actions\Sell;

use App\Services\Telegram\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Models\SalesModel;
use App\Models\CraftedItemsModel;
use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Notifications\MediaSender;

class BuyCraftItemAction extends BaseAction
{
    protected $salesModel;
    protected $craftedItemsModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->salesModel = new SalesModel();
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

        // Гейты входа — единым сервисом (см. CraftShopGate): порог золота здесь был
        // константой 1000 и разъезжался бы с живой настройкой админки.
        $gate = (new \App\Services\Economy\CraftShopGate())->check($character);
        if ($gate !== null) {
            $this->logRejected($character['id'], 'BUY_CRAFT', $gate['reason']);

            return Request::sendMessage([
                'chat_id'      => $chatId,
                'text'         => $gate['text'],
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => [[
                    ['text' => '🛒 Магазин',   'callback_data' => 'shop'],
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                ]]]),
            ]);
        }

        // Извлечение ID предмета из колбека
        $callbackData = $this->callbackQuery->getData();
        $craftedItemId = str_replace('buyCraftItem_', '', $callbackData);

        // Проверка наличия предмета у торговца
        $saleItem = $this->salesModel->where('crafted_item_id', $craftedItemId)->first();
        if (!$saleItem) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Извините, этот предмет сейчас недоступен у торговца.',
            ]);
        }

        // Получение информации о предмете
        $craftedItem = $this->craftedItemsModel->find($craftedItemId);
        $itemName = $craftedItem['name_rus'];
        $rawQuantity  = $saleItem['quantity'] ?? 0;
        $itemQuantity = is_numeric($rawQuantity) ? (int) $rawQuantity : 0;

        // Цена — с наценкой торговца, ровно как спишет BuyCraftConfirmAction
        // (пол множителя покупки 1.25). См. CraftTradeService.
        $craftTrade = new \App\Services\Economy\CraftTradeService();
        $karma      = $craftTrade->karmaOf($character);
        $rawPrice   = $saleItem['price'] ?? 0;
        $basePrice  = is_numeric($rawPrice) ? (float) $rawPrice : 0.0;
        $itemPrice  = $craftTrade->displayBuyPrice($basePrice, $karma);

        // Формирование сообщения и кнопок
        $text = "Ты собираешься купить:\n";
        $text .= "*Предмет:* _{$itemName}_\n";
        $text .= "*В наличии:* {$itemQuantity} штук\n";
        $text .= "*Цена за одну:* {$itemPrice} 💰\n";
        $text .= "\n" . $craftTrade->buyPriceHint() . "\n";
        $text .= "\n_Укажи желаемое количество на покупку:_\n";

        // Только те количества, которые реально есть у торговца: «50 шт» при трёх
        // на прилавке приводила к отказу «недостаточно этого предмета».
        $keyboardButtons = [];
        foreach ([1, 5, 10, 50] as $qty) {
            if ($qty > $itemQuantity) {
                continue;
            }
            $keyboardButtons[] = [
                'text'          => "{$qty} шт",
                'callback_data' => 'buyCraftConfirm_' . $qty . '_' . $craftedItemId,
            ];
        }
        if ($itemQuantity > 1 && ! in_array($itemQuantity, [1, 5, 10, 50], true)) {
            $keyboardButtons[] = [
                'text'          => "Все {$itemQuantity} шт",
                'callback_data' => 'buyCraftConfirm_' . $itemQuantity . '_' . $craftedItemId,
            ];
        }

        $keyboard = $keyboardButtons === [] ? [] : array_chunk($keyboardButtons, 3);
        // Arseny report 2026-05-26: «Нужна кнопка назад» — шаг назад на список этой же категории
        // (а не на старт buyCraft через 2 шага). type берём из crafted_item.
        $rawType    = $craftedItem['type'] ?? '';
        $type       = is_string($rawType) ? $rawType : '';
        $backCb     = $type !== '' ? "buyCraftList_{$type}" : 'buyCraft';
        $keyboard[] = [
            ['text' => '⬅️ Назад',   'callback_data' => $backCb],
            ['text' => '🛒 Магазин', 'callback_data' => 'shop'],
        ];

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // #12 edit-in-place (ADR-018): экран выбора количества крафта для покупки — навигация →
        // редактируем сообщение, на котором нажата кнопка (fallback на новое при ошибке).
        return MediaSender::editTextOrSend($this->navTarget() + [
            'text' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ]);
    }
}
