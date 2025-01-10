<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateActionLogTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'character_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => false,
            ],
            'chat_id' => [
                'type' => 'BIGINT',
                'constraint' => 20,
                'unsigned' => true,
                'null' => false,
            ],
            'action_name' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
            ],
            'action_status' => [
                'type' => 'ENUM',
                'constraint' => ['Pending', 'Completed', 'Skipped'],
                'default' => 'Pending',
            ],
            'description' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
                'default' => null,
                'on update' => 'CURRENT_TIMESTAMP',
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addForeignKey('character_id', 'characters', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('action_log');
    }

    public function down()
    {
        $this->forge->dropTable('action_log');
    }
}
