<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-176 — Community chat bot, story 02: сырой поток сообщений из группового чата сообщества.
 *
 * Таблица community_messages (TRANSIENT — Config\WipeManifest: поток регенерируется чатом,
 * окно хранения 30 дней): каждая доставленная апдейтом реплика чата, включая метку, был ли
 * это вопрос и обращён ли он к боту, плюс статус разбора (new/answered/escalated/ignored).
 * UNIQUE(chat_id, message_id) — идемпотентность повторной доставки Telegram-апдейта.
 *
 * Ничего не пишет и не читает — это story 05/06. Только схема.
 */
class Adr176CreateCommunityMessagesTable extends Migration
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
            'chat_id' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
            ],
            'message_thread_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true,
            ],
            'message_id' => [
                'type'       => 'INT',
                'constraint' => 11,
            ],
            'reply_to_message_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true,
            ],
            'telegram_user_id' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
            ],
            'username' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'text' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'sent_at' => [
                'type' => 'DATETIME',
            ],
            'is_question' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'addressed_to_bot' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['new', 'answered', 'escalated', 'ignored'],
                'default'    => 'new',
            ],
            'answered_by_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey(['chat_id', 'message_thread_id', 'sent_at']);
        $this->forge->addKey(['status', 'sent_at']);
        $this->forge->addUniqueKey(['chat_id', 'message_id']);
        $this->forge->createTable('community_messages', true, ['ENGINE' => 'InnoDB', 'DEFAULT CHARSET' => 'utf8mb4']);
    }

    public function down(): void
    {
        $this->forge->dropTable('community_messages', true);
    }
}
