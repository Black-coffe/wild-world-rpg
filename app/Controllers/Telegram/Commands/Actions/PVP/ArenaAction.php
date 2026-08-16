<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\PVP;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Controllers\Telegram\Commands\Actions\SettingsAction;
use App\Services\Notifications\MediaSender;
use App\Services\PVE\DuelService;
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
 * без adjacency-гейта). Вход: хаб поселения (safe-зона, тематично) + экран «🏆 Рейтинг PvP».
 *
 * Killswitch `pvp.duel.enabled` (DuelService::enabled) — при OFF alert. Caption самодостаточен.
 */
final class ArenaAction extends BaseAction
{
    private const ROSTER_LIMIT = 12;

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (! $user || ! $character) {
            return $this->alert('Персонаж не найден.');
        }
        if (! (new DuelService())->enabled()) {
            return $this->alert('Арена сейчас закрыта.');
        }

        $selfId   = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $selfOpen = SettingsAction::duelsOpenFlag($character) === 1;

        $roster = $this->roster($selfId);

        $text = "🏟 *Арена — равные дуэли*\n\n"
            . "_Спортивный поединок на равных статах: ни здоровья, ни опыта не теряется. "
            . "Решают билд и удача. Победы идут в 🏆 Рейтинг PvP._\n\n";

        $rows        = [];
        $duelButtons = [];
        if ($roster === []) {
            $text .= "Пока *никто не открыт* для дуэлей.\n";
        } else {
            $text .= "⚔️ *Открытые бойцы:*\n";
            foreach ($roster as $r) {
                $id    = is_numeric($r['id'] ?? null) ? (int) $r['id'] : 0;
                $name  = is_string($r['name'] ?? null) && $r['name'] !== '' ? $r['name'] : ('№' . $id);
                $lvl   = is_numeric($r['level'] ?? null) ? (int) $r['level'] : 1;
                $pts   = is_numeric($r['pts'] ?? null) ? (int) $r['pts'] : 0;
                if ($id <= 0) {
                    continue;
                }
                $ptsTag = $pts > 0 ? " · {$pts} очк." : '';
                $text  .= "• {$name} (ур.{$lvl}{$ptsTag})\n";
                $duelButtons[] = ['text' => "⚔️ Вызвать: {$name}", 'callback_data' => 'arenaDuel_' . $id];
            }
            // Соперников пакуем по 2-3 в ряд: колонкой ростер был бы простынёй.
            foreach (ButtonPacker::pack($duelButtons) as $packedRow) {
                $rows[] = $packedRow;
            }
        }

        // Discoverability opt-in: подсказать открыться, чтобы и тебя могли вызвать.
        $text .= "\n";
        if ($selfOpen) {
            $text .= "✅ Ты *открыт* для дуэлей — тебя могут вызвать. Закрыться можно в ⚙️ Настройках.";
        } else {
            $text .= "🔒 Ты *закрыт* для дуэлей. Откройся в ⚙️ Настройках — тогда и тебя смогут вызвать на арену.";
            $rows[] = [['text' => '⚙️ Открыться к дуэлям', 'callback_data' => 'duelsOpenOn']];
        }

        $rows[] = [
            ['text' => '🏆 Рейтинг', 'callback_data' => 'pvpLadder'],
            ['text' => '◀️ Перс', 'callback_data' => 'character'],
        ];

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return MediaSender::editTextOrSend($this->navTarget() + [
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $rows]) ?: '{}',
        ]);
    }

    /**
     * Ростер бойцов, открытых к дуэлям (кроме себя), по убыванию очков ладдера затем уровня.
     *
     * @return array<int, array<string,mixed>>
     */
    private function roster(int $selfId): array
    {
        $q = \Config\Database::connect()->table('characters c')
            ->select('c.id, c.name, c.level, COALESCE(l.points, 0) AS pts')
            ->join('pvp_ladder l', 'l.character_id = c.id', 'left')
            ->where('c.duels_open', 1)
            ->where('c.id !=', $selfId)
            ->orderBy('pts', 'DESC')
            ->orderBy('c.level', 'DESC')
            ->limit(self::ROSTER_LIMIT)
            ->get();

        return $q === false ? [] : $q->getResultArray();
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
