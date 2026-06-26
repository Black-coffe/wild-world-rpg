<?php

declare(strict_types=1);

namespace App\TaskHandlers\Onboarding;

use App\Attributes\HandlerKey;
use App\Models\TelegramUserModel;
use App\Services\GameSettings\GameSettingsService;
use App\Services\Notifications\BroadcastDeliveryClassifier;
use App\TaskHandlers\BaseTaskHandler;
use CodeIgniter\Database\BaseResult;
use Config\Database;
use Longman\TelegramBot\Request;

/**
 * S6 (ROADMAP-RETENTION-10, [[ADR-140]]) — day-2 пинг: проактивный возврат в окне D1→D2.
 *
 * Самая глубокая утечка воронки — обвал D1≈45%→D7≈12.5% — начинается с НЕВОЗВРАТА НА ДЕНЬ 2.
 * Comeback-пинг ([[ComebackNudgeHandler]], ADR-136) бьёт только с 48ч отсутствия → окно D1→D2
 * (16-44ч) остаётся без проактивного исходящего. Инфра «поводов вернуться» (серия входов
 * [[LoginStreakService]] / задания дня [[DailyTaskService]] / [[ReturnDigestService]]) УЖЕ live,
 * но новичок о ней не знает, пока сам не зайдёт. Этот слой — ОДИН тёплый пинг в окне дня-2,
 * называющий КОНКРЕТНЫЕ live-поводы (серия / дейлики). Audit S6 (2026-06-26): «overnight-движок»
 * по сути уже есть (Gather до 720мин, streak/daily/digest live) — genuine gap = салиентность
 * возврата именно в D1→D2.
 *
 * Структура — зеркало [[ComebackNudgeHandler]]: Tasks.php everyMinute + guard'ы:
 *   1. killswitch `returnability.day2.enabled` (default false → DORMANT);
 *   2. hour-guard `returnability.day2.send_hour` (одна волна в день);
 *   3. once/day-claim — durable game_settings-маркер `returnability.day2.last_run`.
 *
 * Анти-спам: **one-shot НАВСЕГДА per char** (маркер `Day2Ping` в action_log) → ровно ОДИН day-2
 * пинг за жизнь персонажа; окно [`min_absent_hours`..`max_absent_hours`] НИЖЕ 48ч-пола comeback'а
 * (не пересекаются → один игрок не получит оба пинга в один час); потолок `max_level`; opt-out
 * `characters.daily_tips_enabled=1`; достижимость `blocked_at IS NULL` + markBlocked на 403;
 * батч-кап `max_per_run` + throttle. 🔴 БЕЗ НАГРАДЫ (пинг = чистый текст; награда стимулировала бы
 * «уходить ради бонуса при возврате»). Поводы НАЗЫВАЮТСЯ честно — только реально включённые
 * (серия/дейлики по их killswitch). MEDIA-OFF (ADR-020): текст самодостаточен; {@see format()} pure.
 */
#[HandlerKey(
    key: 'onboarding_day2',
    displayName: 'Онбординг: day-2 пинг (окно D1→D2, ADR-140)',
    description: 'everyMinute + hour/once-day guard: новичку (level ≤ max), ушедшему в окно [min..max] часов (ниже 48ч-пола comeback) и достижимому, шлёт ОДИН проактивный day-2 пинг с конкретными live-поводами (серия входов / задания дня). One-shot НАВСЕГДА per char (Day2Ping), opt-out daily_tips, markBlocked на 403. Killswitch returnability.day2.enabled (default OFF). Без награды.',
)]
class Day2NudgeHandler extends BaseTaskHandler
{
    private const THROTTLE_USEC = 35000;                       // ~28 msg/sec < лимит Telegram 30/sec
    private const MARKER        = 'Day2Ping';                  // one-shot per char (НАВСЕГДА)
    private const RUN_MARKER    = 'returnability.day2.last_run'; // once/day-claim (durable)

    private GameSettingsService $settings;

