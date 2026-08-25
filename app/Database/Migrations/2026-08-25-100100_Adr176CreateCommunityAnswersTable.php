<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-176 — Community chat bot, story 02: банк утверждённых ответов + черновики.
 *
 * Таблица community_answers (KEEP — Config\WipeManifest: авторский корпус наравне
 * с game_tips, вайп прогресса игроков не должен стирать написанные владельцем ответы).
 * UNIQUE(client_key) — идемпотентность повторного `community:import` (ULID генерится
 * локально при импорте, не в БД). requires_setting — NULL-имый ключ килсвитча, рубеж
 * live/dormant (§5.5 плана). Новая строка без явного статуса получает 'draft'.
 *
 * Наполнение банка контентом — не эта story: на старте ни одной строки.
 */
class Adr176CreateCommunityAnswersTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'client_key' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
            ],
            'question_pattern' => [
                'type' => 'TEXT',
            ],
            'answer_text' => [
                'type' => 'TEXT',
            ],
            'requires_setting' => [
                'type'       => 'VARCHAR',
                'constraint' => 120,
                'null'       => true,
            ],
            'source_ref' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['draft', 'approved', 'rejected', 'revoked'],
                'default'    => 'draft',
            ],
            'approved_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'approved_by' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ],
            'revoked_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('client_key');
        $this->forge->addKey('status');
        $this->forge->createTable('community_answers', true, ['ENGINE' => 'InnoDB', 'DEFAULT CHARSET' => 'utf8mb4']);
    }

    public function down(): void
    {
        $this->forge->dropTable('community_answers', true);
    }
}
