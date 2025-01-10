<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateFactionsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 5,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => '100',
                'null'       => false,
            ],
            'description' => [
                'type'       => 'TEXT',
                'null'       => true,
            ],
            'type' => [
                'type'       => "ENUM('PVP', 'PVE')",
                'null'       => false,
            ],
            'unique_ability' => [
                'type'       => 'TEXT',
                'null'       => true,
            ],
            'created_at' => [
                'type'       => 'DATETIME',
                'null'       => true,
            ],
            'updated_at' => [
                'type'       => 'DATETIME',
                'null'       => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->createTable('factions');
    }

    public function down()
    {
        $this->forge->dropTable('factions');
    }
}
