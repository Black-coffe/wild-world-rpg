<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * v0.51.110 — Endgame scenarios system (Step 1: schema).
 *
 * 1:1 faction→scenario mapping per canon (wildworld.fun "Концепція и развитие
 * игры" 2025-01-29):
 *   - Милитари (id=1) → Доминирование
 *   - Партизаны (id=2) → Анархия
 *   - Инженеры (id=3) → Научный прорыв
 *   - Фермеры (id=4) → Эвакуация
 *
 * NEW table: faction_endgame_scores — tracks score progress per faction.
 * NEW char field: endgame_state — frozen/active/evacuated/won/lost.
 * NEW char field: endgame_lock_at — timestamp коли character was frozen.
 *
 * Threshold: 75,000 points per faction (medium pace ~3-6 months).
 */
class AddEndgameSystem extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'faction_id' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],
            'scenario_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'comment'    => 'Dominance|ScientificBreakthrough|Anarchy|Evacuation',
            ],
            'score' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'default'  => 0,
            ],
            'threshold' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'default'  => 75000,
                'comment'  => 'Medium pace per user choice 2026-05-08',
            ],
            'threshold_hit' => [
                'type'    => 'TINYINT',
                'constraint' => 1,
                'default' => 0,
            ],
            'threshold_hit_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'last_event_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('faction_id');
        $this->forge->addKey('threshold_hit');
        $this->forge->createTable('faction_endgame_scores');

        // Seed 4 rows для 4 factions (id 1-4 з factions table; id=5=Нейтралы skip).
        $now = date('Y-m-d H:i:s');
        $this->db->table('faction_endgame_scores')->insertBatch([
            ['faction_id' => 1, 'scenario_name' => 'Dominance',             'score' => 0, 'threshold' => 75000, 'created_at' => $now, 'updated_at' => $now],
            ['faction_id' => 2, 'scenario_name' => 'Anarchy',               'score' => 0, 'threshold' => 75000, 'created_at' => $now, 'updated_at' => $now],
            ['faction_id' => 3, 'scenario_name' => 'ScientificBreakthrough','score' => 0, 'threshold' => 75000, 'created_at' => $now, 'updated_at' => $now],
            ['faction_id' => 4, 'scenario_name' => 'Evacuation',            'score' => 0, 'threshold' => 75000, 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Add endgame_state + endgame_lock_at columns to characters.
        $this->forge->addColumn('characters', [
            'endgame_state' => [
                'type'       => 'ENUM',
                'constraint' => ['active', 'frozen', 'evacuated', 'won', 'lost'],
                'default'    => 'active',
                'comment'    => 'v0.51.110 endgame state per character',
            ],
            'endgame_lock_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('characters', ['endgame_state', 'endgame_lock_at']);
        $this->forge->dropTable('faction_endgame_scores', true);
    }
}
