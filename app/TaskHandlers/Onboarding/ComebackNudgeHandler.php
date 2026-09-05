<?php

declare(strict_types=1);

namespace App\TaskHandlers\Onboarding;

use App\Attributes\HandlerKey;
use App\Models\TelegramUserModel;
use App\Services\GameSettings\GameSettingsService;
use App\Services\Notifications\BroadcastDeliveryClassifier;
use App\Services\Onboarding\OnboardingChainCatalog;
use App\TaskHandlers\BaseTaskHandler;
use CodeIgniter\Database\BaseResult;
use Config\Database;
use App\Services\Telegram\Request;

/**
 * ADR-136 (ROADMAP-100 продолжение воронки) — comeback-пинг УШЕДШИМ новичкам
 * (returnability Слой 3, дополняет E6 [[ReturnDigestService]] и E4 [[OnboardingNudgeHandler]]).
 *
 * Самая глубокая измеренная утечка воронки — ранний отток (срез A+B 2026-06-20: D1≈45% / D7≈12.5%):
 * новичок регистрируется, делает пару шагов и пропадает. Слой-1 just-in-time хинты и Слой-2 E4
 * авто-эскалация СТРУКТУРНО его не достают — оба требуют, чтобы игрок открыл экран / был онлайн.
 * Этот слой — единственный ПРОАКТИВНЫЙ исходящий: один тёплый пинг ушедшему новичку в окне
 * «отсутствует, но ещё не сгинул» с CTA на его незавершённый онбординг-шаг.
 *
 * Структура (зеркало [[DailyTipBroadcastHandler]]): Tasks.php everyMinute + guard'ы:
 *   1. killswitch `returnability.comeback.enabled` (default false → DORMANT);
 *   2. hour-guard — шлём только в час `returnability.comeback.send_hour` (серверное; не 3 ночи);
 *   3. once/day-claim — атомарный game_settings-маркер `returnability.comeback.last_run` (durable,
 *      переживает cache:clear/деплой; conditional UPDATE + affectedRows()===1 → один тик в сутки).
 *
 * Анти-спам (исходящая рассылка → высокая планка):
 *   • **one-shot НАВСЕГДА per char** — маркер `ComebackPing` в action_log (NOT EXISTS) → ровно ОДИН
 *     comeback-пинг за всё время жизни персонажа (не «за эпизод»). Безопасный дефолт против спама;
 *   • окно отсутствия [`min_absent_hours`..`max_absent_hours`] — НЕ свежеактивных (это E4), НЕ
 *     сгинувших год назад (пуш мёртвому = спам, многие заблокировали бота);
 *   • потолок новичка `max_level` (ветеранов не трогаем);
 *   • opt-out `characters.daily_tips_enabled=1` (тот же тумблер «Совета дня»);
 *   • достижимость `telegram_users.blocked_at IS NULL` + **самоочистка**: на 403 (бот заблокирован)
 *     markBlocked → больше не шлём (реюз [[BroadcastDeliveryClassifier]] / [[PveNotificationSender]]);
 *   • батч-кап `max_per_run` + throttle 35мс (остаток подхватится завтра, one-shot не задвоит).
 *
 * 🔴 БЕЗ НАГРАДЫ (анти-абьюз): пинг — чистое сообщение, без выдачи ресурсов/золота. Награда
 * стимулировала бы «уходить ради бонуса при возврате» (как read-only /guide, ADR-127). Возврат
 * мотивируется напоминанием о незавершённом, а не плюшкой.
 *
 * MEDIA-OFF (ADR-020): текст самодостаточен. Текст ({@see format()}) — pure, тестируется напрямую.
 */
#[HandlerKey(
    key: 'onboarding_comeback',
    displayName: 'Онбординг: comeback-пинг ушедшим новичкам (ADR-136)',
    description: 'everyMinute + hour/once-day guard: новичку (level ≤ max), ушедшему в окно [min..max] часов и ещё достижимому, шлёт ОДИН проактивный comeback-пинг с CTA на незавершённый онбординг-шаг. One-shot НАВСЕГДА per char (ComebackPing), opt-out daily_tips, markBlocked на 403. Killswitch returnability.comeback.enabled (default OFF). Без награды.',
)]
class ComebackNudgeHandler extends BaseTaskHandler
{
    private const THROTTLE_USEC = 35000;                          // ~28 msg/sec < лимит Telegram 30/sec
    private const MARKER        = 'ComebackPing';                 // one-shot per char (НАВСЕГДА)
    private const RUN_MARKER    = 'returnability.comeback.last_run'; // once/day-claim (durable)

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
        if ($this->settings->get('returnability.comeback.enabled', false) !== true) {
            return;
        }
        if ((int) date('G') !== $this->intSetting('returnability.comeback.send_hour', 11)) {
            return;
        }
        if (! $this->claimToday()) {
            return;
        }

