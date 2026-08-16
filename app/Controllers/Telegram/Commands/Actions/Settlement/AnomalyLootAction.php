<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Settlement;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Notifications\MediaSender;
use App\Services\Settlement\RuinLootService;
use App\Services\Settlement\SettlementZoneService;
use Config\Database;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * E16 Ф2 (ADR-116) — исследование поясной аномалии (повторяемый лут по кулдауну).
 *
 * Callback `anomalyLoot`: по клетке персонажа находит аномалию (type=anomaly, SettlementZoneService),
 * зовёт RuinLootService с префиксом `world.anomalies` (свой killswitch/кулдаун/множитель, общая
 * таблица кулдауна character_ruin_loot — ключ char×settlement). Аномалии — поясные лор-лендмарки
 * Y300-600 («вторая родина»): ниже риска чем глубокий север (нет стражи), модест-лут + капля
 * поясного сырья (Пепел Предтеч / Кристалл Разлома). Caption самодостаточен (media-off).
 */
final class AnomalyLootAction extends BaseAction
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
            return $this->alert('Здесь нет аномалии.');
        }
        $settlement = $policy['settlement'];
        if (($settlement['type'] ?? null) !== 'anomaly') {
            return $this->alert('Здесь нет аномалии.');
        }

        $name   = is_string($settlement['name_ru'] ?? null) ? $settlement['name_ru'] : 'Аномалия';
        $charId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;

        $result = (new RuinLootService(keyPrefix: 'world.anomalies'))->loot($charId, $settlement);

        if ($result['ok'] !== true) {
            $reason = is_string($result['reason'] ?? null) ? $result['reason'] : 'unknown';
            $text   = match ($reason) {
                'cooldown' => "🔬 *{$name}*\n\nАномалия выдохлась — её странное вещество ещё не накопилось. Возвращайся через *"
                    . $this->formatRemaining(is_numeric($result['remaining'] ?? null) ? (int) $result['remaining'] : 0)
                    . '*.',
                'disabled', 'empty' => "🔬 *{$name}*\n\nАномалия затихла. Брать сейчас нечего.",
                default => "🔬 *{$name}*\n\nИсследовать не вышло.",
            };

            return $this->render($text);
        }

        $awarded = is_array($result['awarded'] ?? null) ? $result['awarded'] : [];
        $lines   = $this->awardedLines($awarded);
        $body    = $lines !== ''
            ? "✨ *{$name}*\n\nТы осторожно собираешь то, что породила аномалия:\n{$lines}"
            : "✨ *{$name}*\n\nТы прочёсываешь аномальную зону, но ценного почти не осталось.";

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
