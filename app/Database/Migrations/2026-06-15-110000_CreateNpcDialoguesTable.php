<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-089 Фаза 1 — контент-таблица реплик NPC (data-driven, как квесты).
 *
 * Хранит тексты по (npc_id, dialogue_type): greeting (интро экрана встречи), talk, ask,
 * trade, rob_success, rob_fail. Несколько строк на (npc_id, type) допустимы → случайный
 * выбор. Тон — скупой постапок (ADR-062, редколлегия).
 *
 * WipeManifest: **KEEP** (контент-определение, как quests/recipes; НЕ player-данные —
 * нет character_id/telegram_user_id). Классифицировано в Config\WipeManifest.
 */
class CreateNpcDialoguesTable extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('npc_dialogues')) {
            return;
        }

        $this->forge->addField([
            'id'            => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'npc_id'        => ['type' => 'INT', 'unsigned' => true, 'comment' => 'FK npcs.id'],
            'dialogue_type' => ['type' => 'VARCHAR', 'constraint' => 32, 'comment' => 'greeting/talk/ask/trade/rob_success/rob_fail'],
            'text_ru'       => ['type' => 'TEXT', 'comment' => 'Реплика (скупой постапок-тон)'],
            'weight'        => ['type' => 'INT', 'default' => 1, 'comment' => 'Вес для случайного выбора при нескольких строках'],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['npc_id', 'dialogue_type']);
        $this->forge->createTable('npc_dialogues');
    }

    public function down(): void
    {
        $this->forge->dropTable('npc_dialogues', true);
    }
}
