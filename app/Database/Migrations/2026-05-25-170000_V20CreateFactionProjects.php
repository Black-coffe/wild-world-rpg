<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * V20 (ADR-051) — faction_projects: общий (один-на-фракцию) проект снабжения.
 * Члены фракции асинхронно вносят золото в `progress`; при достижении порога —
 * `buff_until` = now + N часов (фракц-buff крафта для всех членов), progress
 * сбрасывается (carry), cycles_completed++. Idempotent (dropIfExists-safe).
 */
class V20CreateFactionProjects extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('faction_projects')) {
            return;
        }

        $this->forge->addField([
            'id'               => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'faction_id'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'progress'         => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'buff_until'       => ['type' => 'DATETIME', 'null' => true],
            'cycles_completed' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'created_at'       => ['type' => 'DATETIME', 'null' => true],
            'updated_at'       => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('faction_id');
        $this->forge->createTable('faction_projects');
    }

    public function down(): void
    {
        $this->forge->dropTable('faction_projects', true);
    }
}
