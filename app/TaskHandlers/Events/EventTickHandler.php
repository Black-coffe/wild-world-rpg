<?php

namespace App\TaskHandlers\Events;

use App\Services\Events\EventDispatcher;

/**
 * F7.3 — thin wrapper для Tasks.php scheduler.
 *
 * Замінює 19 окремих handler'ів (Hurricane, Snowfall, Fever, ..., Mirage)
 * одним dispatch loop'ом. Викликається з `event.tick` schedule кожну хвилину
 * через singleInstance lock.
 *
 * Старі handler'и в `app/TaskHandlers/Events/*Handler.php` НЕ видаляються
 * у F7.3 (буде у F7.10 cleanup) — це дозволяє швидкий rollback через
 * git revert + Tasks.php restore. Старі schedules з Tasks.php прибрано
 * у цьому ж commit'і — з нього їх ніхто не дьоргає.
 *
 * Stats з dispatcher'а пишуться в логи лише при error > 0 (silent на success).
 */
final class EventTickHandler
{
    public function process(): void
    {
        $stats = (new EventDispatcher())->tickAllActive();

        // Логуємо лише якщо щось дивне
        if (($stats['errors'] ?? 0) > 0) {
            log_message('warning', '[EventTick] errors=' . $stats['errors']
                . ' active=' . ($stats['active_events_total'] ?? 0)
                . ' dispatched=' . ($stats['events_dispatched'] ?? 0)
                . ' applied=' . ($stats['effects_applied'] ?? 0));
        }
    }
}
