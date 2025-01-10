<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateTelegramUsersTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'telegram_id' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
                'unsigned'   => false,
                'null'       => false,
            ],
            'username' => [
                'type'       => 'VARCHAR',
                'constraint' => '100',
                'null'       => true,
            ],
            'first_name' => [
                'type'       => 'VARCHAR',
                'constraint' => '100',
                'null'       => true,
            ],
            'last_name' => [
                'type'       => 'VARCHAR',
                'constraint' => '100',
                'null'       => true,
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
        $this->forge->createTable('telegram_users');
    }

    public function down()
    {
        $this->forge->dropTable('telegram_users');
    }
}
