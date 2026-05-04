<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * F1.9 — таблица admin_audit_log: непрерываемая лента destructive
 * админских действий (broadcast, character reset, poll send/delete/stop).
 *
 * Цель: если админ-аккаунт скомпрометирован или сотрудник недобросовестный,
 * у нас остаётся forensic след — кто, когда, какое действие, на ком/над чем.
 *
 * Не предполагается удалять записи (только архивировать). Поэтому отдельная
 * таблица, не общий action_log.
 */
class CreateAdminAuditLogTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'constraint'     => 20,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'admin_user_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => false,
                'comment'    => 'users.id админа, выполнившего действие',
            ],
            'action' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => false,
                'comment'    => 'CHARACTER_RESET, BROADCAST_SEND, POLL_DELETE, POLL_SEND, POLL_STOP, ...',
            ],
            'target_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'null'       => true,
                'comment'    => 'character / poll / telegram_chat / null',
            ],
            'target_id' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
                'null'       => true,
                'comment'    => 'ID цели действия (характер, опрос и т.п.)',
            ],
            'payload' => [
                'type' => 'TEXT',
                'null' => true,
                'comment' => 'JSON с дополнительными деталями: title, count, длина текста, и т.п.',
            ],
            'ip_address' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => true,
            ],
            'user_agent' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('admin_user_id');
        $this->forge->addKey(['action', 'created_at'], false, false, 'idx_aal_action_created');
        $this->forge->createTable('admin_audit_log', true);
    }

    public function down()
    {
        $this->forge->dropTable('admin_audit_log', true);
    }
}
