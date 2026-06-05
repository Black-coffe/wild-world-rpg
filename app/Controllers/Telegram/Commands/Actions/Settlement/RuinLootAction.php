<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Settlement;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Notifications\MediaSender;
use App\Services\Settlement\RuinLootService;
use App\Services\Settlement\SettlementZoneService;
use Config\Database;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * ADR-101 Фаза 4 — обыск охраняемых руин (повторяемый лут по кулдауну).
 *
 * Callback `ruinLoot`: по клетке персонажа находит руину (type=ruins, SettlementZoneService),
 * зовёт RuinLootService::loot (killswitch + кулдаун + loot_config). Caption самодостаточен
 * (media-off). Страж — отдельный ambient-слой (AutoPveHandler), лут им НЕ гейтится.
 */
final class RuinLootAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (! $user || ! $character) {
            return $this->alert('Персонаж не найден.');
        }

        $cell = is_numeric($character['cell_number'] ?? null) ? (int) $character['cell_number'] : 0;
        $x    = is_numeric($character['coordinate_x'] ?? null) ? (int) $character['coordinate_x'] : null;
        $y    = is_numeric($character['coordinate_y'] ?? null) ? (int) $character['coordinate_y'] : null;

        $policy = (new SettlementZoneService())->policyAt($cell, $x, $y);
        if (! is_array($policy)) {
            return $this->alert('Здесь нет руин.');
        }
        $settlement = $policy['settlement'];
        if (($settlement['type'] ?? null) !== 'ruins') {
            return $this->alert('Здесь нет руин.');
        }

        $name   = is_string($settlement['name_ru'] ?? null) ? $settlement['name_ru'] : 'Руины';
        $charId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;

        $result = (new RuinLootService())->loot($charId, $settlement);

        if ($result['ok'] !== true) {
            $reason = is_string($result['reason'] ?? null) ? $result['reason'] : 'unknown';
            $text   = match ($reason) {
                'cooldown' => "🕳 *{$name}*\n\nЗдесь ты уже всё обчистил. Развалины успеют накопить что-то ценное не раньше чем через *"
                    . $this->formatRemaining(is_numeric($result['remaining'] ?? null) ? (int) $result['remaining'] : 0)
                    . '*.',
                'disabled', 'empty' => "🕳 *{$name}*\n\nЗдесь всё подчистую разграблено до тебя. Брать нечего.",
                default => "🕳 *{$name}*\n\nОбыскать не вышло.",
            };

            return $this->render($text);
        }

        $awarded = is_array($result['awarded'] ?? null) ? $result['awarded'] : [];
        $lines   = $this->awardedLines($awarded);
        $body    = $lines !== ''
            ? "💀 *{$name}*\n\nТы прочёсываешь развалины и выносишь:\n{$lines}"
            : "💀 *{$name}*\n\nТы прочёсываешь развалины, но ценного почти не осталось.";

        return $this->render($body);
    }

    /**
     * Список добычи с русскими именами ресурсов.
     *
     * @param array<string, int> $awarded
     */
    private function awardedLines(array $awarded): string
    {
        if ($awarded === []) {
            return '';
        }
        $names = array_keys($awarded);
        $map   = [];
        $query = Database::connect()->table('resources')->whereIn('name_en', $names)->get();
        $rows  = $query !== false ? $query->getResultArray() : [];
        foreach ($rows as $r) {
            $en = isset($r['name_en']) && is_string($r['name_en']) ? $r['name_en'] : null;
            $ru = isset($r['name']) && is_string($r['name']) ? $r['name'] : null;
            if ($en !== null) {
                $map[$en] = $ru !== null && $ru !== '' ? $ru : $en;
            }
        }
        $lines = [];
        foreach ($awarded as $en => $amount) {
            $label   = $map[$en] ?? $en;
            $lines[] = "• {$label} ×{$amount}";
        }

        return implode("\n", $lines);
    }

    /** Секунды → «Xч Yм» / «Yм». */
    private function formatRemaining(int $seconds): string
    {
        if ($seconds <= 0) {
            return 'мгновение';
        }
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        if ($h > 0) {
            return $m > 0 ? "{$h}ч {$m}м" : "{$h}ч";
        }

        return $m > 0 ? "{$m}м" : 'меньше минуты';
    }

    private function render(string $text): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return MediaSender::editTextOrSend($this->navTarget() + [
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => [
                [['text' => '⬅️ Назад', 'callback_data' => 'settleHub']],
                [['text' => '🚶 Уйти', 'callback_data' => 'move']],
            ]]),
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
