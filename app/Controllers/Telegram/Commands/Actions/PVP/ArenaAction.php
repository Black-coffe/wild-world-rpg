<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\PVP;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Notifications\MediaSender;
use App\Services\PVE\ArenaScreenService;
use App\Services\Telegram\ButtonPacker;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * E25 (ADR-124) — «🏟 Арена» дуэлей: точка входа, делающая opt-in дуэли (ADR-071) ДОСТИЖИМЫМИ.
 *
 * Audit-первопричина: дуэли активны на проде с 2026-05-30, но 0 использования — вызвать можно
 * было ТОЛЬКО случайно зайдя в клетку opt-in игрока при походе (на 1M клеток + низкий онлайн =
 * никогда). Арена — ростер всех `duels_open=1` бойцов: выбери и вызови откуда угодно (спорт без
 * ставок → локация не важна). Вызов идёт `arenaDuel_<id>` → DuelAction (тот же equalized движок,
 * без adjacency-гейта). Вход: хаб «⚙️ Ещё», хаб поселения + экран «🏆 Рейтинг PvP».
 *
 * w2-n7-combat-02 (ADR-190): данные — модель `ArenaScreenService::arena()`, этот handler только рисует
 * её в Telegram (веб рисует ту же модель нативно). Вход в журнал «📜 Мои бои» — рядом с рейтингом.
 *
 * Killswitch `pvp.duel.enabled` — при OFF alert. Caption самодостаточен.
 */
final class ArenaAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (! $user || ! $character) {
            return $this->alert('Персонаж не найден.');
        }

        $arena = (new ArenaScreenService())->arena($character);
        if (! $arena['enabled']) {
            return $this->alert('Арена сейчас закрыта.');
        }

        $text = "🏟 *Арена — равные дуэли*\n\n"
            . "_Спортивный поединок на равных статах: ни здоровья, ни опыта не теряется. "
            . "Решают билд и удача. Победы идут в 🏆 Рейтинг PvP._\n\n";

        $rows        = [];
        $duelButtons = [];
        if ($arena['roster'] === []) {
            $text .= "Пока *никто не открыт* для дуэлей.\n";
        } else {
            $text .= "⚔️ *Открытые бойцы:*\n";
            foreach ($arena['roster'] as $r) {
                $ptsTag = $r['pts'] > 0 ? " · {$r['pts']} очк." : '';
                $text  .= "• {$r['name']} (ур.{$r['level']}{$ptsTag})\n";
                $duelButtons[] = ['text' => "⚔️ Вызвать: {$r['name']}", 'callback_data' => 'arenaDuel_' . $r['id']];
            }
            // Соперников пакуем по 2-3 в ряд: колонкой ростер был бы простынёй.
            foreach (ButtonPacker::pack($duelButtons) as $packedRow) {
                $rows[] = $packedRow;
            }
        }

        // Discoverability opt-in: подсказать открыться, чтобы и тебя могли вызвать.
        $text .= "\n";
        if ($arena['self_open']) {
            $text .= "✅ Ты *открыт* для дуэлей — тебя могут вызвать. Закрыться можно в ⚙️ Настройках.";
        } else {
            $text .= "🔒 Ты *закрыт* для дуэлей. Откройся в ⚙️ Настройках — тогда и тебя смогут вызвать на арену.";
            $rows[] = [['text' => '⚔️ Открыться к дуэлям', 'callback_data' => 'duelsOpenOn']];
        }

        $rows[] = [
            ['text' => '🏆 Рейтинг PvP', 'callback_data' => 'pvpLadder'],
            ['text' => '📜 Мои бои', 'callback_data' => 'battles'],
            ['text' => '◀️ Я', 'callback_data' => 'character'],
        ];

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return MediaSender::editTextOrSend($this->navTarget() + [
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $rows]) ?: '{}',
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
