<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateClaimedCellsTable extends Migration
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
            'character_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
            ],
            'map_cell_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
            ],
            'claimed_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'status' => [
                'type' => 'ENUM',
                'constraint' => ['active', 'abandoned'],
                'default' => 'active',
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('character_id', 'characters', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('map_cell_id', 'map', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('claimed_cells');
    }

    public function down()
    {
        $this->forge->dropTable('claimed_cells');
    }
}
