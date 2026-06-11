<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-112 (E11) — таблицы титулов + active_title_id на characters.
 *
 * `titles` — определения (KEEP, контент, как achievements). `character_titles` — разблокировки
 * (PLAYER_DATA, зеркало character_achievements, UNIQUE(character_id,title_id)). `characters.
 * active_title_id` — один экипированный титул (CHARACTER_RESET → null после вайпа).
 *
 * WipeManifest: character_titles=PLAYER_DATA, titles=KEEP, active_title_id в characterResetValues —
 * классифицировано в Config\WipeManifest.
 */
class Adr112CreateTitles extends Migration
{
    public function up(): void
    {
        // titles — определения (контент)
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'title_key'   => ['type' => 'VARCHAR', 'constraint' => 64],
            'name'        => ['type' => 'VARCHAR', 'constraint' => 64],
            'description' => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
            'source_type' => ['type' => 'VARCHAR', 'constraint' => 16], // level | achievement
            'source_ref'  => ['type' => 'VARCHAR', 'constraint' => 64],  // число-уровень ИЛИ achievement_key
            'icon'        => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => '🎖'],
            'sort_order'  => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'enabled'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('title_key');
        $this->forge->addKey(['source_type', 'source_ref']);
        $this->forge->createTable('titles', true, ['ENGINE' => 'InnoDB', 'DEFAULT CHARSET' => 'utf8mb4']);

        // character_titles — разблокировки (прогресс)
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'character_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'title_id'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'unlocked_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['character_id', 'title_id']);
        $this->forge->createTable('character_titles', true);

        // characters.active_title_id — экипированный титул
        if (! $this->db->fieldExists('active_title_id', 'characters')) {
            $this->forge->addColumn('characters', [
                'active_title_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'after' => 'specialization'],
            ]);
        }
    }

    public function down(): void
    {
        if ($this->db->fieldExists('active_title_id', 'characters')) {
            $this->forge->dropColumn('characters', 'active_title_id');
        }
        $this->forge->dropTable('character_titles', true);
        $this->forge->dropTable('titles', true);
    }
}
