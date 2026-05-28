<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W9 (ADR-066) — выданные достижения персонажей.
 *
 * UNIQUE(character_id, achievement_id) — идемпотентность выдачи на уровне БД
 * (AchievementService::award ловит дубликат и не падает). Cron-poll выдаёт через
 * LEFT JOIN ca IS NULL, так что дубликаты в норме не возникают, но UNIQUE — гарантия.
 */
class W9CreateCharacterAchievementsTable extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('character_achievements')) {
            return;
        }

        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'character_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'achievement_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'unlocked_at'    => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['character_id', 'achievement_id']);
        $this->forge->addKey('character_id');
        $this->forge->createTable('character_achievements', false, [
            'ENGINE'  => 'InnoDB',
            'CHARSET' => 'utf8mb4',
            'COLLATE' => 'utf8mb4_unicode_ci',
        ]);
    }

    public function down(): void
    {
        $this->forge->dropTable('character_achievements', true);
    }
}
