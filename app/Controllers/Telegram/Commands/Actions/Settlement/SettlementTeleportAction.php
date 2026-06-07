<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Settlement;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Notifications\MediaSender;
use App\Services\Settlement\SettlementTeleportService;
use App\Services\Settlement\SettlementZoneService;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * ADR-101 Фаза 5 — экран «🛰 Быстрое перемещение» (список открытых поселений-назначений).
 *
 * Callback `settleTeleport` (exact-роут): доступен, только когда игрок СТОИТ в поселении
 * (settlement-to-settlement, решение владельца). Показывает открытые (anchor исследован)
 * поселения КРОМЕ руин и текущего; тап по пункту → `settleTeleportGo_id={id}` (подтв.+переход
 * в {@see SettlementTeleportGoAction}). Текст самодостаточен (media-off): стоимость, кулдаун.
 */
final class SettlementTeleportAction extends BaseAction
{
    private const TYPE_ICONS = [
        'outpost' => '🏚', 'bandit' => '🔥', 'faction' => '🛡', 'ruins' => '🏛',
    ];

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (! $user || ! $character) {
            return $this->alert('Персонаж не найден.');
        }

        $service = new SettlementTeleportService();
        if (! $service->enabled()) {
            return $this->alert('Быстрое перемещение сейчас недоступно.');
        }

        // Старт только из поселения: определяем текущее поселение по клетке.
        $cell = is_numeric($character['cell_number'] ?? null) ? (int) $character['cell_number'] : 0;
        $x    = is_numeric($character['coordinate_x'] ?? null) ? (int) $character['coordinate_x'] : null;
        $y    = is_numeric($character['coordinate_y'] ?? null) ? (int) $character['coordinate_y'] : null;

        $policy = (new SettlementZoneService())->policyAt($cell, $x, $y);
        if ($policy === null) {
            return $this->alert('Быстрое перемещение доступно только из поселения.');
        }
        $currentId = is_numeric($policy['settlement']['id'] ?? null) ? (int) $policy['settlement']['id'] : 0;

        $charId       = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $destinations = $service->openDestinations($charId, $currentId);
        $cost         = $service->cost();
        $cooldownLeft = $service->cooldownRemainingSeconds($charId);

        $rows = [];
        if ($destinations === []) {
            $text = "🛰 *Быстрое перемещение*\n\n"
                . "Пока некуда перемещаться — ты ещё не открыл других поселений.\n\n"
                . "_Поселение становится точкой перехода, когда ты доберёшься до него пешком и нанесёшь на карту._";
        } else {
            $text = "🛰 *Быстрое перемещение*\n\n"
                . "Переход в открытое поселение. Стоимость: *" . number_format($cost) . "* 💰.\n";
            if ($cooldownLeft > 0) {
                $min = (int) ceil($cooldownLeft / 60);
                $text .= "⏳ Кулдаун: ещё *~{$min} мин*.\n";
            }
            $text .= "\nКуда отправимся?";

            foreach ($destinations as $s) {
                $sid  = is_numeric($s['id'] ?? null) ? (int) $s['id'] : 0;
                $name = is_string($s['name_ru'] ?? null) ? $s['name_ru'] : 'Поселение';
                $type = is_string($s['type'] ?? null) ? $s['type'] : 'outpost';
                $sx   = is_numeric($s['coordinate_x'] ?? null) ? (int) $s['coordinate_x'] : 0;
                $sy   = is_numeric($s['coordinate_y'] ?? null) ? (int) $s['coordinate_y'] : 0;
                $icon = self::TYPE_ICONS[$type] ?? '🏚';
                $rows[] = [[
                    'text'          => "{$icon} {$name} ({$sx},{$sy})",
                    'callback_data' => "settleTeleportGo_id={$sid}",
                ]];
            }
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
