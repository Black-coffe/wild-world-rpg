<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateExploredCellsTable extends Migration
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
                'null' => false,
            ],
            'telegram_user_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => false,
            ],
            'map_cell_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => false,
            ],
            'biome_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => false,
            ],
            // Дополнительные поля по предложению
            'character_level' => [
                'type' => 'INT',
                'constraint' => 11,
                'null' => true,
            ],
            'cell_status' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
            ],
            'notes' => [
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
        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('character_id', 'characters', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('telegram_user_id', 'telegram_users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('map_cell_id', 'map', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('biome_id', 'biomes', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('explored_cells');
    }

    public function down()
    {
        $this->forge->dropTable('explored_cells');
    }
}
