<?php

declare(strict_types=1);

namespace App\TaskHandlers\Onboarding;

use App\Attributes\HandlerKey;
use App\Services\GameSettings\GameSettingsService;
use App\Services\Onboarding\OnboardingChainCatalog;
use App\TaskHandlers\BaseTaskHandler;
use CodeIgniter\Database\BaseResult;
use Config\Database;

/**
 * E4 (ROADMAP-100-SESSIONS) — авто-эскалация «застрял» поверх обучающей цепочки (ADR-103 Слой 2).
 *
 * Tasks.php everyMinute + внутренние guard'ы (паттерн DailyTipBroadcastHandler/QuestObjectiveHandler):
 * новичок, у которого назначенный онбординг-шаг (`quest_steps`, is_completed=0) висит дольше
 * `stuck_minutes` И который ПРЯМО СЕЙЧАС активен (last_seen в окне `active_window_minutes`),
 * получает ОДНУ контекстную подсказку «вот что нужно сделать» (description текущего шага).
 *
 * Анти-спам (как ADR-038):
 *   • killswitch `onboarding.auto_escalation.enabled` (default false → DORMANT);
 *   • opt-out — тот же тумблер «Совета дня» (characters.daily_tips_enabled=1);
 *   • one-shot per шаг — флаг `OnbNudge_<title_en>` в action_log (NOT EXISTS в выборке);
 *   • потолок новичка — onboarding.contextual_hints.max_level (общий со Слоем 1/2);
 *   • батч-кап на тик — max_per_run (защита от лавины уведомлений).
 *
 * «При активной сессии» (last_seen) — ключевое: подсказываем только тем, кто СЕЙЧАС играет
 * и реально застрял, а не оффлайн-призракам. Источник last_seen — E6 Ф1 (telegram_users.last_seen).
 */
#[HandlerKey(
    key: 'onboarding_nudge',
    displayName: 'Онбординг: авто-эскалация «застрял» (E4)',
    description: 'everyMinute + killswitch: новичку, застрявшему на онбординг-шаге > stuck_minutes и активному сейчас (last_seen), шлёт ОДНУ контекстную подсказку текущего шага. One-shot per шаг (OnbNudge_*), opt-out daily_tips_enabled. Killswitch onboarding.auto_escalation.enabled (default OFF).',
)]
class OnboardingNudgeHandler extends BaseTaskHandler
{
    private const THROTTLE_USEC = 35000; // ~28 msg/sec < лимит Telegram 30/sec
    private const LOG_PREFIX     = 'OnbNudge_';

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
        if ($this->settings->get('onboarding.auto_escalation.enabled', false) !== true) {
            return;
        }

        $stuckMin  = $this->intSetting('onboarding.auto_escalation.stuck_minutes', 45);
        $activeMin = $this->intSetting('onboarding.auto_escalation.active_window_minutes', 30);
        $maxPerRun = $this->intSetting('onboarding.auto_escalation.max_per_run', 20);
        $maxLevel  = $this->intSetting('onboarding.contextual_hints.max_level', 6);

        $rows = $this->fetchStuck($stuckMin, $activeMin, $maxLevel, $maxPerRun);

        $sent = 0;
        foreach ($rows as $row) {
            $charId  = is_numeric($row['character_id'] ?? null) ? (int) $row['character_id'] : 0;
            $tgId    = is_numeric($row['telegram_id'] ?? null) ? (int) $row['telegram_id'] : 0;
            $titleEn = is_string($row['title_en'] ?? null) ? $row['title_en'] : '';
            if ($charId <= 0 || $tgId === 0 || $titleEn === '') {
                continue;
            }

            $text = $this->nudgeText($titleEn);
            if ($text === null) {
                continue;
            }

            $this->sendNudge($tgId, $text);
            $this->recordNudged($charId, $tgId, $titleEn);
            $sent++;
            usleep(self::THROTTLE_USEC);
        }

