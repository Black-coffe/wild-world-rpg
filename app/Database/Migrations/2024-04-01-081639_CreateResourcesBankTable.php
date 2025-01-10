<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateResourcesBankTable extends Migration
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
            'resource_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
            ],
            'current_quantity' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
            ],
            'resources_purchased' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
            ],
            'resources_sold' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
            ],
            'last_update' => [
                'type' => 'DATETIME',
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('resource_id', 'resources', 'id');
        $this->forge->createTable('resources_bank');
    }

    public function down()
    {
        $this->forge->dropTable('resources_bank');
    }
}
