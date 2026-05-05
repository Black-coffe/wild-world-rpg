<?php

namespace App\TaskHandlers\Events;

use App\Services\Events\EventDispatcher;

/**
 * F7.3 — thin wrapper для Tasks.php scheduler.
 *
 * Замінюесть 19 окремих handler'ів (Hurricane, Snowfall, Fever, ..., Mirage)
 * одним dispatch loop'ом. Вызовається с `event.tick` schedule кожнв хвилину
 * через singleInstance lock.
 *
 * Стари handler'и в `app/TaskHandlers/Events/*Handler.php` НЕ видаляються
 * в F7.3 (будет в F7.10 cleanup) — це дозволяесть швидкий rollback через
 * git revert + Tasks.php restore. Стари schedules с Tasks.php убрано
 * в цьомв ж commit'и — с нього их ніхто не дьоргаесть.
 *
 * Stats с dispatcher'а пишуться в логи лише при error > 0 (silent на success).
 */
final class EventTickHandler
{
    public function process(): void
    {
        $stats = (new EventDispatcher())->tickAllActive();

        // Логуємо лише если щось дивне
        if (($stats['errors'] ?? 0) > 0) {
            log_message('warning', '[EventTick] errors=' . $stats['errors']
                . ' active=' . ($stats['active_events_total'] ?? 0)
                . ' dispatched=' . ($stats['events_dispatched'] ?? 0)
                . ' applied=' . ($stats['effects_applied'] ?? 0));
        }
    }
}
