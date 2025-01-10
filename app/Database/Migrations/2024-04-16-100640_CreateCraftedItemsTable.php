<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateCraftedItemsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'                   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'name_rus'             => ['type' => 'VARCHAR', 'constraint' => '255'],
            'name_eng'             => ['type' => 'VARCHAR', 'constraint' => '255'],
            'type'                 => ['type' => 'VARCHAR', 'constraint' => '100', 'null' => true],
            'direction_craft'      => ['type' => 'VARCHAR', 'constraint' => '100', 'null' => true],
            'effect'               => ['type' => 'VARCHAR', 'constraint' => '255', 'null' => true],
            'gathering_speed'      => ['type' => 'FLOAT', 'null' => true],
            'durability_count'     => ['type' => 'INT', 'null' => true],
            'durability_time'      => ['type' => 'INT', 'null' => true],
            'type_of_battle'       => ['type' => 'VARCHAR', 'constraint' => '100', 'null' => true],
            'damage'               => ['type' => 'INT', 'null' => true],
            'armor'                => ['type' => 'INT', 'null' => true],
            'hp'                   => ['type' => 'INT', 'null' => true],
            'character_boost'      => ['type' => 'TEXT', 'null' => true],
            'weight'               => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true],
            'price'                => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true],
            'stack_size'           => ['type' => 'INT', 'null' => true],
            'required_level'       => ['type' => 'INT', 'null' => true],
            'required_skills'      => ['type' => 'TEXT', 'null' => true],
            'required_resources'   => ['type' => 'TEXT', 'null' => true],
            'crafting_time'        => ['type' => 'VARCHAR', 'constraint' => '100', 'null' => true],
            'crafting_location'    => ['type' => 'VARCHAR', 'constraint' => '255', 'null' => true],
            'description'          => ['type' => 'TEXT', 'null' => true],
            'created_at'           => ['type' => 'DATETIME', 'null' => true],
            'updated_at'           => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->createTable('crafted_items');
    }

    public function down()
    {
        $this->forge->dropTable('crafted_items');
    }
}
