<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * web-bridge-p1-01 (ADR-189) — схема игры на сайте.
 *
 *   - web_play_state   — экран `/play` персонажа: текущий экран, история, док (reply-клавиатура),
 *                        режим ввода и счётчик синтетических message_id (с 1 000 000 000).
 *   - web_inbox        — входящие на сайте: фоновые сообщения виртуальному персонажу (`virtual`)
 *                        и копии для связанного игрока (`mirror`).
 *   - web_play_intents — дедуп действий `/play` по intent_id; `id` строки даёт синтетический
 *                        `update_id = −id` (в `telegram_updates_seen` не пишется).
 *
 * WipeManifest: web_play_state/web_inbox = PLAYER_DATA (character_id), web_play_intents = TRANSIENT.
 */
class CreateWebPlayTables extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'character_id'    => ['type' => 'INT', 'constraint' => 5, 'unsigned' => true, 'null' => false],
            'next_message_id' => ['type' => 'BIGINT', 'null' => false, 'default' => 1000000000],
            'screen'          => ['type' => 'JSON', 'null' => true],
            'history'         => ['type' => 'JSON', 'null' => true],
            'dock'            => ['type' => 'JSON', 'null' => true],
            'input'           => ['type' => 'JSON', 'null' => true],
            'updated_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('character_id', true);
        $this->forge->addForeignKey('character_id', 'characters', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('web_play_state', true);

        $this->forge->addField([
            'id'           => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'character_id' => ['type' => 'INT', 'constraint' => 5, 'unsigned' => true, 'null' => false],
            'message_id'   => ['type' => 'BIGINT', 'null' => false],
            'source'       => ['type' => 'ENUM', 'constraint' => ['virtual', 'mirror'], 'null' => false],
            'payload'      => ['type' => 'JSON', 'null' => false],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
            'read_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['character_id', 'read_at']);
        $this->forge->addUniqueKey(['character_id', 'message_id'], 'web_inbox_character_message_unique');
        $this->forge->addForeignKey('character_id', 'characters', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('web_inbox', true);

        $this->forge->addField([
            'id'         => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'account_id' => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'intent_id'  => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['account_id', 'intent_id'], 'web_play_intents_account_intent_unique');
        $this->forge->addKey('created_at');
        $this->forge->createTable('web_play_intents', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('web_play_intents', true);
        $this->forge->dropTable('web_inbox', true);
        $this->forge->dropTable('web_play_state', true);
    }
}