    public function __construct(?GameSettingsService $settings = null)
    {
        $this->settings = $settings ?? new GameSettingsService();
    }

    /**
     * @param array<int|string, mixed> $task
     */
    public function handle(array $task = []): void
    {
        if ($this->settings->get('returnability.day2.enabled', false) !== true) {
            return;
        }
        if ((int) date('G') !== $this->intSetting('returnability.day2.send_hour', 18)) {
            return;
        }
        if (! $this->claimToday()) {
            return;
        }

        $minAbsent = $this->intSetting('returnability.day2.min_absent_hours', 16);
        $maxAbsent = $this->intSetting('returnability.day2.max_absent_hours', 44);
        $maxLevel  = $this->intSetting('returnability.day2.max_level', 6);
        $maxRun    = $this->intSetting('returnability.day2.max_per_run', 50);

        // Поводы называем честно — только реально включённые (не обещаем dormant).
        $text = self::format(
            $this->settings->get('returnability.streak.enabled', false) === true,
            $this->settings->get('quests.daily.enabled', false) === true,
        );

        $sent = 0;
        foreach ($this->fetchAbsent($minAbsent, $maxAbsent, $maxLevel, $maxRun) as $row) {
            $charId = is_numeric($row['character_id'] ?? null) ? (int) $row['character_id'] : 0;
            $tgRaw  = $row['telegram_id'] ?? null;
            $tgId   = is_numeric($tgRaw) ? (string) $tgRaw : '';
            if ($charId <= 0 || $tgId === '' || $tgId === '0') {
                continue;
            }

            $this->sendNudge($tgId, $text);
            $this->recordPinged($charId, $tgId);
            $sent++;
            usleep(self::THROTTLE_USEC);
        }

        if ($sent > 0) {
            log_message('info', "[Day2Nudge] послано {$sent} day-2 пингов новичкам окна D1→D2");
        }
    }

    /**
     * Выборка ушедших в окно дня-2 новичков (overridable seam для unit-тестов).
     * Гейты в SQL: новичок (level ≤ max), opt-in (daily_tips_enabled=1), достижим (blocked_at IS NULL);
     * отсутствует в окне [min..max] часов; day-2 пинг ещё не слался (one-shot `Day2Ping`).
     *
     * @return list<array<string,mixed>>
     */
    protected function fetchAbsent(int $minAbsent, int $maxAbsent, int $maxLevel, int $maxPerRun): array
    {
        $minAbsent = max(1, $minAbsent);
        $maxAbsent = max($minAbsent + 1, $maxAbsent);
        $maxLevel  = max(1, $maxLevel);
        $maxPerRun = max(1, min(500, $maxPerRun));
        $marker    = self::MARKER;

        $q = Database::connect()->query(
            "SELECT c.id AS character_id, tu.telegram_id
             FROM characters c
             JOIN telegram_users tu ON tu.id = c.telegram_user_id
             WHERE c.level <= {$maxLevel}
               AND c.daily_tips_enabled = 1
               AND tu.blocked_at IS NULL
               AND tu.last_seen IS NOT NULL
               AND tu.last_seen <= NOW() - INTERVAL {$minAbsent} HOUR
               AND tu.last_seen >= NOW() - INTERVAL {$maxAbsent} HOUR
               AND NOT EXISTS (
                   SELECT 1 FROM action_log a
                   WHERE a.character_id = c.id AND a.action_name = '{$marker}'
               )
             ORDER BY tu.last_seen DESC
             LIMIT {$maxPerRun}"
        );

        if (! $q instanceof BaseResult) {
            return [];
        }

        return array_values($q->getResultArray());
    }

