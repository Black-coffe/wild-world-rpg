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
        $quantity = (int) $quantity;
        $craftedItemId = (int) $craftedItemId;

        // Информация о предмете (read-only, нужна для формулы цены и UI)
        $craftedItem = $this->craftedItemsModel->find($craftedItemId);
        if (!$craftedItem) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Предмет не найден.',
            ]);
        }
        $itemName = $craftedItem['name_rus'];
        $basePrice = (float) $craftedItem['price'];

        // F0.5 — все мутации внутри транзакции с FOR UPDATE-блокировкой
        // строки лога предмета и кармы персонажа.
        // Без транзакции двойной callback приводил к продаже того же предмета
        // дважды (двойное золото при единственном списании со склада).
        $db = \Config\Database::connect();
        $db->transStart();

        // Перечитываем лог предмета у персонажа FOR UPDATE
        $logQuery = $db->query(
            'SELECT id, quantity FROM crafted_items_log
             WHERE character_id = ? AND crafted_item_id = ? FOR UPDATE',
            [$character['id'], $craftedItemId]
        );
        $craftedItemLog = $logQuery instanceof \CodeIgniter\Database\BaseResult ? $logQuery->getRowArray() : null;
        if (!$craftedItemLog || $craftedItemLog['quantity'] < $quantity) {
            $db->transRollback();
            $this->logRejected($character['id'], 'SELL_CRAFT', 'insufficient_inventory', ['item_id' => $craftedItemId, 'requested' => $quantity, 'have' => $craftedItemLog['quantity'] ?? 0]);
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Извини, но у тебя нет достаточного количества этого предмета для продажи!',
            ]);
        }

        // Перечитываем карму персонажа FOR UPDATE
        $charRefreshQuery = $db->query(
            'SELECT trading_karma FROM characters WHERE id = ? FOR UPDATE',
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

        // ADR-157: цена считается единым сервисом (карма зажата, потолок = базовая
        // цена, инвариант «продажа дешевле покупки» применяется последним).
        // ADR-085: мягкий бонус цены владельцам Склада — как внешний множитель,
        // поверх которого инвариант всё равно срабатывает.
        $warehouseMult = (new \App\Services\Player\WarehouseSellBonusService())->sellPriceMultiplier((int) $character['id']);
        $price = (new \App\Services\Economy\TradePricingService())->sellUnitPrice(
            $basePrice,
            (float) $charRefresh['trading_karma'],
            $warehouseMult
        );
        $totalPrice = $price * $quantity;

        // ADR-157 шаг 2: суточный лимит выкупа. У части рецептов ВСЕ входы покупаются
        // у того же торговца (сырьё продаётся без лимита стока), поэтому цикл
        // «закупил вход → скрафтил → сдал» приносил прибыль без потолка. Правка цен
        // обрушила бы доход честных поваров — вместо этого ограничен дневной оборот
        // одного выжившего.
        $limits = new \App\Services\Economy\VendorDailyLimitService();
        if (! $limits->allows((int) $character['id'], (float) $totalPrice)) {
            $db->transRollback();
            $this->logRejected($character['id'], 'SELL_CRAFT', 'daily_buyback_cap', [
                'requested' => round($totalPrice),
                'sold_24h'  => round($limits->soldLast24h((int) $character['id'])),
            ]);

            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "🛒 Торговец выворачивает пустые карманы: *монеты на сегодня кончились*.\n\n"
                    . "Он скупает у одного выжившего ограниченно — приходи, когда разживётся "
                    . "деньгами. Твой товар никуда не делся.",
                'parse_mode' => 'Markdown',
            ]);
        }

        // 1) Обновление таблицы sales
        $sale = $this->salesModel->where('crafted_item_id', $craftedItemId)->first();
        if ($sale) {
            $this->salesModel->updateSaleQuantity($craftedItemId, $quantity);
        } else {
            $this->salesModel->addSale([
                'crafted_item_id' => $craftedItemId,
                'quantity'        => $quantity,
                'price'           => $price,
            ]);
        }

        // 2) Обновление таблицы transactions
        $this->transactionModel->addTransaction([
            'character_id'    => $character['id'],
            'crafted_item_id' => $craftedItemId,
            'type'            => 'sell',
            'quantity'        => $quantity,
            'price'           => $totalPrice,
            'transaction_date' => date('Y-m-d H:i:s'),
        ]);

        // 3) Списание количества предметов у персонажа
        $newQuantity = $craftedItemLog['quantity'] - $quantity;
        if ($newQuantity > 0) {
            $this->craftedItemsLogModel->update($craftedItemLog['id'], ['quantity' => $newQuantity]);
        } else {
            $this->craftedItemsLogModel->delete($craftedItemLog['id']);
        }

        // 4) Прибавление золота + 5) увеличение кармы — одним апдейтом
        $bonusFactor = 0.0002;
        $karmaDelta = $totalPrice * $bonusFactor;
        // ADR-157: карма пишется уже нормализованной — без этого она копила
        // десятки тысяч (продажа поднимала карму, карма поднимала цену продажи).
        $newKarma = (new \App\Services\Economy\TradePricingService())
            ->normalizeKarma((float) $charRefresh['trading_karma'] + $karmaDelta);
        $db->query(
            'UPDATE characters SET gold = gold + ?, trading_karma = ? WHERE id = ?',
            [$totalPrice, $newKarma, $character['id']]
        );

        $db->transComplete();

        if ($db->transStatus() === false) {
            log_message('error', "[SellCraftConfirm] транзакция упала для character {$character['id']}, item {$craftedItemId}");
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Произошла ошибка при продаже. Попробуйте позже.',
            ]);
        }

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

        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'chat_id' => $chatId,
            'photo' => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ]);
    }

}
