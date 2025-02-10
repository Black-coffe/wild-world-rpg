<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateOutfitsTable extends Migration
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
            // Поле для русского (или локализованного) названия с пробелами
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 150,
                'null'       => false,
            ],
            // Новое поле — название на английском, одним словом (slug)
            'name_en' => [
                'type'       => 'VARCHAR',
                'constraint' => 150,
                'null'       => true,  // Можно оставить NULL, если не всегда заполняется
            ],

            'description' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'armor_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
            ],
            'slot' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
            ],
            'armor_value' => [
                'type'       => 'FLOAT',
                'default'    => 0,
            ],
            'physical_resistance' => [
                'type'       => 'FLOAT',
                'default'    => 0,
            ],
            'fire_resistance' => [
                'type'       => 'FLOAT',
                'default'    => 0,
            ],
            'poison_resistance' => [
                'type'       => 'FLOAT',
                'default'    => 0,
            ],
            'speed_modifier' => [
                'type'       => 'FLOAT',
                'default'    => 0,
            ],
            'stealth_modifier' => [
                'type'       => 'FLOAT',
                'default'    => 0,
            ],
            'weight' => [
                'type'       => 'FLOAT',
                'default'    => 0,
            ],
            'durability' => [
                'type'       => 'INT',
                'default'    => 0,
            ],
            'durability_max' => [
                'type'       => 'INT',
                'default'    => 100,
            ],
            'required_strength' => [
                'type'    => 'INT',
                'default' => 0,
            ],
            'required_level' => [
                'type'    => 'INT',
                'default' => 0,
            ],
            'rarity' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'default'    => 'Common',
            ],
            'bonus_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
            ],
            'special_bonus' => [
                'type' => 'TEXT', // Можно хранить JSON
                'null' => true,
            ],
            'penalty_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
            ],
            'penalty' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'price' => [
                'type'    => 'INT',
                'default' => 0,
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

        // Primary Key
        $this->forge->addKey('id', true);

        // Создаём таблицу outfits
        $this->forge->createTable('outfits');
    }

    public function down()
    {
        // Откат: удаляем таблицу
        $this->forge->dropTable('outfits', true);
    }
}
