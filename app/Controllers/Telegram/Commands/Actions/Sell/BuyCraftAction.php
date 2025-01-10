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

class BuyCraftAction extends BaseAction
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

        $salesItems = $this->salesModel->findAll();
        if (empty($salesItems)) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'В данный момент у торговца нет доступных крафтовых предметов для продажи.',
            ]);
        }

        // Группировка товаров по типу
        $itemTypes = [];
        foreach ($salesItems as $item) {
            $craftedItem = $this->craftedItemsModel->find($item['crafted_item_id']);
            if ($craftedItem) {
                $type = $craftedItem['type'];
                if (!isset($itemTypes[$type])) {
                    $itemTypes[$type] = 0;
                }
                $itemTypes[$type] += $item['quantity'];
            }
        }

        $text = "Привет мой друг, поторгуем?\n\nЯ здесь прикупил немного барахла, готов продать по дешевке тебе.\n*Вот что у меня есть:*\n";
        $keyboardButtons = [];

        foreach ($itemTypes as $type => $quantity) {
            $typeRus = $this->translateType($type);
            $text .= "- {$typeRus} | {$quantity} всех предметов\n";
            $keyboardButtons[] = [
                'text' => $typeRus,
                'callback_data' => 'buyCraftList_' . $type
            ];
        }

        $keyboardButtons[] = ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'];
        $keyboardButtons[] = ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'];

        $keyboard = array_chunk($keyboardButtons, 2);
        $imagePath = base_url('uploads/telegram/craft/vendor_kiosk_in_the_game_world.jpg'); // Укажите актуальный путь к изображению

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendPhoto([
            'chat_id' => $chatId,
            'photo' => Request::encodeFile($imagePath),
            'caption' => $text,
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
