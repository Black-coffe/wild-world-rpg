<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Settlement;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterFactionModel;
use App\Services\Notifications\MediaSender;
use App\Services\Settlement\SettlementShopService;
use App\Services\Settlement\SettlementZoneService;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * ADR-101 Фаза 3 (рефайн) — экран «🛍 Лавка фракции» оплота (unique-stock).
 *
 * Callback `settleShop` (exact-роут): доступен, когда игрок стоит в оплоте (type=faction).
 * Показывает курируемый ассортимент существующих предметов с ценой под фракцию игрока (свой —
 * скидка, чужой — наценка). Тап по позиции → `settleShopBuy_id={shopItemId}` (подтв.+покупка в
 * {@see SettlementShopBuyAction}). Текст самодостаточен (media-off): что, уровень, редкость, цена.
 */
final class SettlementShopAction extends BaseAction
{
    public const RARITY_ICONS = [
        'Common' => '⚪', 'Uncommon' => '🟢', 'Rare' => '🔵', 'Epic' => '🟣', 'Legendary' => '🟠',
    ];

    private const FACTION_LABELS = [
        1 => 'Милитари', 2 => 'Партизаны', 3 => 'Инженеры', 4 => 'Фермеры',
    ];

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

        $cell = is_numeric($character['cell_number'] ?? null) ? (int) $character['cell_number'] : 0;
        $x    = is_numeric($character['coordinate_x'] ?? null) ? (int) $character['coordinate_x'] : null;
        $y    = is_numeric($character['coordinate_y'] ?? null) ? (int) $character['coordinate_y'] : null;

        $policy = (new SettlementZoneService())->policyAt($cell, $x, $y);
        if ($policy === null) {
            return $this->alert('Здесь нет поселения.');
        }
        $settlement = $policy['settlement'];
        $type       = is_string($settlement['type'] ?? null) ? $settlement['type'] : '';
        if ($type !== 'faction') {
            return $this->alert('Фракционная лавка есть только в оплотах фракций.');
        }
        $sid       = is_numeric($settlement['id'] ?? null) ? (int) $settlement['id'] : 0;
        $facId     = is_numeric($settlement['faction_id'] ?? null) ? (int) $settlement['faction_id'] : 0;
        $charId    = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $playerFac = (new CharacterFactionModel())->getFactionId($charId);

        $items = $service->listForSettlement($sid, $facId, $playerFac);

        $facName = self::FACTION_LABELS[$facId] ?? '—';
        $isMember = $playerFac > 0 && $playerFac === $facId;
        $affinityLine = $isMember
            ? "🤝 Ты свой здесь — цены со *скидкой {$service->memberDiscountPct()}%*."
            : "⚠️ Ты чужак — цены с *наценкой {$service->otherMarkupPct()}%* (свои платят меньше).";

        $text = "🛍 *Лавка фракции «{$facName}»*\n\n";

        $rows = [];
        if ($items === []) {
            $text .= "Прилавок пуст. Возвращайся позже.";
        } else {
            $text .= "{$affinityLine}\n\nТовары на продажу:\n";
            foreach ($items as $it) {
                $rIcon = self::RARITY_ICONS[$it['rarity']] ?? '▫️';
                $lvl   = $it['level'] > 0 ? " · ур.{$it['level']}" : '';
                $text .= "\n{$rIcon} *{$it['name']}*{$lvl} — *" . number_format($it['price']) . "* 💰";
                $rows[] = [[
                    'text'          => "🛒 {$it['name']} — " . number_format($it['price']) . '💰',
                    'callback_data' => "settleShopBuy_id={$it['shop_item_id']}",
                ]];
            }
            $text .= "\n\n_Купленное оружие/броня попадёт в инвентарь — экипируй на экране снаряжения._";
        }

        $rows[] = [['text' => '⬅️ Назад', 'callback_data' => 'settleHub']];

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
