<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Sell;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Economy\GearSaleService;
use App\Services\Notifications\MediaSender;
use App\Services\Player\WarehouseSellBonusService;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * ADR-165 — карточка предмета экипировки на продажу: сколько дадут и за сколько штук.
 *
 * Отдельный action, а не ветка в {@see SellCraftItemAction}: там id в callback — это
 * `crafted_items.id` (определение предмета), а здесь — id СТРОКИ инвентаря
 * (`characters_weapons.id` / `characters_outfits.id`). Числа из разных пространств,
 * и смешивать их в одном ключе — прямой путь к продаже чужой строки.
 *
 * callback_data: `sellGearItem_<w|a>_<row_id>`.
 */
class SellGearItemAction extends BaseAction
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

        $parts = explode('_', (string) $this->callbackQuery->getData());
        $kind  = $gearSale->kindForCode($parts[1] ?? '');
        $rowId = isset($parts[2]) && is_numeric($parts[2]) ? (int) $parts[2] : 0;

        if ($kind === null || $rowId <= 0) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Некорректные данные предмета для продажи.',
            ]);
        }

        $characterId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $item        = $gearSale->findSellable($characterId, $kind, $rowId);

        if ($item === null) {
            // Один и тот же ответ на «нет такой строки», «чужая строка», «надета» и
            // «соулбаунд»: показывать игроку, что именно не сошлось, значит подсказывать,
            // какой параметр подбирать. Причину видно в firehose.
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

        $karmaRaw      = $character['trading_karma'] ?? 0;
        $karma         = is_numeric($karmaRaw) ? (float) $karmaRaw : 0.0;
        $warehouseMult = (new WarehouseSellBonusService())->sellPriceMultiplier($characterId);

        $unit  = (int) round($gearSale->unitPrice($item['base_price'], $karma, $warehouseMult));
        $total = $unit * $item['quantity'];
        $code  = $gearSale->codeForKind($kind);

        $text  = "Ты собираешься продать:\n";
        $text .= "*Предмет:* _{$item['name']}_\n";
        if ($item['rarity'] !== '') {
            $text .= "*Редкость:* {$item['rarity']}\n";
        }
        $text .= "*В наличии:* {$item['quantity']} шт.\n";
        $text .= "*Дадут за одну:* {$unit} 💰\n";
        $text .= "*Дадут за все:* {$total} 💰\n\n";
        $text .= "_Обратно предмет не выкупить: проданная экипировка у торговца не оседает._\n";
        $text .= "\n_Сколько отдаёшь?_\n";

        $quantities = [1, 5, 10];
        $buttons    = [];
        foreach ($quantities as $qty) {
            if ($qty > $item['quantity']) {
                continue;
            }
            $buttons[] = [
                'text'          => "{$qty} шт",
                'callback_data' => "sellGearConfirm_{$code}_{$rowId}_{$qty}",
            ];
        }
        // «Все» отдельной кнопкой: у экипировки стеки редко бывают круглыми, и без неё
        // продажа семи одинаковых курток превращалась бы в семь тапов.
        if ($item['quantity'] > 1 && ! in_array($item['quantity'], $quantities, true)) {
            $buttons[] = [
                'text'          => "Все {$item['quantity']} шт",
                'callback_data' => "sellGearConfirm_{$code}_{$rowId}_{$item['quantity']}",
            ];
        }

        $keyboard   = array_chunk($buttons, count($buttons) === 4 ? 2 : 3);
        $backCb     = $kind === GearSaleService::KIND_WEAPON
            ? 'sellCraftList_' . GearSaleService::CATEGORY_WEAPON
            : 'sellCraftList_' . GearSaleService::CATEGORY_ARMOR;
        $keyboard[] = [
            ['text' => '⬅️ Назад',   'callback_data' => $backCb],
            ['text' => '🛒 Магазин', 'callback_data' => 'shop'],
        ];

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return MediaSender::editTextOrSend($this->navTarget() + [
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ]);
    }
}