        $minAbsent = $this->intSetting('returnability.comeback.min_absent_hours', 48);
        $maxAbsent = $this->intSetting('returnability.comeback.max_absent_hours', 168);
        $maxLevel  = $this->intSetting('returnability.comeback.max_level', 6);
        $maxRun    = $this->intSetting('returnability.comeback.max_per_run', 50);

        $sent = 0;
        foreach ($this->fetchAbsent($minAbsent, $maxAbsent, $maxLevel, $maxRun) as $row) {
            $charId = is_numeric($row['character_id'] ?? null) ? (int) $row['character_id'] : 0;
            $tgRaw  = $row['telegram_id'] ?? null;
            $tgId   = is_numeric($tgRaw) ? (string) $tgRaw : '';
            if ($charId <= 0 || $tgId === '' || $tgId === '0') {
                continue;
            }
            $stepEn = is_string($row['step_title_en'] ?? null) && $row['step_title_en'] !== ''
                ? $row['step_title_en']
                : null;

            $this->sendComeback($tgId, self::format($stepEn));
            $this->recordPinged($charId, $tgId);
            $sent++;
            usleep(self::THROTTLE_USEC);
        }

        if ($sent > 0) {
            log_message('info', "[ComebackNudge] послано {$sent} comeback-пингов ушедшим новичкам");
        }
    }

    /**
     * Выборка ушедших новичков в окне (overridable seam для unit-тестов).
     *
     * Гейты в SQL: новичок (level ≤ max), opt-in (daily_tips_enabled=1), достижим (blocked_at IS NULL);
     * отсутствует в окне [min..max] часов (last_seen старше min, но не старше max); пинг ещё не слался
     * (one-shot `ComebackPing`). step_title_en — текущий незавершённый онбординг-шаг (для персонализации).
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
        $prefix    = OnboardingChainCatalog::PREFIX;

        $q = Database::connect()->query(
            "SELECT c.id AS character_id, tu.telegram_id,
                    (SELECT q.title_en FROM quest_steps qs JOIN quests q ON q.id = qs.quest_id
                     WHERE qs.character_id = c.id AND qs.is_completed = 0 AND q.title_en LIKE '{$prefix}%'
                     ORDER BY qs.created_at DESC LIMIT 1) AS step_title_en
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
     * Текст comeback-пинга. Pure → тестируется напрямую. Персонализирован по незавершённому
     * онбординг-шагу (CTA «ты остановился на…»); fallback — общий, если шага нет.
     * Только сбалансированный статический Markdown (*…*); динамику (title_ru/description) НЕ
     * оборачиваем в разметку (урок [[feedback_legacy_markdown_no_backslash_escape]]).
     */
    public static function format(?string $stepTitleEn): string
    {
        $step = $stepTitleEn !== null ? OnboardingChainCatalog::find($stepTitleEn) : null;

        $body = $step !== null
            ? "Ты остановился на шаге «{$step['title_ru']}».\n{$step['description']}\n\n"
            : "Твой лагерь, добыча и снаряжение ждут на месте — продолжи выживание.\n\n";

        return "🌅 *Возвращайся, выживший — пустошь тебя помнит.*\n\n"
            . $body
            . "Один шаг — и ты снова в строю. Жми «🚀 Активные квесты» или «◀️ Я».";
    }

    /**
     * Реальная отправка + обработка достижимости (overridable seam — тесты подменяют, чтобы не
     * дёргать Telegram на CI). На 403 (blocked) — markBlocked (самоочистка), на успехе — clearBlocked.
     */
    protected function sendComeback(string $tgId, string $text): void
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
                    log_message('warning', "[ComebackNudge] получатель {$tgId} недостижим, blocked_at: {$desc}");
                } else {
                    log_message('warning', "[ComebackNudge] sendMessage not ok ({$tgId}): {$desc}");
                }
            } else {
                $userModel->clearBlocked($tgId); // self-heal: вернулся — снимаем отметку
            }
        } catch (\Throwable $e) {
            log_message('error', "[ComebackNudge] sendMessage exception ({$tgId}): " . $e->getMessage());
        }
    }

    /** Клавиатура CTA (существующие routed-callback'и). */
    private static function keyboard(): string
    {
        return json_encode([
            'inline_keyboard' => [[
                ['text' => '🚀 Активные квесты', 'callback_data' => 'activeQuests'],
                ['text' => '◀️ Я', 'callback_data' => 'character'],
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    /** One-shot маркер: comeback-пинг этому персонажу отправлен (created_at явно — урок E4). */
    protected function recordPinged(int $charId, string $tgId): void
    {
        Database::connect()->table('action_log')->insert([
            'character_id'  => $charId,
            'chat_id'       => is_numeric($tgId) ? (int) $tgId : 0,
            'action_name'   => self::MARKER,
            'action_status' => 'Completed',
            'description'   => 'ADR-136 returnability comeback ping',
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Атомарно «забирает» рассылку за сегодня через game_settings-маркер (как DailyTipBroadcast).
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
