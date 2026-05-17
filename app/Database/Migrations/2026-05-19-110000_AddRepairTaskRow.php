<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S5b (v0.51.188) — `repair` task row для `RepairCompletionHandler`.
 *
 * Использует `handler_key='repair'` (ADR-023 handler_key dispatcher).
 * `min/max_duration` берётся из `GameSettings::get('repair.task_duration_minutes', 15)`
 * — задаются здесь как fallback (15 default).
 *
 * Idempotent: INSERT IGNORE (UNIQUE по name='Repair' через UPDATE).
 */
class AddRepairTaskRow extends Migration
{
    public function up(): void
    {
        $existing = $this->db->table('tasks')
            ->where('handler_key', 'repair')
            ->get()
            ->getRowArray();

        if (! empty($existing)) {
            return; // Already exists — idempotent.
        }

        $now = date('Y-m-d H:i:s');
        $this->db->table('tasks')->insert([
            'name'                       => 'Repair',
            'handler_key'                => 'repair',
            'name_rus'                   => 'Ремонт инструмента',
            'description'                => 'Восстановление прочности крафтового инструмента до template-default. Стоимость и длительность настраиваются через `GameSettings` (repair.cost_fraction, repair.task_duration_minutes, repair.restore_durability_to_percent).',
            'min_duration'               => 15,
            'max_duration'               => 15,
            'type'                       => 'craft',
            'difficulty_level'           => 1,
            'execution_limit'            => 0,
            'parallel_execution_allowed' => 0, // одно ремонт-задание за раз
            'interruptible'              => 1,
            'created_at'                 => $now,
            'updated_at'                 => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('tasks')->where('handler_key', 'repair')->delete();
    }
}
