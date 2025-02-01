<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateTeleportBeacons extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true
            ],
            'character_id' => [
                'type'     => 'INT',
                'unsigned' => true
            ],
            'faction_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
                'default'  => null
            ],
            'player_level_at_creation' => [
                'type'     => 'INT',
                'unsigned' => true
            ],
            'map_cell_id' => [
                'type'     => 'INT',
                'unsigned' => true
            ],
            'coordinate_x' => [
                'type' => 'INT'
            ],
            'coordinate_y' => [
                'type' => 'INT'
            ],
            'tax_cost' => [
                'type'     => 'INT',
                'unsigned' => true,
                'default'  => 0
            ],
            'remaining_uses' => [
                'type'     => 'INT',
                'unsigned' => true,
                'default'  => 100
            ],
            'last_teleport_at' => [
                'type' => 'DATETIME',
                'null' => true
            ],
            'ownership_type' => [
                'type'       => 'ENUM',
                'constraint' => ['author', 'captured'],
                'default'    => 'author'
            ],
            'settings_json' => [
                'type' => 'TEXT',
                'null' => true,
                'default' => null
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true
            ]
        ]);

        // Указываем PRIMARY KEY
        $this->forge->addKey('id', true);

        // Пример добавления внешних ключей (при наличии соответствующих таблиц):
         $this->forge->addForeignKey('character_id', 'characters', 'id', 'CASCADE', 'CASCADE');
         $this->forge->addForeignKey('map_cell_id', 'map', 'id', 'CASCADE', 'CASCADE');
         $this->forge->addForeignKey('faction_id', 'factions', 'id', 'CASCADE', 'CASCADE');

        // Создаём таблицу
        $this->forge->createTable('teleport_beacons');
    }

    public function down()
    {
        // Откат миграции
        $this->forge->dropTable('teleport_beacons');
    }
}
