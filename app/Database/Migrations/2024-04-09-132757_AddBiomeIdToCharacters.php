<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddBiomeIdToCharacters extends Migration
{
    public function up()
    {
        // Добавление нового поля 'biome_id'
        $fields = [
            'biome_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true, // Сделайте поле необязательным, если персонаж может находиться вне биома
                'after'      => 'cell_number', // Укажите, после какого поля разместить новое
            ],
        ];
        $this->forge->addColumn('characters', $fields);

        // Добавление внешнего ключа
        $this->forge->addForeignKey('biome_id', 'biomes', 'id', 'CASCADE', 'SET NULL');
    }

    public function down()
    {
        // Удаление поля и внешнего ключа
        $this->forge->dropForeignKey('characters', 'characters_biome_id_foreign');
        $this->forge->dropColumn('characters', 'biome_id');
    }
}
