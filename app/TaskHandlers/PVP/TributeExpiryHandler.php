<?php

declare(strict_types=1);

namespace App\TaskHandlers\PVP;

use App\Attributes\HandlerKey;
use App\Services\PVE\TributeService;
use App\TaskHandlers\BaseTaskHandler;

/**
 * ADR-135 Ф3 «Трофейная подать» — авто-снятие истёкших податей по `expires_at`.
 *
 * Recurring (Tasks.php hourly): идемпотентный batch-UPDATE active→expired для податей, чей срок
 * (expires_at) прошёл. **НЕ killswitch-gated** — чистка работает даже при выключенном
 * `tribute.enabled` (иначе данники зависли бы навсегда). Dormant: 0 активных податей → no-op.
 * Silent (без Telegram) — уведомление об освобождении вешает Фаза 4 (UX); поэтому handler
 * не дёргает telegram() (трап BaseTaskHandler invalid-API-key в тестах не задевается).
 */
#[HandlerKey(
    key: 'tribute_expiry',
    displayName: 'Трофейная подать: авто-снятие истёкших (cron)',
    description: 'Recurring (hourly): идемпотентно снимает истёкшие подати (active→expired по expires_at). НЕ killswitch-gated (чистка). ADR-135 Ф3.',
)]
class TributeExpiryHandler extends BaseTaskHandler
{
    protected TributeService $service;

    public function __construct()
    {
        $this->service = new TributeService();
    }

    /**
     * @param array<string,mixed> $task
     */
    public function handle(array $task = []): void
    {
        $expired = $this->service->expireOverdue();
        if ($expired > 0) {
            log_message('info', "[TributeExpiry] expired={$expired} податей (ADR-135 Ф3)");
        }
    }
}
