<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\PVP;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Notifications\MediaSender;
use App\Services\PVE\ArenaScreenService;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * W18 (ADR-072) — экран «🏆 Рейтинг PvP». Callback `pvpLadder` (global) /
 * `pvpLadder_global` / `pvpLadder_faction_<id>` (первый сегмент `pvpLadder` → этот handler).
 *
 * Топ-N по all-time points (дуэли W17 + летальные PvP-атаки), + личная позиция игрока.
 * Табы: 🌍 Глобальный / 🏳️ Моя фракция (если игрок во фракции). edit-in-place (ADR-018).
 * Killswitch pvp.ladder.enabled: при dormant — сообщение-замок с объяснением.
 *
 * w2-n7-combat-02 (ADR-190): данные — модель `ArenaScreenService::ladder()`, этот handler только рисует
 * её в Telegram. Вход в журнал «📜 Мои бои» — рядом с ареной.
 */
final class PvpLadderAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = (int) $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        if (! $user || ! $character) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Персонаж не найден.']);
        }

        // Вкладка из callback_data.
        $factionId = null;
        if (preg_match('/^pvpLadder_faction_(\d+)$/', (string) $this->callbackQuery->getData(), $m) === 1) {
            $factionId = (int) $m[1];
        }

        $characterId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $ladder      = (new ArenaScreenService())->ladder($characterId, $factionId);
        if (! $ladder['enabled']) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => "🏆 *Рейтинг PvP временно недоступен*\n\n_Раздел отключён администрацией._",
                'parse_mode' => 'Markdown',
            ]);
        }

        $header = $factionId !== null
            ? "🏆 *Рейтинг PvP — {$ladder['faction_name']}*\n\n"
            : "🏆 *Рейтинг PvP — 🌍 Глобальный*\n\n";
        $text = $header . self::renderRows($ladder['rows']);

        // Личная позиция игрока (по all-time points).
        $my = $ladder['my'];
        if ($my !== null) {
            $rankStr = $my['rank'] > 0 ? "#{$my['rank']}" : '—';
            $text .= "\n👤 *Ты:* {$rankStr} · {$my['points']} очк. (дуэли: {$my['duel_wins']}, PvP: {$my['pvp_wins']})";
        } else {
            $text .= "\n👤 *Ты* ещё не в рейтинге — выиграй дуэль, чтобы попасть в таблицу.";
        }

        $text .= "\n\n_Очки за победы: дуэль и летальное PvP. Рейтинг — престиж, без игровых наград._";

        // Табы: глобальный + моя фракция (если игрок во фракции).
        $tabs = [];
        if ($factionId !== null) {
            $tabs[] = ['text' => '🌍 Глобальный', 'callback_data' => 'pvpLadder_global'];
        }
        if ($ladder['my_faction'] > 0 && $factionId === null) {
            $tabs[] = ['text' => '🏳️ Моя фракция', 'callback_data' => 'pvpLadder_faction_' . $ladder['my_faction']];
        }
        $rowsKb = [];
        if ($tabs !== []) {
            $rowsKb[] = $tabs;
        }
        // E25 (ADR-124) — вход на «🏟 Арену» прямо из рейтинга (climb-the-ladder discoverability).
        $rowsKb[] = [
            ['text' => '🏟 Арена', 'callback_data' => 'arena'],
            ['text' => '📜 Мои бои', 'callback_data' => 'battles'],
            ['text' => '◀️ Я', 'callback_data' => 'character'],
        ];

        return MediaSender::editTextOrSend($this->navTarget() + [
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $rowsKb]) ?: '{}',
        ]);
    }

    /**
     * @param list<array{name: string, points: int, duel_wins: int, pvp_wins: int}> $rows
     */
    private static function renderRows(array $rows): string
    {
        if ($rows === []) {
            return "_Пока никто не набрал очков. Стань первым!_\n";
        }
        $medals = ['🥇', '🥈', '🥉'];
        $out    = '';
        foreach ($rows as $i => $r) {
            $pos  = $medals[$i] ?? (($i + 1) . '.');
            $out .= "{$pos} *{$r['name']}* — {$r['points']} очк. _(дуэли {$r['duel_wins']}, PvP {$r['pvp_wins']})_\n";
        }

        return $out;
    }
}
