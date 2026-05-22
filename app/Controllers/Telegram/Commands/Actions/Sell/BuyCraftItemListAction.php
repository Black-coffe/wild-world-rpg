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

class BuyCraftItemListAction extends BaseAction
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

        // Извлечение типа предмета из колбека
        $callbackData = $this->callbackQuery->getData();
        $type = str_replace('buyCraftList_', '', $callbackData);

        // Получение всех предметов данного типа у торговца
        $salesItems = $this->salesModel->findAll();
        $craftedItemsList = [];
        foreach ($salesItems as $item) {
            $craftedItem = $this->craftedItemsModel->find($item['crafted_item_id']);
            if ($craftedItem && $craftedItem['type'] === $type) {
                $craftedItemsList[] = [
                    'id' => $craftedItem['id'],
                    'name' => $craftedItem['name_rus'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price']
                ];
            }
        }

        if (empty($craftedItemsList)) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'В данный момент у торговца нет предметов данного типа для продажи.',
            ]);
        }

        // Формирование сообщения и кнопок
        $typeRus = $this->translateType($type);
        $text = "*Ты собираешься купить:*\n";
        $text .= "Направление: {$typeRus}\n";

        $keyboardButtons = [];
        foreach ($craftedItemsList as $index => $item) {
            $text .= "- *№" . ($index + 1) . "* / _" . $item['name'] . "_ / *" . $item['quantity'] . "* в наличии / *" . $item['price'] . " 💰* за шт.\n";
            $keyboardButtons[] = [
                'text' => (string)($index + 1),
                'callback_data' => 'buyCraftItem_' . $item['id']
            ];
        }

        $keyboardButtons[] = ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'];
        $keyboardButtons[] = ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'];
        $keyboardButtons[] = ['text' => '🛍️ Купить крафт', 'callback_data' => 'buyCraft'];

        $keyboard = array_chunk($keyboardButtons, 3);

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // #12 edit-in-place (ADR-018): список крафта категории у торговца — навигация →
        // редактируем сообщение, на котором нажата кнопка (fallback на новое при ошибке).
        return MediaSender::editTextOrSend($this->navTarget() + [
            'text' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ]);
    }

    private function translateType($type)
    {
        $translations = [
            'workbench' => '🔬 Верстаки',
            'component' => '📐 Компоненты',
            'transport' => '🛴 Транспорт',
            'tool' => '🛠️ Инструменты',
            'drug' => '💊 Лекарства',
        ];

        return $translations[$type] ?? $type;
    }
}
