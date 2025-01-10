<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateCharacterResourcesTable extends Migration
{
    public function up()
    {
        // Создание структуры таблицы 'character_resources'
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'id_characters' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => false,
            ],
            'id_resources' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => false,
            ],
            'quantity' => [
                'type' => 'BIGINT',
                'constraint' => 20,
                'unsigned' => true,
                'default' => 0,
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
        $this->forge->addKey('id', true); // Установка первичного ключа
        $this->forge->addForeignKey('id_characters', 'characters', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('id_resources', 'resources', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('character_resources', true); // Создание таблицы
    }

    public function down()
    {
        // Удаление таблицы 'character_resources'
        $this->forge->dropTable('character_resources', true);
    }
}
