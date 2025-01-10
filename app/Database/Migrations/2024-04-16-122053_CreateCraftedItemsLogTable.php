<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateCraftedItemsLogTable extends Migration
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
            'character_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => false
            ],
            'task_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true
            ],
            'crafted_item_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => false
            ],
            'type' => [
                'type' => 'VARCHAR',
                'constraint' => '100',
                'null' => true
            ],
            'direction_craft' => [
                'type' => 'VARCHAR',
                'constraint' => '100',
                'null' => true
            ],
            'crafting_location' => [
                'type' => 'VARCHAR',
                'constraint' => '255',
                'default' => 'all',
                'null' => true
            ],
            'durability_count' => [
                'type' => 'INT',
                'constraint' => 11,
                'default' => 0
            ],
            'durability_time' => [
                'type' => 'DATE',
                'null' => true
            ],
            'quantity' => [
                'type' => 'INT',
                'constraint' => 11,
                'default' => 1
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
        $this->forge->addForeignKey('character_id', 'characters', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('task_id', 'tasks', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('crafted_item_id', 'crafted_items', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('crafted_items_log');
    }

    public function down()
    {
        $this->forge->dropTable('crafted_items_log');
    }
}
