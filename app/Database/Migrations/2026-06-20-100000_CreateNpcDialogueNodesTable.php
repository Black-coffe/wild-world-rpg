<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-089 Фаза 5+ — ветвящиеся диалоги именных NPC.
 *
 * Узел дерева беседы: (npc_id, node_key) → текст + варианты ответа (JSON options:
 * [{label, next, rel}] — label кнопки, next = node_key следующего узла ('' = конец),
 * rel = изменение отношения). Старт с node_key='root'. У NPC с деревом «Поговорить»
 * открывает беседу вместо одной реплики. Дерево = у именных NPC.
 *
 * WipeManifest: **KEEP** (контент-определение, не player-данные). Классифицировано.
 */
class CreateNpcDialogueNodesTable extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('npc_dialogue_nodes')) {
            return;
        }
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'npc_id'     => ['type' => 'INT', 'unsigned' => true, 'comment' => 'FK npcs.id'],
            'node_key'   => ['type' => 'VARCHAR', 'constraint' => 64, 'comment' => 'Ключ узла (root = старт)'],
            'text_ru'    => ['type' => 'TEXT', 'comment' => 'Реплика NPC в узле'],
            'options'    => ['type' => 'TEXT', 'null' => true, 'comment' => 'JSON [{label,next,rel}] — варианты ответа'],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['npc_id', 'node_key']);
        $this->forge->createTable('npc_dialogue_nodes');
    }

    public function down(): void
    {
        $this->forge->dropTable('npc_dialogue_nodes', true);
    }
}
