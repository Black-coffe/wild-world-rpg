<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-019 Step 3 — добавляет 'paused' в `character_tasks.status` ENUM.
 *
 * Поход (`MarchingTaskHandler`) при прерывании (обнаружен игрок / тяжёлая рана /
 * вход в опасную зону / квест-триггер) создаёт новый `character_tasks` row со
 * `status='paused'` + `task_settings.steps_remaining` + `task_settings.paused_reason`.
 * Кнопка `[🚜 Продолжить поход]` (callback `march_resume`) переводит его в 'in_work'.
 * `Worker.php` фильтрует `status='in_work'` → paused-задачи пропускаются (как 'queued').
 *
 * До: in_work / completed / interrupted / queued (последнее — community idea #1).
 * После: + paused. MySQL inplace ALTER (добавление enum-значения — fast даже на
 * больших таблицах). Зеркалит паттерн миграции `AddQueuedStatusToCharacterTasks`.
 *
 * down() реверсит только если нет 'paused' rows — иначе strict ENUM modify откажется.
 */
class AddPausedStatusToCharacterTasks extends Migration
{
    public function up(): void
    {
        $this->db->query(
            "ALTER TABLE character_tasks MODIFY COLUMN status "
            . "ENUM('in_work','completed','interrupted','queued','paused') NOT NULL DEFAULT 'in_work'"
        );
    }

    public function down(): void
    {
        $pausedCount = $this->db->table('character_tasks')
            ->where('status', 'paused')
            ->countAllResults();
        if ($pausedCount > 0) {
            throw new \RuntimeException(
                "Cannot rollback: {$pausedCount} character_tasks rows have status='paused'. "
                . "Manual cleanup required (DELETE или UPDATE до 'interrupted')."
            );
        }

        $this->db->query(
            "ALTER TABLE character_tasks MODIFY COLUMN status "
            . "ENUM('in_work','completed','interrupted','queued') NOT NULL DEFAULT 'in_work'"
        );
    }
}
