<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateTasksTable extends Migration
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
            'name' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
            ],
            'name_rus' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'default' => null,
            ],
            'description' => [
                'type' => 'TEXT',
            ],
            'min_duration' => [
                'type' => 'INT',
                'constraint' => 11,
                'default' => 0,
            ],
            'max_duration' => [
                'type' => 'INT',
                'constraint' => 11,
                'default' => 0,
            ],
            'type' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
            ],
            'difficulty_level' => [
                'type' => 'INT',
                'constraint' => 11,
            ],
            'execution_limit' => [
                'type' => 'INT',
                'constraint' => 11,
                'default' => 0, // 0 означает без ограничений
            ],
            'parallel_execution_allowed' => [
                'type' => 'BOOLEAN',
                'default' => true, // true - разрешена параллельная выполнимость
            ],
            'interruptible' => [
                'type' => 'BOOLEAN',
                'default' => true, // true - задача может быть прервана
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
        $this->forge->createTable('tasks');
    }

    public function down()
    {
        $this->forge->dropTable('tasks');
    }
}
