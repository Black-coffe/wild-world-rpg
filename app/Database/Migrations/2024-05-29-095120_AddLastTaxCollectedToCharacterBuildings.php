<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddLastTaxCollectedToCharacterBuildings extends Migration
{
    public function up()
    {
        $this->forge->addColumn('character_buildings', [
            'last_tax_collected' => [
                'type' => 'DATETIME',
                'null' => true, // Поле может быть NULL, если налог еще не взимался
                'after' => 'updated_at', // Добавляем поле после updated_at
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('character_buildings', 'last_tax_collected');
    }
}
