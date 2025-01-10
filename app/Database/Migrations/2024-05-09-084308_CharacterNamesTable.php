<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CharacterNamesTable extends Migration
{
    public function up()
    {
        // Определение структуры таблицы
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'character_name' => [
                'type' => 'VARCHAR',
                'constraint' => '100',
                'null' => false,
                'unique' => true, // Уникальное имя персонажа
            ],
            'status' => [
                'type' => 'ENUM',
                'constraint' => ['free', 'occupied'],
                'default' => 'free',
                'null' => false,
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

        // Добавление первичного ключа
        $this->forge->addKey('id', true);

        // Создание таблицы
        $this->forge->createTable('character_names');
    }

    public function down()
    {
        // Удаление таблицы в случае отката
        $this->forge->dropTable('character_names');
    }
}
