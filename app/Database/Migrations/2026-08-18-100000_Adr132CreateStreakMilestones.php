<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-132 (надстройка над E6/ADR-108 Ф3) — лестница вех login-стрика.
 *
 * 2 таблицы:
 *  - streak_milestones (KEEP)                  — контент-определения вех (порог дней → награды).
 *  - character_streak_milestones (PLAYER_DATA) — какие вехи персонаж уже забрал (идемпотентность).
 *
 * Счётчик серии (characters.login_streak / login_streak_last_day) — уже на проде (ADR-108 Ф3),
 * НЕ создаётся здесь. Заморозка серии (characters.streak_freezes) — Ф3, не в этой миграции.
 *
 * utf8mb4 (имена/описания с emoji/кириллицей). Классификация — Config\WipeManifest.
 */
class Adr132CreateStreakMilestones extends Migration
{
    public function up(): void
    {
        // streak_milestones (KEEP — контент)
        $this->forge->addField([
            'id'                  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'threshold_days'      => ['type' => 'INT', 'constraint' => 11],
            'name'                => ['type' => 'VARCHAR', 'constraint' => 128],
            'description'         => ['type' => 'VARCHAR', 'constraint' => 512, 'default' => ''],
            'icon'                => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => '🔥'],
            'reward_gold'         => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'reward_resource'     => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
            'reward_resource_qty' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'title_key'           => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'grants_freeze'       => ['type' => 'INT', 'constraint' => 11, 'default' => 0], // forward-data для Ф3 (заморозка серии)
            'sort_order'          => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'enabled'             => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('threshold_days');
        $this->forge->createTable('streak_milestones', true, ['ENGINE' => 'InnoDB', 'DEFAULT CHARSET' => 'utf8mb4']);

        // character_streak_milestones (PLAYER_DATA — забранные вехи, идемпотентность выдачи)
        $this->forge->addField([
            'id'                  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'character_id'        => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'streak_milestone_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'claimed_at'          => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['character_id', 'streak_milestone_id']);
        $this->forge->addKey('character_id');
        $this->forge->createTable('character_streak_milestones', true, ['ENGINE' => 'InnoDB', 'DEFAULT CHARSET' => 'utf8mb4']);
    }

    public function down(): void
    {
        $this->forge->dropTable('character_streak_milestones', true);
        $this->forge->dropTable('streak_milestones', true);
    }
}
