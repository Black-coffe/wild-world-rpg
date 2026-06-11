<?php

declare(strict_types=1);

namespace App\TaskHandlers\Achievements;

use App\Attributes\HandlerKey;
use App\Models\TelegramUserModel;
use App\Services\Player\AchievementService;
use App\TaskHandlers\BaseTaskHandler;

/**
 * W9 (ADR-066) — выдача достижений (cron-poll state-driven, зеркало QuestObjectiveHandler).
 *
 * Recurring (Tasks.php everyMinute): для каждого включённого достижения set-based SQL
 * находит персонажей, выполнивших критерий и ещё не получивших его, выдаёт (idempotent)
 * и уведомляет — БАТЧЕМ per-player (один msg на чара со всеми его новыми анлоками за тик).
 *
 * Throttle: не более `achievement.max_awards_per_tick` выдач за тик (против notification-шторма
 * при активации, когда сотни существующих чаров разом выполняют пороги). Killswitch
 * `achievement.enabled` (default OFF на ship W9 — cron no-op до активации в W10).
 */
#[HandlerKey(
    key: 'achievement_check',
    displayName: 'Достижения: выдача по state-критериям (cron-poll)',
    description: 'Recurring (everyMinute): выдаёт достижения по persistent state (level/explore/craft/base/faction/quests/gold), батч-уведомление per-player, cap per tick. Killswitch achievement.enabled.',
)]
class AchievementCheckCron extends BaseTaskHandler
{
    protected AchievementService $service;
    protected TelegramUserModel $telegramUserModel;

    public function __construct()
    {
        $this->service           = new AchievementService();
        $this->telegramUserModel = new TelegramUserModel();
    }

    /**
     * @param array<string,mixed> $task
     */
    public function handle(array $task = []): void
    {
        if (! $this->service->isEnabled()) {
            return;
        }

        $cap     = $this->service->maxAwardsPerTick();
        $awarded = 0;

        /** @var array<int, list<array<int|string,mixed>>> $perPlayer charId => list of achievement rows */
        $perPlayer = [];
        /** @var array<int, int> $perPlayerGold charId => суммарное золото-награда за тик (E9, ADR-110) */
        $perPlayerGold = [];

        foreach ($this->service->definitions() as $ach) {
            if ($awarded >= $cap) {
                break;
            }
            $achId = is_numeric($ach['id'] ?? null) ? (int) $ach['id'] : 0;
            if ($achId <= 0) {
                continue;
            }

            $remaining = $cap - $awarded;
            $charIds   = $this->service->qualifyingCharacterIds($ach, $remaining);

            foreach ($charIds as $charId) {
                if ($awarded >= $cap) {
                    break;
                }
                if ($this->service->award($charId, $achId)) {
                    $awarded++;
                    $perPlayer[$charId][] = $ach;
                    // E9 (ADR-110): золото-награда за новую разблокировку (0 если dormant).
                    $perPlayerGold[$charId] = ($perPlayerGold[$charId] ?? 0) + $this->service->grantReward($charId, $ach);
                }
            }
        }

        foreach ($perPlayer as $charId => $achList) {
            $this->notify($charId, $achList, $perPlayerGold[$charId] ?? 0);
        }

        if ($awarded > 0) {
            log_message('info', "[AchievementCheck] awarded={$awarded} to " . count($perPlayer) . ' chars');
        }
    }

    /**
     * Батч-уведомление одному персонажу обо всех его новых достижениях за тик.
     *
     * @param list<array<int|string,mixed>> $achList
     * @param int                           $rewardGold суммарное золото-награда за тик (E9; 0 если dormant)
     */
    private function notify(int $characterId, array $achList, int $rewardGold = 0): void
    {
        if (empty($achList)) {
            return;
        }

        $db   = \Config\Database::connect();
        $cRes = $db->table('characters')->select('telegram_user_id')->where('id', $characterId)->get();
        $cRow = $cRes === false ? null : $cRes->getRowArray();
        if (! is_array($cRow)) {
            return;
        }
        $tgUserId = is_numeric($cRow['telegram_user_id'] ?? null) ? (int) $cRow['telegram_user_id'] : 0;
        if ($tgUserId <= 0) {
            return;
        }
        $tg = $this->telegramUserModel->find($tgUserId);
        if (! is_array($tg) || empty($tg['telegram_id'])) {
            return;
        }
        $chatId = is_numeric($tg['telegram_id']) ? (int) $tg['telegram_id'] : 0;
        if ($chatId === 0) {
            return;
        }

        $plural = count($achList) > 1 ? 'Новые достижения' : 'Новое достижение';
        $msg    = "🏅 *{$plural}!*\n\n";
        foreach ($achList as $ach) {
            $icon  = is_string($ach['icon'] ?? null) && $ach['icon'] !== '' ? $ach['icon'] : '🏅';
            $title = is_string($ach['title'] ?? null) ? $ach['title'] : '';
            $desc  = is_string($ach['description'] ?? null) ? $ach['description'] : '';
            $msg  .= "{$icon} *{$title}*\n{$desc}\n\n";
        }
        if ($rewardGold > 0) {
            $msg .= "🏆 Награда: *+{$rewardGold}* золота\n\n";
        }
        $msg .= '_Так держать, выживший._';

        // Без inline-кнопок: «Перс» уже зафиксирован в постоянной reply-клавиатуре бота
        // (Перс / База / Крафт / Карта / Настройки, StartCommand:40, one_time_keyboard=false).
        // Дублировать persistent-кнопку inline'ом = шум (button-connectedness rule, см.
        // memory feedback_no_duplicate_persistent_keyboard_buttons). W10 добавит уместную
        // «🏅 Достижения» (когда появится экран просмотра).
        $this->safeSendMessage($chatId, $msg, ['parse_mode' => 'Markdown']);
    }
}
