<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-089 Фаза 2 (reactivity) — память отношений NPC к персонажу.
 *
 * Один ряд на (character_id, npc_id): relation_score меняется от действий игрока
 * (грабёж/убийство/бой ↓, беседа/торговля ↑). Отношение к ТИПУ NPC (npc_id), а не к
 * транзиентному спавну → «этот вид помнит, что ты с ним делал». Порог → attitude
 * (hostile/wary/neutral/friendly) влияет на приветствие и доступные действия.
 *
 * WipeManifest: **PLAYER_DATA** (есть character_id → полный вайп = DELETE всех; single-char
 * сброс = DELETE WHERE character_id). Классифицировано в Config\WipeManifest.
 */
class CreateCharacterNpcRelationsTable extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('character_npc_relations')) {
            return;
        }

        $this->forge->addField([
            'id'                  => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'character_id'        => ['type' => 'INT', 'unsigned' => true, 'comment' => 'FK characters.id'],
            'npc_id'              => ['type' => 'INT', 'unsigned' => true, 'comment' => 'FK npcs.id (тип NPC)'],
            'relation_score'      => ['type' => 'INT', 'default' => 0, 'comment' => 'Отношение: <0 враждебнее, >0 дружелюбнее'],
            'interactions'        => ['type' => 'INT', 'default' => 0, 'comment' => 'Счётчик взаимодействий'],
            'last_interaction_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at'          => ['type' => 'DATETIME', 'null' => true],
            'updated_at'          => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['character_id', 'npc_id']);
        $this->forge->createTable('character_npc_relations');
    }

    public function down(): void
    {
        $this->forge->dropTable('character_npc_relations', true);
    }
}
