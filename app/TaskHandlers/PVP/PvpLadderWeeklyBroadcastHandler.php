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
 *   4. once/week-guard — АТОМАРНЫЙ DB-claim строки game_settings `pvp.ladder.weekly_last_broadcast`
 *      (value_string = ISO год-неделя `o-W`). Conditional UPDATE `WHERE value_string != week`
 *      + affectedRows()===1 → выигравший процесс владеет рассылкой за неделю; остальные тики
 *      (и повторы после cache:clear/деплоя в час рассылки) видят value=week → 0 affected → выход.
 *      DB-маркер durable (переживает cache:clear), в отличие от прежнего cache-only (урок tips
 *      ADR-038/W23: деплой в час рассылки стирал cache → дубли). Claim ставится ДО рассылки.
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
    private const MARKER_KEY = 'pvp.ladder.weekly_last_broadcast'; // DB once/week-claim (durable, переживает cache:clear)
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
        // 4. once/week-guard — атомарный DB-claim (durable, переживает cache:clear/деплой).
        if (! $this->claimWeek()) {
            return;
        }

        $top = $this->ladder->weeklyTop($this->ladder->broadcastTopN());

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
     * Атомарно «забирает» рассылку за текущую ISO-неделю через game_settings-маркер.
     *
     * Self-heal: если строки маркера нет (свежая БД/тест) — создаёт её (value_string='' → != week
     * → claim сработает). Conditional UPDATE `WHERE value_string != <год-неделя>` + affectedRows()===1
     * → этот процесс выиграл claim. Иначе (уже week / гонка проиграна) → false. InnoDB row-lock
     * делает claim race-safe при пересекающихся everyMinute-тиках. DB-маркер durable — переживает
     * cache:clear/деплой (урок tips ADR-038/W23, [[feedback_once_per_day_guard_db_not_cache]]).
     */
    private function claimWeek(): bool
    {
        $week = date('o-W');
        $db   = \Config\Database::connect();

        // Self-heal: гарантируем наличие строки маркера (value_string='' → != week → claim сработает).
        $exists = $db->table('game_settings')->where('setting_key', self::MARKER_KEY)->countAllResults();
        if ($exists === 0) {
            try {
                $db->table('game_settings')->insert([
                    'setting_key'  => self::MARKER_KEY,
                    'value_type'   => 'string',
                    'value_string' => '',
                    'category'     => 'system',
                ]);
            } catch (\Throwable) {
                // Гонка на вставке (UNIQUE setting_key) — строку создал параллельный процесс, ок.
            }
        }

        $db->table('game_settings')
            ->where('setting_key', self::MARKER_KEY)
            ->where('value_string !=', $week)
            ->update(['value_string' => $week]);

        return $db->affectedRows() === 1;
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
