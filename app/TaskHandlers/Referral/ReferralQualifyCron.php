<?php

declare(strict_types=1);

namespace App\TaskHandlers\Referral;

use App\Attributes\HandlerKey;
use App\Services\Player\ReferralService;
use App\TaskHandlers\BaseTaskHandler;

/**
 * S8 (ROADMAP-RETENTION-10, ADR-146) — выдача титула «Зовущий» по дозреванию приглашённых.
 *
 * Recurring (Tasks.php everyMinute): {@see ReferralService::qualifyAndReward} находит дозревших
 * (приглашённый достиг qualify-уровня), но не награждённых рефералов, выдаёт реферреру non-power
 * титул (идемпотентно через TitleService) и возвращает список уведомлений — cron шлёт их per-player.
 * Throttle внутри сервиса. Killswitch `referral.enabled` (+ `titles.enabled`) → no-op до активации.
 *
 * Класс НЕ final; конструктор НЕ eager-инициализирует Telegram (lazy через BaseTaskHandler) — тесты.
 */
#[HandlerKey(
    key: 'referral_qualify',
    displayName: 'Реферал: выдача титула «Зовущий» по дозреванию приглашённого',
    description: 'Recurring (everyMinute): выдаёт реферреру титул, когда приглашённый достиг qualify-уровня, + уведомление per-player, cap per tick. Killswitch referral.enabled. ADR-146.',
)]
class ReferralQualifyCron extends BaseTaskHandler
{
    protected ReferralService $service;

    public function __construct(?ReferralService $service = null)
    {
        $this->service = $service ?? new ReferralService();
    }

    /**
     * @param array<int|string, mixed> $task
     */
    public function handle(array $task = []): void
    {
        $notices = $this->service->qualifyAndReward(25);
        foreach ($notices as $notice) {
            $this->safeSendMessage($notice['chat_id'], $notice['text'], ['parse_mode' => 'Markdown']);
        }
        if ($notices !== []) {
            log_message('info', '[ReferralQualify] rewarded=' . count($notices));
        }
    }
}
