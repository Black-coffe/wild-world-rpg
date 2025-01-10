<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateCharacterDataTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'constraint' => 5,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'character_id' => [
                'type' => 'INT',
                'null' => true,
                'default' => null,
            ],
            'coordinate_x' => [
                'type' => 'FLOAT',
                'null' => true,
                'default' => null,
            ],
            'coordinate_y' => [
                'type' => 'FLOAT',
                'null' => true,
                'default' => null,
            ],
            'biome' => [
                'type' => 'VARCHAR',
                'constraint' => 100,
                'null' => true,
                'default' => null,
            ],
            'explored_cells' => [
                'type' => 'INT',
                'default' => null,
                'null' => true,
            ],
            'unique_resources' => [
                'type' => 'INT',
                'default' => null,
                'null' => true,
            ],
            'days_played' => [
                'type' => 'INT',
                'default' => null,
                'null' => true,
            ],
            'character_level' => [
                'type' => 'INT',
                'default' => null,
                'null' => true,
            ],
            'experience_points' => [
                'type' => 'INT',
                'default' => null,
                'null' => true,
            ],
            'health' => [
                'type' => 'INT',
                'default' => null,
                'null' => true,
            ],
            'strength' => [
                'type' => 'INT',
                'default' => null,
                'null' => true,
            ],
            'agility' => [
                'type' => 'INT',
                'default' => null,
                'null' => true,
            ],
            'intelligence' => [
                'type' => 'INT',
                'default' => null,
                'null' => true,
            ],
            'endurance' => [
                'type' => 'INT',
                'default' => null,
                'null' => true,
            ],
            'closed_tasks' => [
                'type' => 'INT',
                'default' => null,
                'null' => true,
            ],
            'interrupted_tasks' => [
                'type' => 'INT',
                'default' => null,
                'null' => true,
            ],
            'message_text' => [
                'type' => 'TEXT',
                'null' => true,
                'default' => null,
            ],
            'message_image_path' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
                'default' => null,
            ],
            'message_buttons' => [
                'type' => 'TEXT',
                'null' => true,
                'default' => null,
            ],
            'created_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
                'on update' => 'CURRENT_TIMESTAMP',
            ],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->createTable('character_data');
    }


    public function down()
    {
        $this->forge->dropTable('character_data');
    }
}
