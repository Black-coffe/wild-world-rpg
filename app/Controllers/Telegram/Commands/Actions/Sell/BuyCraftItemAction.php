<?php

namespace App\Controllers\Telegram\Commands\Actions\Sell;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Models\CharacterModel;
use App\Models\CharacterBuildingModel;
use App\Models\BuildingModel;
use App\Models\SalesModel;
use App\Models\CraftedItemsModel;
use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Notifications\MediaSender;

class BuyCraftItemAction extends BaseAction
{
    protected $characterModel;
    protected $characterBuildingModel;
    protected $buildingModel;
    protected $salesModel;
    protected $craftedItemsModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterModel = new CharacterModel();
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->buildingModel = new BuildingModel();
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

        if ($character['gold'] < 1000) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Для торговли необходимо иметь не менее 1000 золотых монет.',
            ]);
        }

        $warehouseBuilding = $this->buildingModel->where('name_en', 'Warehouse')->first();
        if (!$warehouseBuilding) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Ошибка: постройка "Склад" не найдена в базе данных.',
            ]);
        }

        $characterWarehouse = $this->characterBuildingModel
            ->where('character_id', $character['id'])
            ->where('building_id', $warehouseBuilding['id'])
            ->first();

        if (!$characterWarehouse) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Для покупки крафтовых предметов, необходимо иметь постройку "Склад".',
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
        $itemQuantity = $saleItem['quantity'];
        $itemPrice = $saleItem['price'];

        // Формирование сообщения и кнопок
        $text = "Ты собираешься купить:\n";
        $text .= "*Предмет:* _{$itemName}_\n";
        $text .= "*В наличии:* {$itemQuantity} штук\n";
        $text .= "*Стоимость одного:* {$itemPrice}$\n";
        $text .= "\n_Укажи желаемое количество на покупку:_\n";

        $keyboardButtons = [
            ['text' => '1 шт', 'callback_data' => 'buyCraftConfirm_1_' . $craftedItemId],
            ['text' => '5 шт', 'callback_data' => 'buyCraftConfirm_5_' . $craftedItemId],
            ['text' => '10 шт', 'callback_data' => 'buyCraftConfirm_10_' . $craftedItemId],
            ['text' => '50 шт', 'callback_data' => 'buyCraftConfirm_50_' . $craftedItemId],
        ];

        $keyboard = array_chunk($keyboardButtons, 4);
        $keyboard[] = [
            ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
            ['text' => '🛍️ Купить крафт', 'callback_data' => 'buyCraft'],
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
