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
        $itemQuantity = $craftedItemLog['quantity'];
        $itemPrice = $craftedItem['price'];
        $totalPrice = $itemPrice * $itemQuantity;

        // Формирование сообщения и кнопок
        $text = "Ты собираешься продать:\n";
        $text .= "*Предмет:* _{$itemName}_\n";
        $text .= "*В наличии:* {$itemQuantity} штук\n";
        $text .= "*Стоимость одного:* {$itemPrice} 💰\n";
        $text .= "*Стоимость всех:* {$totalPrice} 💰\n";
        $text .= "\n_Укажи желаемое количество на продажу:_\n";

        $keyboardButtons = [
            ['text' => '1 шт', 'callback_data' => 'sellCraftConfirm_1_' . $craftedItemId],
            ['text' => '5 шт', 'callback_data' => 'sellCraftConfirm_5_' . $craftedItemId],
            ['text' => '10 шт', 'callback_data' => 'sellCraftConfirm_10_' . $craftedItemId],
            ['text' => '50 шт', 'callback_data' => 'sellCraftConfirm_50_' . $craftedItemId],
        ];

        $keyboard = array_chunk($keyboardButtons, 4);
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
