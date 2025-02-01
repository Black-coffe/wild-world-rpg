<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateTeleportBeaconLogs extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true
            ],
            'teleport_beacon_id' => [
                'type'     => 'INT',
                'unsigned' => true
            ],
            'character_id' => [
                'type'     => 'INT',
                'unsigned' => true
            ],
            // Откуда телепортировался игрок (можно дополнительно хранить координаты, если нужно)
            'from_map_cell_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
                'default'  => null
            ],
            // Стоимость/затраты на телепорт (в золоте или другом ресурсе)
            'teleport_cost' => [
                'type'     => 'INT',
                'unsigned' => true,
                'default'  => 0
            ],
            // Время, когда был совершен телепорт
            'teleported_at' => [
                'type' => 'DATETIME',
                'null' => false
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

        // Пример добавления внешних ключей,
        // если таблицы (teleport_beacons, characters, map) существуют и FK нужны:
        $this->forge->addForeignKey('teleport_beacon_id', 'teleport_beacons', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('character_id', 'characters', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('from_map_cell_id', 'map', 'id', 'CASCADE', 'CASCADE');

        // Создаём таблицу
        $this->forge->createTable('teleport_beacon_logs');
    }

    public function down()
    {
        // Откат миграции
        $this->forge->dropTable('teleport_beacon_logs');
    }
}
