<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Settlement;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterFactionModel;
use App\Services\Notifications\MediaSender;
use App\Services\Settlement\SettlementShopService;
use App\Services\Settlement\SettlementZoneService;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * ADR-101 Фаза 3 (рефайн) — подтверждение + покупка предмета в лавке оплота.
 *
 * Exact-роут `settleShopBuy`; хвост `_id={id}` (предпросмотр) или `_id={id}_go` (купить)
 * разбирается regex'ом — НЕ prefix (урок мёртвых `npcAct_`). Покупка тратит золото →
 * ОБЯЗАТЕЛЬНЫЙ confirm-шаг (паттерн ADR-096). Сделку (списание+выдача) делает ТОЛЬКО `_go`-ветка
 * через {@see SettlementShopService::purchase}. Стоять нужно в том же оплоте (валидируется по клетке).
 */
final class SettlementShopBuyAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (! $user || ! $character) {
            return $this->alert('Персонаж не найден.');
        }

        $service = new SettlementShopService();
        if (! $service->enabled()) {
            return $this->alert('Лавка сейчас закрыта.');
        }

        $data = (string) $this->callbackQuery->getData();
        if (! preg_match('/^settleShopBuy_id=(\d+)(_go)?$/', $data, $m)) {
            return $this->alert('Некорректная покупка.');
        }
        $shopItemId = (int) $m[1];
        $confirmed  = isset($m[2]);

        // Лавка только в оплоте — определяем поселение по клетке игрока.
        $cell = is_numeric($character['cell_number'] ?? null) ? (int) $character['cell_number'] : 0;
        $x    = is_numeric($character['coordinate_x'] ?? null) ? (int) $character['coordinate_x'] : null;
        $y    = is_numeric($character['coordinate_y'] ?? null) ? (int) $character['coordinate_y'] : null;

        $policy = (new SettlementZoneService())->policyAt($cell, $x, $y);
        if ($policy === null || (is_string($policy['settlement']['type'] ?? null) ? $policy['settlement']['type'] : '') !== 'faction') {
            return $this->alert('Покупка доступна только в оплоте фракции.');
        }
        $settlement = $policy['settlement'];
        $sid        = is_numeric($settlement['id'] ?? null) ? (int) $settlement['id'] : 0;
        $facId      = is_numeric($settlement['faction_id'] ?? null) ? (int) $settlement['faction_id'] : 0;
        $charId     = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;

        if (! $confirmed) {
            return $this->preview($service, $charId, $shopItemId, $sid, $facId);
        }

        $result = $service->purchase($charId, $shopItemId, $sid, $facId);
        if (! $result['success']) {
            return $this->screen("🛍 {$result['message']}", [
                [['text' => '⬅️ К лавке', 'callback_data' => 'settleShop']],
            ]);
        }

        $name = is_string($result['name'] ?? null) ? $result['name'] : 'Предмет';
        $cost = is_numeric($result['price'] ?? null) ? (int) $result['price'] : 0;
        $left = is_numeric($result['gold_left'] ?? null) ? (int) $result['gold_left'] : 0;

        $text = "✅ *Покупка совершена!*\n\n"
            . "Ты купил: *{$name}*\n"
            . "💰 Списано: *" . number_format($cost) . "* золота.\n"
            . "Осталось: *" . number_format($left) . "* 💰.\n\n"
            . "_Предмет в инвентаре — экипируй его на экране снаряжения._";

        return $this->screen($text, [
            [['text' => '🛍 Ещё в лавку', 'callback_data' => 'settleShop']],
            [['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'], ['text' => '🏚 Поселение', 'callback_data' => 'settleHub']],
        ]);
    }

    /**
     * Шаг подтверждения: имя/редкость/уровень/цена без мутаций.
     */
    private function preview(SettlementShopService $service, int $charId, int $shopItemId, int $sid, int $facId): ServerResponse
    {
        $playerFac = (new CharacterFactionModel())->getFactionId($charId);
        $item      = null;
        foreach ($service->listForSettlement($sid, $facId, $playerFac) as $it) {
            if ($it['shop_item_id'] === $shopItemId) {
                $item = $it;
                break;
            }
        }
        if ($item === null) {
            return $this->screen('🛍 Этого товара здесь нет.', [
                [['text' => '⬅️ К лавке', 'callback_data' => 'settleShop']],
            ]);
        }

        $rIcon = SettlementShopAction::RARITY_ICONS[$item['rarity']] ?? '▫️';
        $lvl   = $item['level'] > 0 ? "Требуется уровень: *{$item['level']}*\n" : '';
        $aff   = $item['is_member']
            ? "🤝 Цена со скидкой своему (−{$service->memberDiscountPct()}%)."
            : "⚠️ Цена с наценкой чужаку (+{$service->otherMarkupPct()}%).";

        $text = "🛍 *Покупка*\n\n"
            . "{$rIcon} *{$item['name']}*\n"
            . $lvl
            . "Цена: *" . number_format($item['price']) . "* 💰\n"
            . "{$aff}\n\n"
            . "Подтвердить покупку?";

        return $this->screen($text, [
            [['text' => '✅ Да, купить', 'callback_data' => "settleShopBuy_id={$shopItemId}_go"]],
            [['text' => '⬅️ Назад', 'callback_data' => 'settleShop']],
        ]);
    }

    /**
     * @param list<list<array<string,string>>> $rows
     */
    private function screen(string $text, array $rows): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return MediaSender::editTextOrSend($this->navTarget() + [
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $rows]),
        ]);
    }

    private function alert(string $msg): ServerResponse
    {
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => $msg,
            'show_alert'        => true,
        ]);

        return Request::emptyResponse();
    }
}
