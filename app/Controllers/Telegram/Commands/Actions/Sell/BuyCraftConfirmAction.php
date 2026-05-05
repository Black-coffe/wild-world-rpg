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
            $this->logRejected($character['id'], 'BUY_CRAFT', 'min_gold_threshold', ['gold' => $character['gold']]);
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
            $this->logRejected($character['id'], 'BUY_CRAFT', 'no_warehouse');
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Для покупки крафтовых предметов, необходимо иметь постройку "Склад".',
            ]);
        }

        // Извлечение количества и ID предмета из колбека
        $callbackData = $this->callbackQuery->getData();
        list($action, $quantity, $craftedItemId) = explode('_', $callbackData);
        $quantity = (int) $quantity;
        $craftedItemId = (int) $craftedItemId;

        // Получение информации о предмете (read-only, для UI и каскадных полей)
        $craftedItem = $this->craftedItemsModel->find($craftedItemId);
        if (!$craftedItem) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Предмет не найден.',
            ]);
        }
        $itemName = $craftedItem['name_rus'];

        // F0.5 — все мутации gold / sales / log внутри одной транзакции с
        // повторным чтением балансов под FOR UPDATE. Без этого двойной клик
        // или двойной callback (нестабильная сеть) приводил к двойному
        // списанию: проверка $character['gold'] < $totalPrice проходила в
        // обоих параллельных запросах до того, как первый успевал зафиксить.
        $db = \Config\Database::connect();
        $db->transStart();

        // Перечитываем персонажа FOR UPDATE — блокируем строку до commit'а
        $charRefreshQuery = $db->query(
            'SELECT gold, trading_karma FROM characters WHERE id = ? FOR UPDATE',
            [$character['id']]
        );
        $charRefresh = $charRefreshQuery instanceof \CodeIgniter\Database\BaseResult ? $charRefreshQuery->getRowArray() : null;
        if (!$charRefresh) {
            $db->transRollback();
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Персонаж не найден.',
            ]);
        }

        // Перечитываем предмет торговца FOR UPDATE
        $saleItemQuery = $db->query(
            'SELECT id, price, quantity FROM sales WHERE crafted_item_id = ? FOR UPDATE',
            [$craftedItemId]
        );
        $saleItem = $saleItemQuery instanceof \CodeIgniter\Database\BaseResult ? $saleItemQuery->getRowArray() : null;
        if (!$saleItem || $saleItem['quantity'] < $quantity) {
            $db->transRollback();
            $this->logRejected($character['id'], 'BUY_CRAFT', 'vendor_out_of_stock', ['item_id' => $craftedItemId, 'requested' => $quantity, 'available' => $saleItem['quantity'] ?? 0]);
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Извините, у торговца недостаточно этого предмета.',
            ]);
        }

        // Расчёт цены с учётом кармы торговли (с ограничением 0.33x..10.5x)
        $basePrice = (float) $saleItem['price'];
        $price = $basePrice * (1 + (100 - $charRefresh['trading_karma']) / 50);
        $price = max($basePrice * 0.33, min($price, $basePrice * 10.5));
        $totalPrice = $price * $quantity;

        // Финальная проверка золота — уже после блокировки строки персонажа
        if ($charRefresh['gold'] < $totalPrice) {
            $db->transRollback();
            $this->logRejected($character['id'], 'BUY_CRAFT', 'insufficient_gold', ['need' => $totalPrice, 'have' => $charRefresh['gold']]);
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'У вас недостаточно золота для этой покупки.',
            ]);
        }

        // Перечитываем лог предмета у персонажа (внутри транзакции)
        $craftedItemLog = $this->craftedItemsLogModel
            ->where('character_id', $character['id'])
            ->where('crafted_item_id', $craftedItemId)
            ->first();

        // 1) Обновление таблицы sales
        $newSaleQuantity = $saleItem['quantity'] - $quantity;
        if ($newSaleQuantity > 0) {
            $this->salesModel->update($saleItem['id'], ['quantity' => $newSaleQuantity]);
        } else {
            $this->salesModel->delete($saleItem['id']);
        }

        // 2) Обновление таблицы transactions
        $this->transactionModel->addTransaction([
            'character_id'    => $character['id'],
            'crafted_item_id' => $craftedItemId,
            'type'            => 'buy',
            'quantity'        => $quantity,
            'price'           => $totalPrice,
            'transaction_date' => date('Y-m-d H:i:s'),
        ]);

        // 3) Обновление таблицы crafted_items_log
        if ($craftedItemLog) {
            $newQuantity = $craftedItemLog['quantity'] + $quantity;
            $this->craftedItemsLogModel->update($craftedItemLog['id'], ['quantity' => $newQuantity]);
        } else {
            $this->craftedItemsLogModel->insert([
                'character_id'      => $character['id'],
                'task_id'           => 1,
                'crafted_item_id'   => $craftedItemId,
                'type'              => $craftedItem['type'],
                'direction_craft'   => $craftedItem['direction_craft'],
                'crafting_location' => $craftedItem['crafting_location'],
                'durability_count'  => $craftedItem['durability_count'],
                'durability_time'   => null,
                'quantity'          => $quantity,
            ]);
        }

        // 4) Списание золота + 5) уменьшение кармы — одним апдейтом
        $penaltyFactor = 0.0002;
        $karmaDelta = -($totalPrice * $penaltyFactor);
        $newKarma = $charRefresh['trading_karma'] + $karmaDelta;
        $db->query(
            'UPDATE characters SET gold = gold - ?, trading_karma = ? WHERE id = ?',
            [$totalPrice, $newKarma, $character['id']]
        );

        $db->transComplete();

        if ($db->transStatus() === false) {
            log_message('error', "[BuyCraftConfirm] транзакция упала для character {$character['id']}, item {$craftedItemId}");
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Произошла ошибка при покупке. Попробуйте позже.',
            ]);
        }

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

}
