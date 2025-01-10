<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateCharacterTasksTable extends Migration
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
            'telegram_user_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => false,
            ],
            'task_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => false,
            ],
            'start_time' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'end_time' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'status' => [
                'type' => 'ENUM',
                'constraint' => ['in_work', 'completed', 'interrupted'],
                'default' => 'in_work',
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('character_id', 'characters', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('telegram_user_id', 'telegram_users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('task_id', 'tasks', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('character_tasks');
    }

    public function down()
    {
        $this->forge->dropTable('character_tasks');
    }
}
