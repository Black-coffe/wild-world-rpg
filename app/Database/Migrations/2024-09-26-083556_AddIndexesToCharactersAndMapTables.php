<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddIndexesToCharactersAndMapTables extends Migration
{
    public function up()
    {
        // Таблица characters
        $this->forge->addKey('cell_number');
        $this->forge->addKey(['intellect', 'agility', 'strength']);

        // Таблица map
        $this->forge->addKey('cell_number');
        $this->forge->addKey(['coordinate_x', 'coordinate_y']);
    }

    public function down()
    {
        // Таблица characters
        $this->forge->dropIndex('characters', 'cell_number');
        $this->forge->dropIndex('characters', ['intellect', 'agility', 'strength']);

        // Таблица map
        $this->forge->dropIndex('map', 'cell_number');
        $this->forge->dropIndex('map', ['coordinate_x', 'coordinate_y']);
    }
}
