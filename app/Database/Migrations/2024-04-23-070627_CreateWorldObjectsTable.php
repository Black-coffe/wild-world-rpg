<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateWorldObjectsTable extends Migration
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
            'name' => [
                'type' => 'VARCHAR',
                'constraint' => '255'
            ],
            'name_en' => [
                'type' => 'VARCHAR',
                'constraint' => '255'
            ],
            'description' => [
                'type' => 'TEXT'
            ],
            'biome_id' => [
                'type' => 'TEXT',
                'null' => true,
                'comment' => 'JSON array of possible contents'
            ],
            'max_count' => [
                'type' => 'INT',
                'constraint' => 11
            ],
            'discovery_tools' => [
                'type' => 'TEXT',
                'null' => true,
                'comment' => 'JSON array of tools required for discovery'
            ],
            'contents' => [
                'type' => 'TEXT',
                'null' => true,
                'comment' => 'JSON array of possible contents'
            ],
            'respawn_time' => [
                'type' => 'INT',
                'constraint' => 11,
                'comment' => 'Time in hours for the object to respawn'
            ],
            'status' => [
                'type' => 'ENUM',
                'constraint' => ['active', 'inactive'],
                'default' => 'active'
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('world_objects');
    }

    public function down()
    {
        $this->forge->dropTable('world_objects');
    }
}
