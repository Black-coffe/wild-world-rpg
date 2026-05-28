<?php

declare(strict_types=1);

namespace App\TaskHandlers\PVP;

use App\Attributes\HandlerKey;
use App\Models\CharacterModel;
use App\Services\PVE\PvpLadderService;
use App\TaskHandlers\BaseTaskHandler;

/**
 * W18 (ADR-072) — еженедельная рассылка топа PvP-ладдера + сброс недельного сезона.
 *
 * Tasks.php everyMinute + внутренние guard'ы (зеркало DailyTipBroadcastHandler):
 *   1. killswitch `pvp.ladder.enabled` И `pvp.ladder.broadcast_enabled` — иначе выход
 *      (отдельный broadcast-флаг → ладдер можно включить без weekly-спама 364 игрокам).
 *   2. day-guard — только в день `pvp.ladder.broadcast_day` (1=Пн … 7=Вс, date('N')).
 *   3. hour-guard — только в час `pvp.ladder.broadcast_hour` (серверное время).
 *   4. once/week-guard — cache-маркер `o-W` (ISO год-неделя), ставится ДО рассылки.
 *
 * Берёт топ-N по `week_points`, СНАЧАЛА сбрасывает `week_*` (новый сезон), затем шлёт
 * всем игрокам с telegram-связью. Если за неделю никто не набрал очков — тихо выходит
 * (не спамим пустой таблицей). Send через safeSendMessage (lazy Telegram-init, ловит ошибки).
 */
#[HandlerKey(
    key: 'pvp_ladder_weekly_broadcast',
    displayName: 'Рейтинг PvP — еженедельный топ (ADR-072)',
    description: 'everyMinute + day/hour/once-week guard: раз в неделю шлёт топ PvP-ладдера всем игрокам и сбрасывает недельные очки. Killswitch pvp.ladder.enabled + pvp.ladder.broadcast_enabled, день/час tunable.',
)]
class PvpLadderWeeklyBroadcastHandler extends BaseTaskHandler
{
    private const CACHE_KEY  = 'pvp_ladder_weekly_last_sent';
    private const CACHE_TTL  = 864000; // 10 дней
    private const THROTTLE_USEC = 35000; // ~28 msg/sec < лимит Telegram 30/sec

    private PvpLadderService $ladder;

    public function __construct(?PvpLadderService $ladder = null)
    {
        $this->ladder = $ladder ?? new PvpLadderService();
    }

    /**
     * @param array<int|string, mixed> $task
     */
    public function handle(array $task = []): void
    {
        // 1. killswitch (оба флага)
        if (! $this->ladder->enabled() || ! $this->ladder->broadcastEnabled()) {
            return;
        }
        // 2. day-guard
        if ((int) date('N') !== $this->ladder->broadcastDay()) {
            return;
        }
        // 3. hour-guard
        if ((int) date('G') !== $this->ladder->broadcastHour()) {
            return;
        }
        // 4. once/week-guard
        $cache = \Config\Services::cache();
        $week  = date('o-W');
        $last  = $cache->get(self::CACHE_KEY);
        if (is_string($last) && $last === $week) {
            return;
        }

        $top = $this->ladder->weeklyTop($this->ladder->broadcastTopN());

        // Помечаем неделю ДО рассылки (идемпотентность при ре-запуске/краше).
        $cache->save(self::CACHE_KEY, $week, self::CACHE_TTL);

        if (empty($top)) {
            // Никто не набрал очков за неделю — не спамим. week_* и так нули.
            return;
        }

        $text = $this->renderBroadcast($top);

        // Сброс недельного сезона СРАЗУ (до рассылки) — новый отсчёт.
        $this->ladder->resetWeek();

        $this->broadcast($text);
    }

    /**
     * @param list<array<string,mixed>> $top
     */
    private function renderBroadcast(array $top): string
    {
        $medals = ['🥇', '🥈', '🥉'];
        $lines  = '';
        $i      = 0;
        foreach ($top as $r) {
            $name = is_string($r['name'] ?? null) && $r['name'] !== '' ? $r['name'] : ('№' . (is_numeric($r['character_id'] ?? null) ? (int) $r['character_id'] : '?'));
            $pts  = is_numeric($r['week_points'] ?? null) ? (int) $r['week_points'] : 0;
            $pos  = $medals[$i] ?? (($i + 1) . '.');
            $lines .= "{$pos} *{$name}* — {$pts} очк.\n";
            $i++;
        }

        return "🏆 *Итоги недели — Рейтинг PvP*\n\n"
            . $lines
            . "\n_Новый сезон начался. Открой дуэли в ⚙️ Настройках и сразись за вершину рейтинга!_";
    }

    private function broadcast(string $text): void
    {
        $query = (new CharacterModel())->builder()
            ->select('characters.id AS character_id, telegram_users.telegram_id')
            ->join('telegram_users', 'telegram_users.id = characters.telegram_user_id')
            ->get();
        /** @var list<array<string,mixed>> $rows */
        $rows = $query === false ? [] : $query->getResultArray();

        $sent = 0;
        $seen = [];
        foreach ($rows as $row) {
            $tgRaw = $row['telegram_id'] ?? null;
            if (! is_numeric($tgRaw)) {
                continue;
            }
            $tgId = (int) $tgRaw;
            if ($tgId === 0 || isset($seen[$tgId])) {
                continue;
            }
            $seen[$tgId] = true;

            $this->sendBroadcast($tgId, $text);
            $sent++;
            usleep(self::THROTTLE_USEC);
        }

        log_message('info', "[PvpLadderWeeklyBroadcast] sent {$sent} сообщений");
    }

    /**
     * Seam для тестов: реальная отправка. Переопределяется в db-тесте (без Telegram-init).
     */
    protected function sendBroadcast(int $tgId, string $text): void
    {
        $this->safeSendMessage($tgId, $text, ['parse_mode' => 'Markdown']);
    }
}
