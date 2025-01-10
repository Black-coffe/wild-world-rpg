<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateMapTable extends Migration
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
            'cell_number' => [
                'type' => 'INT',
                'constraint' => 11,
            ],
            'coordinate_x' => [
                'type' => 'INT',
                'constraint' => 11,
                'index' => true,
            ],
            'coordinate_y' => [
                'type' => 'INT',
                'constraint' => 11,
                'index' => true,
            ],
            'biome_id' => [
                'type' => 'INT',
                'constraint' => 11, // Установите такой же размер, как и у поля id в таблице biomes
                'null' => true,
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

        $this->forge->addPrimaryKey('id');
        $this->forge->createTable('map');
    }

    public function down()
    {
        $this->forge->dropTable('map');
    }
}
