<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateBiomeWorldObjectMap extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true
            ],
            'biome_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true
            ],
            'world_object_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true
            ],
            'map_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true
            ],
            'status' => [
                'type' => 'ENUM',
                'constraint' => ['active', 'found', 'cleared'],
                'default' => 'active'
            ],
            'object_type' => [
                'type' => 'ENUM',
                'constraint' => ['single_use', 'multi_use'],
                'default' => 'single_use'
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

        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('biome_id', 'biomes', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('world_object_id', 'world_objects', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('map_id', 'map', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('biome_world_object_map');
    }

    public function down()
    {
        $this->forge->dropTable('biome_world_object_map');
    }
}
