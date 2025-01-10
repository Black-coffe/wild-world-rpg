<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateSalesTable extends Migration
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
            'crafted_item_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => false
            ],
            'quantity' => [
                'type' => 'INT',
                'constraint' => 11,
                'null' => false
            ],
            'price' => [
                'type' => 'DECIMAL',
                'constraint' => '10,2',
                'null' => false
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
        $this->forge->addForeignKey('crafted_item_id', 'crafted_items', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('sales');
    }

    public function down()
    {
        $this->forge->dropTable('sales');
    }
}
