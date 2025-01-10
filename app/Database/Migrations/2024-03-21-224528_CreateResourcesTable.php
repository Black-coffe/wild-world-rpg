<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateResourcesTable extends Migration
{
    public function up()
    {
        // Создание структуры таблицы 'resources'
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'constraint' => 5,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'name' => [
                'type' => 'VARCHAR',
                'constraint' => '255',
                'unique' => true, // Установка уникальности для поля name
            ],
            'name_en' => [
                'type' => 'VARCHAR',
                'constraint' => '255',
                'unique' => true, // Установка уникальности для поля name
            ],
            'description' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'biome_id' => [
                'type' => 'TEXT',
                'null' => true,
                'unsigned' => true,
            ],
            'is_tradeable' => [
                'type' => 'BOOLEAN',
            ],
            'type' => [
                'type' => 'VARCHAR',
                'constraint' => '255',
            ],
            'price' => [
                'type' => 'INT',
                'constraint' => 11,
            ],
            'buy_price' => [
                'type' => 'DECIMAL',
                'constraint' => '10,2', // Пример использования 10 цифр с 2 десятичными знаками
                'null' => true,
            ],
            'sell_price' => [
                'type' => 'DECIMAL',
                'constraint' => '10,2',
                'null' => true,
            ],
            'rarity' => [
                'type' => 'INT',
                'constraint' => 2,
            ],
            'level_required' => [
                'type' => 'INT',
                'constraint' => 5,
            ],
            'initial_quantity' => [
                'type' => 'INT',
                'constraint' => 9,
                'default' => 100,
            ],
            'keyword' => [ // Добавление нового поля keyword
                'type' => 'VARCHAR',
                'constraint' => '100', // Достаточно для одного слова
                'null' => true, // По умолчанию будет null
            ],
            'icon_text' => [
                'type' => 'TEXT',
                'null' => true,
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
        $this->forge->createTable('resources', true); // Создание таблицы
    }

    public function down()
    {
        // Удаление таблицы 'resources'
        $this->forge->dropTable('resources', true);
    }
}
