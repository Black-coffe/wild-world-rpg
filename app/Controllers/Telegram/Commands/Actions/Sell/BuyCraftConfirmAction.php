<?php

namespace App\Controllers\Telegram\Commands\Actions\Sell;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Models\CharacterModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\SalesModel;
use App\Models\TransactionModel;
use App\Models\CharacterBuildingModel;
use App\Models\BuildingModel;
use App\Controllers\Telegram\Commands\Actions\BaseAction;

class BuyCraftConfirmAction extends BaseAction
{
    protected $characterModel;
    protected $craftedItemsLogModel;
    protected $craftedItemsModel;
    protected $salesModel;
    protected $transactionModel;
    protected $characterBuildingModel;
    protected $buildingModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterModel = new CharacterModel();
        $this->craftedItemsLogModel = new CraftedItemsLogModel();
        $this->craftedItemsModel = new CraftedItemsModel();
        $this->salesModel = new SalesModel();
        $this->transactionModel = new TransactionModel();
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->buildingModel = new BuildingModel();
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

        // Извлечение количества и ID предмета из колбека
        $callbackData = $this->callbackQuery->getData();
        list($action, $quantity, $craftedItemId) = explode('_', $callbackData);

        // Проверка наличия предмета у торговца
        $saleItem = $this->salesModel->where('crafted_item_id', $craftedItemId)->first();
        if (!$saleItem || $saleItem['quantity'] < $quantity) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Извините, у торговца недостаточно этого предмета.',
            ]);
        }

        // Проверка наличия предмета у персонажа
        $craftedItemLog = $this->craftedItemsLogModel
            ->where('character_id', $character['id'])
            ->where('crafted_item_id', $craftedItemId)
            ->first();

        // Получение информации о предмете
        $craftedItem = $this->craftedItemsModel->find($craftedItemId);
        $itemName = $craftedItem['name_rus'];
        $basePrice = $saleItem['price'];
        $totalPrice = $basePrice * $quantity;

        // Расчет цены с учетом кармы торговли (с ограничением)
        $price = $basePrice * (1 + (100 - $character['trading_karma']) / 50);
        $price = max($basePrice * 0.33, min($price, $basePrice * 10.5)); // Ограничение цены
        $totalPrice = $price * $quantity;

        // Проверка достаточности золота
        if ($character['gold'] < $totalPrice) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'У вас недостаточно золота для этой покупки.',
            ]);
        }

        // Обновление таблицы sales
        $newSaleQuantity = $saleItem['quantity'] - $quantity;
        if ($newSaleQuantity > 0) {
            $this->salesModel->update($saleItem['id'], ['quantity' => $newSaleQuantity]);
        } else {
            $this->salesModel->delete($saleItem['id']);
        }

        // Обновление таблицы transactions
        $this->transactionModel->addTransaction([
            'character_id' => $character['id'],
            'crafted_item_id' => $craftedItemId,
            'type' => 'buy',
            'quantity' => $quantity,
            'price' => $totalPrice,
            'transaction_date' => date('Y-m-d H:i:s'),
        ]);

        // Обновление таблицы crafted_items_log
        if ($craftedItemLog) {
            $newQuantity = $craftedItemLog['quantity'] + $quantity;
            $this->craftedItemsLogModel->update($craftedItemLog['id'], ['quantity' => $newQuantity]);
        } else {
            $this->craftedItemsLogModel->insert([
                'character_id' => $character['id'],
                'task_id' => 1,
                'crafted_item_id' => $craftedItemId,
                'type' => $craftedItem['type'],
                'direction_craft' => $craftedItem['direction_craft'],
                'crafting_location' => $craftedItem['crafting_location'],
                'durability_count' => $craftedItem['durability_count'],
                'durability_time' => null,
                'quantity' => $quantity,
            ]);
        }

        // Списание золота у персонажа
        $this->characterModel->where('id', $character['id'])
            ->set('gold', 'gold - ' . $totalPrice, false)
            ->update();

        // Уменьшение кармы торговли
        $penaltyFactor = 0.0002;
        $this->updateTradingKarma($character['id'], - ($totalPrice * $penaltyFactor));

        // Отправка сообщения игроку
        $text = "*Поздравляю с покупкой!*\n\nТы купил: *{$itemName}*\nВ количестве: *{$quantity}* штук\nИ потратил денег: *{$totalPrice}$*";
        $keyboardButtons = [];

        $keyboardButtons[] = ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'];
        $keyboardButtons[] = ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'];
        $keyboardButtons[] = ['text' => '🛍️ Купить крафт', 'callback_data' => 'buyCraft'];

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

    private function updateTradingKarma($characterId, $value)
    {
        $character = $this->characterModel->find($characterId);
        if ($character) {
            $newKarma = $character['trading_karma'] + $value;
            $this->characterModel->update($characterId, ['trading_karma' => $newKarma]);
        }
    }

}
