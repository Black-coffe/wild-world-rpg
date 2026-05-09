<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * v0.51.129 — community idea #1: craft queue.
 *
 * Додає 'queued' до character_tasks.status ENUM. Поточно: in_work / completed
 * / interrupted. Після migration: in_work / completed / interrupted / queued.
 *
 * Семантика 'queued':
 *   - Task створено, ресурси вже списані, але crafting НЕ почався.
 *   - Worker.php skip'ить queued (filter status='in_work' AND end_time<now).
 *   - При completion активного crafting'у GenericCraftCompletionHandler
 *     знаходить oldest queued same-recipe task → activate (set start_time=now,
 *     end_time=now+dur, status='in_work').
 *   - User cancel queued → refund resources + delete task.
 *
 * Реверсну тільки якщо немає 'queued' rows — інакше down() fail.
 */
class AddQueuedStatusToCharacterTasks extends Migration
{
    public function up(): void
    {
        // ALTER ENUM додаючи 'queued'. MySQL inplace ALTER (без full table rebuild
        // на додавання enum value) — fast навіть на large tables.
        $this->db->query(
            "ALTER TABLE character_tasks MODIFY COLUMN status "
            . "ENUM('in_work','completed','interrupted','queued') NOT NULL DEFAULT 'in_work'"
        );
    }

    public function down(): void
    {
        // Безпека: spring до rollback переконатись що 'queued' rows не існує
        // (інакше strict ENUM modify відмовиться).
        $queuedCount = $this->db->table('character_tasks')
            ->where('status', 'queued')
            ->countAllResults();
        if ($queuedCount > 0) {
            throw new \RuntimeException(
                "Cannot rollback: {$queuedCount} character_tasks rows have status='queued'. "
                . "Manual cleanup required (DELETE або UPDATE до 'interrupted')."
            );
        }

        $this->db->query(
            "ALTER TABLE character_tasks MODIFY COLUMN status "
            . "ENUM('in_work','completed','interrupted') NOT NULL DEFAULT 'in_work'"
        );
    }
}