        if ($sent > 0) {
            log_message('info', "[OnboardingNudge] послано {$sent} подсказок застрявшим новичкам");
        }
    }

    /**
     * Выборка застрявших новичков (overridable seam для unit-тестов).
     *
     * Гейты в SQL: онбординг-шаг не завершён и висит > stuck_min; чар — новичок (level ≤ max),
     * opt-in (daily_tips_enabled=1); tg достижим (blocked_at IS NULL) и активен сейчас
     * (last_seen в окне active_min); подсказка по этому шагу ещё не слалась (one-shot).
     *
     * @return list<array<string,mixed>>
     */
    protected function fetchStuck(int $stuckMin, int $activeMin, int $maxLevel, int $maxPerRun): array
    {
        $stuckMin  = max(1, $stuckMin);
        $activeMin = max(1, $activeMin);
        $maxLevel  = max(1, $maxLevel);
        $maxPerRun = max(1, min(200, $maxPerRun));

        $q = Database::connect()->query(
            "SELECT qs.character_id, q.title_en, tu.telegram_id
             FROM quest_steps qs
             JOIN quests q          ON q.id = qs.quest_id
             JOIN characters c      ON c.id = qs.character_id
             JOIN telegram_users tu ON tu.id = c.telegram_user_id
             WHERE q.title_en LIKE '" . OnboardingChainCatalog::PREFIX . "%'
               AND qs.is_completed = 0
               AND qs.created_at <= NOW() - INTERVAL {$stuckMin} MINUTE
               AND c.level <= {$maxLevel}
               AND c.daily_tips_enabled = 1
               AND tu.blocked_at IS NULL
               AND tu.last_seen >= NOW() - INTERVAL {$activeMin} MINUTE
               AND NOT EXISTS (
                   SELECT 1 FROM action_log a
                   WHERE a.character_id = qs.character_id
                     AND a.action_name = CONCAT('" . self::LOG_PREFIX . "', q.title_en)
               )
             ORDER BY qs.created_at ASC
             LIMIT {$maxPerRun}"
        );

        if (! $q instanceof BaseResult) {
            return [];
        }

        return array_values($q->getResultArray());
    }

    /**
     * Текст подсказки по застрявшему шагу: пере-подаёт description текущей задачи.
     * null — если title_en не принадлежит цепочке (квест переименован/удалён).
     */
    public function nudgeText(string $titleEn): ?string
    {
        $step = OnboardingChainCatalog::find($titleEn);
        if ($step === null) {
            return null;
        }

        return "⏳ *Застрял? Давай подскажу.*\n\n"
            . $step['description'] . "\n\n"
            . "_Текущий шаг ждёт в «Активных квестах». Справишься — получишь награду и следующую цель._";
    }

    /**
     * Overridable seam отправки (тесты подменяют, чтобы не дёргать Telegram на CI —
     * memory feedback_taskhandler_telegram_init_in_tests).
     */
    protected function sendNudge(int $tgId, string $text): void
    {
        $this->safeSendMessage($tgId, $text, [
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode([
                'inline_keyboard' => [[
                    ['text' => '🚀 Активные квесты', 'callback_data' => 'activeQuests'],
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                ]],
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    /** One-shot маркер: подсказка по этому шагу отправлена (enum-status строго 'Completed'). */
    protected function recordNudged(int $charId, int $tgId, string $titleEn): void
    {
        Database::connect()->table('action_log')->insert([
            'character_id' => $charId,
            'chat_id'      => $tgId,
            'action_name'  => self::LOG_PREFIX . $titleEn,
            'action_status' => 'Completed',
            'description'  => 'E4 onboarding auto-escalation nudge',
        ]);
    }

    private function intSetting(string $key, int $default): int
    {
        $raw = $this->settings->get($key, $default);

        return is_numeric($raw) ? (int) $raw : $default;
    }
}