    /**
     * Текст day-2 пинга. Pure → тестируется напрямую. Называет КОНКРЕТНЫЕ live-поводы вернуться
     * (серия входов / задания дня) — только реально включённые (честно, не обещаем dormant).
     * Если ни один не включён — общий fallback. Только сбалансированный статический Markdown (*…*).
     */
    public static function format(bool $streakOn, bool $dailyOn): string
    {
        $reasons = '';
        if ($streakOn) {
            $reasons .= "🔥 *Серия входов* — заходи каждый день, и золото капает всё щедрее.\n";
        }
        if ($dailyOn) {
            $reasons .= "📋 *Задания дня* — короткие цели и быстрое золото за то, что ты и так делаешь.\n";
        }
        if ($reasons === '') {
            $reasons = "🏕 Твой лагерь и снаряжение ждут на месте.\n";
        }

        return "📻 *Это Роби. День второй.*\n\n"
            . "Ты сделал первые шаги — не дай следу остыть. Сегодня тебя ждёт:\n\n"
            . $reasons
            . "\nПустошь помнит тех, кто возвращается. Загляни — продолжим.";
    }

    /**
     * Реальная отправка + обработка достижимости (overridable seam — тесты подменяют).
     * На 403 (blocked) — markBlocked (самоочистка), на успехе — clearBlocked.
     */
    protected function sendNudge(string $tgId, string $text): void
    {
        $this->telegram(); // ленивая инициализация Telegram-моста (BaseTaskHandler) — крон без webhook

        try {
            $response = Request::sendMessage([
                'chat_id'      => $tgId,
                'text'         => $text,
                'parse_mode'   => 'Markdown',
                'reply_markup' => self::keyboard(),
            ]);
            $userModel = new TelegramUserModel();
            if (! $response->isOk()) {
                $desc = $response->getDescription();
                if (BroadcastDeliveryClassifier::isBlocked($desc)) {
                    $userModel->markBlocked($tgId);
                    log_message('warning', "[Day2Nudge] получатель {$tgId} недостижим, blocked_at: {$desc}");
                } else {
                    log_message('warning', "[Day2Nudge] sendMessage not ok ({$tgId}): {$desc}");
                }
            } else {
                $userModel->clearBlocked($tgId); // self-heal: вернулся — снимаем отметку
            }
        } catch (\Throwable $e) {
            log_message('error', "[Day2Nudge] sendMessage exception ({$tgId}): " . $e->getMessage());
        }
    }

    /** Клавиатура CTA (существующие routed-callback'и). */
    private static function keyboard(): string
    {
        return json_encode([
            'inline_keyboard' => [[
                ['text' => '🚀 Активные квесты', 'callback_data' => 'activeQuests'],
                ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    /** One-shot маркер: day-2 пинг этому персонажу отправлен (created_at явно — урок E4). */
    protected function recordPinged(int $charId, string $tgId): void
    {
        Database::connect()->table('action_log')->insert([
            'character_id'  => $charId,
            'chat_id'       => is_numeric($tgId) ? (int) $tgId : 0,
            'action_name'   => self::MARKER,
            'action_status' => 'Completed',
            'description'   => 'ADR-140 returnability day-2 ping',
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Атомарно «забирает» рассылку за сегодня через game_settings-маркер (как ComebackNudge).
     * Self-heal строки маркера; conditional UPDATE WHERE value != today + affectedRows()===1.
     */
    private function claimToday(): bool
    {
        $today = date('Y-m-d');
        $db    = Database::connect();

        $exists = $db->table('game_settings')->where('setting_key', self::RUN_MARKER)->countAllResults();
        if ($exists === 0) {
            try {
                $db->table('game_settings')->insert([
                    'setting_key'  => self::RUN_MARKER,
                    'value_type'   => 'string',
                    'value_string' => '',
                    'category'     => 'system',
                ]);
            } catch (\Throwable) {
                // Гонка на вставке (UNIQUE setting_key) — строку создал параллельный процесс, ок.
            }
        }

        $db->table('game_settings')
            ->where('setting_key', self::RUN_MARKER)
            ->where('value_string !=', $today)
            ->update(['value_string' => $today]);

        return $db->affectedRows() === 1;
    }

    private function intSetting(string $key, int $default): int
    {
        $raw = $this->settings->get($key, $default);

        return is_numeric($raw) ? (int) $raw : $default;
    }
}
