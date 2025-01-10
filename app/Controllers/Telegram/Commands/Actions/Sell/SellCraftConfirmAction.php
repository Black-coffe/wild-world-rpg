<?php

namespace App\Controllers\Telegram\Commands\Actions\Sell;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Models\CharacterModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\SalesModel;
use App\Models\TransactionModel;
use App\Controllers\Telegram\Commands\Actions\BaseAction;

class SellCraftConfirmAction extends BaseAction
{
    protected $characterModel;
    protected $craftedItemsLogModel;
    protected $craftedItemsModel;
    protected $salesModel;
    protected $transactionModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterModel = new CharacterModel();
        $this->craftedItemsLogModel = new CraftedItemsLogModel();
        $this->craftedItemsModel = new CraftedItemsModel();
        $this->salesModel = new SalesModel();
        $this->transactionModel = new TransactionModel();
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

        // Извлечение количества и ID предмета из колбека
        $callbackData = $this->callbackQuery->getData();
        list($action, $quantity, $craftedItemId) = explode('_', $callbackData);

        // Проверка наличия предмета у персонажа
        $craftedItemLog = $this->craftedItemsLogModel
            ->where('character_id', $character['id'])
            ->where('crafted_item_id', $craftedItemId)
            ->first();

        if (!$craftedItemLog || $craftedItemLog['quantity'] < $quantity) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Извини, но у тебя нет достаточного количества этого предмета для продажи!',
            ]);
        }

        // Получение информации о предмете
        $craftedItem = $this->craftedItemsModel->find($craftedItemId);
        $itemName = $craftedItem['name_rus'];
        $basePrice = $craftedItem['price'];

        // Расчет цены с учетом кармы торговли (с ограничением)
        $price = $basePrice * (1 + ($character['trading_karma'] - 100) / 200);
        $price = max($basePrice * 0.33, min($price, $basePrice * 10.5)); // Ограничение цены
        $totalPrice = $price * $quantity;

        // Обновление таблицы sales
        $sale = $this->salesModel->where('crafted_item_id', $craftedItemId)->first();
        if ($sale) {
            $this->salesModel->updateSaleQuantity($craftedItemId, $quantity);
        } else {
            $this->salesModel->addSale([
                'crafted_item_id' => $craftedItemId,
                'quantity' => $quantity,
                'price' => $price,
            ]);
        }

        // Обновление таблицы transactions
        $this->transactionModel->addTransaction([
            'character_id' => $character['id'],
            'crafted_item_id' => $craftedItemId,
            'type' => 'sell',
            'quantity' => $quantity,
            'price' => $totalPrice,
            'transaction_date' => date('Y-m-d H:i:s'),
        ]);

        // Списание количества предметов у персонажа
        $newQuantity = $craftedItemLog['quantity'] - $quantity;
        if ($newQuantity > 0) {
            $this->craftedItemsLogModel->update($craftedItemLog['id'], ['quantity' => $newQuantity]);
        } else {
            $this->craftedItemsLogModel->delete($craftedItemLog['id']);
        }

        // Увеличение количества золота у персонажа
        $this->characterModel->where('id', $character['id'])
            ->set('gold', 'gold + ' . $totalPrice, false)
            ->update();

        // Увеличение кармы торговли
        $this->updateTradingKarma($character['id'], $quantity * 0.01);

        // Отправка сообщения игроку
        $text = "*Поздравляю с продажей*\n\nТы продал: *{$itemName}*\nВ количестве: *{$quantity}* штук\nИ заработал денег: *{$totalPrice}$*";
        $keyboardButtons = [];

        $keyboardButtons[] = ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'];
        $keyboardButtons[] = ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'];
        $keyboardButtons[] = ['text' => '🛒 Продать крафт', 'callback_data' => 'sellCraft'];
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
