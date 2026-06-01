<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-088 Фаза 2 — счётчик побед над NPC (для квестов objective_type=npc_kills).
 *
 * Монотонный per-character счётчик; инкрементится в PvEService::attack() при победе
 * игрока над NPC. QuestObjectiveHandler сверяет npc_kills >= objective_qty (как char_level).
 * Прогресс-колонка → сбрасывается при вайпе (WipeManifest characterResetValues, ADR-087).
 */
class AddNpcKillsToCharacters extends Migration
{
    public function up(): void
    {
        if ($this->db->fieldExists('npc_kills', 'characters')) {
            return;
        }
        $this->forge->addColumn('characters', [
            'npc_kills' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => false,
                'default'    => 0,
                'after'      => 'whats_new_seen',
            ],
        ]);
    }

    public function down(): void
    {
        if ($this->db->fieldExists('npc_kills', 'characters')) {
            $this->forge->dropColumn('characters', 'npc_kills');
        }
    }
}
