<?php

declare(strict_types=1);

namespace App\TaskHandlers\Streak;

use App\Attributes\HandlerKey;
use App\Models\TelegramUserModel;
use App\Services\Player\StreakMilestoneService;
use App\TaskHandlers\BaseTaskHandler;

/**
 * ADR-132 (надстройка над E6/ADR-108 Ф3) — выдача наград за вехи серии (cron-poll state-driven,
 * зеркало {@see \App\TaskHandlers\Achievements\AchievementCheckCron}).
 *
 * Recurring (Tasks.php everyMinute): для каждой включённой вехи set-based SQL находит персонажей,
 * чей login_streak ≥ порога и веха ещё не забрана, идемпотентно «столбит» её, выдаёт награду
 * (золото/ресурс/титул) и уведомляет — БАТЧЕМ per-player (один msg на чара со всеми его новыми вехами).
 *
 * Throttle: не более `returnability.streak.milestones_max_awards_per_tick` выдач за тик (против
 * notification-шторма при активации, когда сотни чаров с накопленной серией разом пробивают вехи).
 * Killswitch `returnability.streak.milestones_enabled` (default OFF → cron no-op до активации).
 *
 * Media-off: уведомление — самодостаточный текст (имя вехи + дни + конкретные награды), без фото.
 */
#[HandlerKey(
    key: 'streak_milestone_check',
    displayName: 'Серия входов: выдача наград за вехи (cron-poll)',
    description: 'Recurring (everyMinute): выдаёт награды за вехи серии (3/5/7/10/15/30/50/100 дней) по login_streak, батч-уведомление per-player, cap per tick. Killswitch returnability.streak.milestones_enabled.',
)]
class StreakMilestoneCron extends BaseTaskHandler
{
    protected StreakMilestoneService $service;
    protected TelegramUserModel $telegramUserModel;

    public function __construct()
    {
        $this->service           = new StreakMilestoneService();
        $this->telegramUserModel = new TelegramUserModel();
    }

    /**
     * @param array<string,mixed> $task
     */
    public function handle(array $task = []): void
    {
        if (! $this->service->enabled()) {
            return;
        }

        $cap     = $this->service->maxAwardsPerTick();
        $awarded = 0;

        /**
         * charId => list of {milestone, reward}
         *
         * @var array<int, list<array{milestone:array<int|string,mixed>, reward:array{gold:int,resource:?string,resource_qty:int,title:?string}}>> $perPlayer
         */
        $perPlayer = [];

        foreach ($this->service->definitions() as $milestone) {
            if ($awarded >= $cap) {
                break;
            }
            $milestoneId = is_numeric($milestone['id'] ?? null) ? (int) $milestone['id'] : 0;
            if ($milestoneId <= 0) {
                continue;
            }

            $remaining = $cap - $awarded;
            $charIds   = $this->service->qualifyingCharacterIds($milestone, $remaining);

            foreach ($charIds as $charId) {
                if ($awarded >= $cap) {
                    break;
                }
                if ($this->service->claim($charId, $milestoneId)) {
                    $awarded++;
                    $reward = $this->service->grantRewards($charId, $milestone);
                    $perPlayer[$charId][] = ['milestone' => $milestone, 'reward' => $reward];
                }
            }
        }

        foreach ($perPlayer as $charId => $list) {
            $this->notify($charId, $list);
        }

        if ($awarded > 0) {
            log_message('info', "[StreakMilestoneCheck] awarded={$awarded} to " . count($perPlayer) . ' chars');
        }
    }

    /**
     * Батч-уведомление одному персонажу обо всех его новых вехах за тик (media-off self-contained).
     *
     * @param list<array{milestone:array<int|string,mixed>, reward:array{gold:int,resource:?string,resource_qty:int,title:?string}}> $list
     */
    private function notify(int $characterId, array $list): void
    {
        if ($list === []) {
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

        $header = count($list) > 1 ? '🔥 *Новые вехи серии!*' : '🔥 *Веха серии достигнута!*';
        $msg    = $header . "\n\n";

        foreach ($list as $item) {
            $m         = $item['milestone'];
            $r         = $item['reward'];
            $icon      = is_string($m['icon'] ?? null) && $m['icon'] !== '' ? $m['icon'] : '🔥';
            $name      = is_string($m['name'] ?? null) ? $m['name'] : '';
            $desc      = is_string($m['description'] ?? null) ? $m['description'] : '';
            $threshold = is_numeric($m['threshold_days'] ?? null) ? (int) $m['threshold_days'] : 0;

            $msg .= "{$icon} *{$name}* — {$threshold} " . self::plural($threshold, 'день', 'дня', 'дней') . " подряд\n";
            if ($desc !== '') {
                $msg .= "{$desc}\n";
            }

            $rewards = [];
            if ($r['gold'] > 0) {
                $rewards[] = "💰 {$r['gold']} золота";
            }
            if ($r['resource'] !== null && $r['resource_qty'] > 0) {
                $rewards[] = "📦 {$r['resource']} ×{$r['resource_qty']}";
            }
            if ($r['title'] !== null && $r['title'] !== '') {
                $rewards[] = "🎖 титул «{$r['title']}»";
            }
            if ($rewards !== []) {
                $msg .= 'Награда: ' . implode(', ', $rewards) . "\n";
            }
            $msg .= "\n";
        }

        $msg .= '_Держись, выживший. Чем длиннее серия — тем ценнее тайник._';

        // Без inline-кнопок: «Перс» уже в постоянной reply-клавиатуре бота (button-connectedness rule).
        $this->safeSendMessage($chatId, $msg, ['parse_mode' => 'Markdown']);
    }

    private static function plural(int $n, string $one, string $few, string $many): string
    {
        $mod100 = $n % 100;
        $mod10  = $n % 10;
        if ($mod100 > 10 && $mod100 < 20) {
            return $many;
        }
        if ($mod10 === 1) {
            return $one;
        }
        if ($mod10 > 1 && $mod10 < 5) {
            return $few;
        }
        return $many;
    }
}
