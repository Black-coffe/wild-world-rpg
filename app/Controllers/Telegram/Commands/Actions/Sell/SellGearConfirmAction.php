<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Sell;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Economy\GearSaleService;
use App\Services\Economy\TradePricingService;
use App\Services\Economy\VendorDailyLimitService;
use App\Services\Notifications\MediaSender;
use App\Services\Player\WarehouseSellBonusService;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * ADR-165 — сделка: экипировка уходит торговцу, золото приходит игроку.
 *
 * Зеркалит {@see SellCraftConfirmAction} во всём, что касается денег: цена считается
 * {@see TradePricingService} (карма зажата, инвариант «продажа дешевле покупки»),
 * складской бонус ADR-085 идёт внешним множителем, суточный лимит выкупа ADR-157
 * проверяется ДО списания, всё внутри одной транзакции с `FOR UPDATE` на строке
 * инвентаря и на карме — иначе двойной тап продаёт один предмет дважды.
 *
 * Отличия от крафта — два:
 *  1. В `sales` строка НЕ пишется. `sales` — это витрина «🛍️ Купить крафт», и её
 *     позиции резолвятся по `crafted_items`. Проданная экипировка туда не помещается
 *     и не должна: выкупа обратно у экипировки нет, о чём игроку сказано на карточке.
 *  2. В `transactions` строка пишется с `item_kind` = weapon/outfit и `item_ref_id`
 *     (ADR-165), а не с `crafted_item_id`. Именно эта запись подводит продажу
 *     экипировки под суточный лимит выкупа — мимо журнала канал стал бы обходом.
 *
 * callback_data: `sellGearConfirm_<w|a>_<row_id>_<qty>`.
 */
class SellGearConfirmAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (! $user || ! $character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        $gearSale = new GearSaleService();
        if (! $gearSale->isEnabled()) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Скупка экипировки сейчас закрыта — торговец берёт только крафт и сырьё.',
            ]);
        }

        $parts    = explode('_', (string) $this->callbackQuery->getData());
        $kind     = $gearSale->kindForCode($parts[1] ?? '');
        $rowId    = isset($parts[2]) && is_numeric($parts[2]) ? (int) $parts[2] : 0;
        $quantity = isset($parts[3]) && is_numeric($parts[3]) ? (int) $parts[3] : 0;

        $characterId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;

        if ($kind === null || $rowId <= 0 || $quantity <= 0) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Некорректные данные предмета для продажи.',
            ]);
        }

        // Определение предмета (имя + базовая цена) читаем ДО транзакции — оно неизменно.
        $item = $gearSale->findSellable($characterId, $kind, $rowId);
        if ($item === null) {
            $this->logRejected($characterId, 'SELL_GEAR', 'item_not_sellable', [
                'kind'   => $kind,
                'row_id' => $rowId,
            ]);

            return Request::sendMessage([
                'chat_id'    => $chatId,
                'parse_mode' => 'Markdown',
                'text'       => "Этот предмет продать нельзя.\n\n"
                    . "_Надетое торговец не берёт — сними вещь. Трофеи с меткой 🔒 он не берёт вовсе._",
            ]);
        }

        [$charTable] = $gearSale->tablesFor($kind);

        $db = \Config\Database::connect();
        $db->transStart();

        // Перечитываем строку инвентаря FOR UPDATE вместе со ВСЕМИ условиями продажи:
        // между показом карточки и тапом вещь могли надеть в другом окне.
        $lockQuery = $db->query(
            "SELECT id, quantity FROM {$charTable}
             WHERE id = ? AND character_id = ?
               AND COALESCE(equipped, 0) = 0
               AND COALESCE(is_soulbound, 0) = 0
             FOR UPDATE",
            [$rowId, $characterId]
        );
        $gearRow = $lockQuery instanceof \CodeIgniter\Database\BaseResult ? $lockQuery->getRowArray() : null;

        $haveRaw = $gearRow['quantity'] ?? 0;
        $have    = is_numeric($haveRaw) ? (int) $haveRaw : 0;

        if ($gearRow === null || $have < $quantity) {
            $db->transRollback();
            $this->logRejected($characterId, 'SELL_GEAR', 'insufficient_inventory', [
                'kind'      => $kind,
                'row_id'    => $rowId,
                'requested' => $quantity,
                'have'      => $have,
            ]);

            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Извини, но столько этого предмета у тебя нет!',
            ]);
        }

        // Карма FOR UPDATE — цена зависит от неё, и она же меняется этой сделкой.
        $charQuery = $db->query('SELECT trading_karma FROM characters WHERE id = ? FOR UPDATE', [$characterId]);
        $charRow   = $charQuery instanceof \CodeIgniter\Database\BaseResult ? $charQuery->getRowArray() : null;
        if ($charRow === null) {
            $db->transRollback();

            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Персонаж не найден.',
            ]);
        }

        $karmaRaw      = $charRow['trading_karma'] ?? 0;
        $karma         = is_numeric($karmaRaw) ? (float) $karmaRaw : 0.0;
        $warehouseMult = (new WarehouseSellBonusService())->sellPriceMultiplier($characterId);
        $pricing       = new TradePricingService();

        $unitPrice = $gearSale->unitPrice($item['base_price'], $karma, $warehouseMult, $pricing);

        // Количество зажимается по остатку суточного лимита (пол — 1 шт): зеркально
        // крафту, лимит общий на оба прилавка.
        $limits       = new VendorDailyLimitService();
        $requestedQty = $quantity;
        $quantity     = $limits->allowedQuantity($characterId, $quantity, $unitPrice);
        $totalPrice   = $unitPrice * $quantity;

        if ($quantity <= 0) {
            $db->transRollback();
            $this->logRejected($characterId, 'SELL_GEAR', 'daily_buyback_cap', [
                'requested' => $requestedQty,
                'sold_24h'  => round($limits->soldLast24h($characterId)),
            ]);

            return Request::sendMessage([
                'chat_id'      => $chatId,
                'parse_mode'   => 'Markdown',
                'text'         => $limits->refusalText($characterId, 'Твоя экипировка никуда не делась.'),
                'reply_markup' => json_encode(['inline_keyboard' => [[
                    ['text' => '🛒 Магазин',   'callback_data' => 'shop'],
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                ]]]),
            ]);
        }

        // 1) Журнал сделок — он же источник суточного лимита выкупа.
        $now = date('Y-m-d H:i:s');
        $db->table('transactions')->insert([
            'character_id'     => $characterId,
            'crafted_item_id'  => null,
            'item_kind'        => $kind,
            'item_ref_id'      => $item['item_id'],
            'type'             => 'sell',
            'quantity'         => $quantity,
            'price'            => $totalPrice,
            'transaction_date' => $now,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        // 2) Списание из инвентаря: остаток нулевой — строку удаляем, иначе экран
        //    экипировки показывал бы «x0».
        $newQuantity = $have - $quantity;
        if ($newQuantity > 0) {
            $db->query("UPDATE {$charTable} SET quantity = ?, updated_at = ? WHERE id = ?", [$newQuantity, $now, $rowId]);
        } else {
            $db->query("DELETE FROM {$charTable} WHERE id = ?", [$rowId]);
        }

        // 3) Золото + карма одним апдейтом (карма нормализована — ADR-157).
        $karmaDelta = $totalPrice * 0.0002;
        $newKarma   = $pricing->normalizeKarma($karma + $karmaDelta);
        $db->query(
            'UPDATE characters SET gold = gold + ?, trading_karma = ? WHERE id = ?',
            [$totalPrice, $newKarma, $characterId]
        );

        $db->transComplete();

        if ($db->transStatus() === false) {
            log_message('error', "[SellGearConfirm] транзакция упала для character {$characterId}, {$kind} row {$rowId}");

            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Произошла ошибка при продаже. Попробуйте позже.',
            ]);
        }

        // Продажа экипировки необратима и трогает дорогую вещь — оставляем след в
        // action_log, чтобы «куда делся мой ствол» разбиралось по логу, а не по памяти.
        $this->logActivity(
            $characterId,
            'SELL_GEAR',
            "Продано торговцу: {$item['name']} x{$quantity} за " . round($totalPrice) . ' золота'
        );

        $earned   = (int) round($totalPrice);
        $leftText = $newQuantity > 0 ? "\nОсталось у тебя: *{$newQuantity}* шт." : '';

        $text = "*Сделка закрыта*\n\n"
            . "Ты продал: *{$item['name']}*\n"
            . "В количестве: *{$quantity}* шт.{$leftText}\n"
            . "И заработал: *{$earned}* 💰";

        // Урезанную лимитом сделку называем прямо — иначе «продал 5, ушло 2» читается
        // как пропажа вещей.
        if ($quantity < $requestedQty) {
            $kept  = $requestedQty - $quantity;
            $text .= "\n\n🛒 _Ты предлагал {$requestedQty} шт., но на сегодня у торговца хватило монет "
                . "только на {$quantity}. Оставшиеся {$kept} шт. остались у тебя._";
        }

        $backCb = $kind === GearSaleService::KIND_WEAPON
            ? 'sellCraftList_' . GearSaleService::CATEGORY_WEAPON
            : 'sellCraftList_' . GearSaleService::CATEGORY_ARMOR;

        $buttons = [
            ['text' => '⬅️ К списку',      'callback_data' => $backCb],
            ['text' => '🛒 Магазин',        'callback_data' => 'shop'],
            ['text' => '👨‍🎤 Персонаж',      'callback_data' => 'character'],
            ['text' => '🎒 Инвентарь',      'callback_data' => 'inventory'],
        ];
        $keyboard = array_chunk($buttons, 2);

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return MediaSender::editTextOrSend($this->navTarget() + [
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ]);
    }
}
