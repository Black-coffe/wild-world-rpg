<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-109 (E8) — таблица ежедневных заданий игрока.
 *
 * Дейлик = строка с baseline-снимком монотонного счётчика (см. ADR-109): выполнено, когда
 * `current_counter − baseline ≥ objective_qty`. Один набор = 3 строки за `task_date` (slot 1-3).
 * Unique (character_id, task_date, slot) → идемпотентность ленивой генерации за день.
 *
 * WipeManifest: PLAYER_DATA (link=character_id) — классифицировано в Config\WipeManifest.
 */
class Adr109CreateCharacterDailyTasks extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'character_id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'task_date'            => ['type' => 'DATE'],
            'slot'                 => ['type' => 'TINYINT', 'constraint' => 2],
            'task_key'             => ['type' => 'VARCHAR', 'constraint' => 64],
            'objective_type'       => ['type' => 'VARCHAR', 'constraint' => 32],
            'objective_qty'        => ['type' => 'INT', 'constraint' => 11, 'default' => 1],
            'baseline'             => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'reward_gold'          => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'reward_resource_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'reward_resource_qty'  => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'is_completed'         => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'completed_at'         => ['type' => 'DATETIME', 'null' => true],
            'created_at'           => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['character_id', 'task_date', 'slot']);
        $this->forge->addKey(['character_id', 'task_date']);
        $this->forge->createTable('character_daily_tasks', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('character_daily_tasks', true);
    }
}
