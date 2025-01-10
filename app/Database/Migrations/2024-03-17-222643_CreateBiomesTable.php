<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateBiomesTable extends Migration
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
            'name' => [
                'type' => 'VARCHAR',
                'constraint' => 100,
            ],
            'description' => [
                'type' => 'TEXT',
            ],
            'biome_type' => [
                'type' => 'ENUM',
                'constraint' => ['hot', 'cold', 'wet', 'dry', 'volcanic', 'cave', 'jungle', 'desert', 'plain'],
                'default' => 'plain',
                'null' => false,
            ],
            'danger_level_text' => [
                'type' => 'VARCHAR',
                'constraint' => 100,
                'null' => true,
            ],
            'danger_level' => [
                'type' => 'INT',
                'constraint' => 2,
                'null' => true,
            ],
            'survival_difficulty_text' => [
                'type' => 'VARCHAR',
                'constraint' => 100,
                'null' => true,
            ],
            'survival_difficulty' => [
                'type' => 'INT',
                'constraint' => 2,
                'null' => true,
            ],
            'occurrence_rate' => [
                'type' => 'DECIMAL',
                'constraint' => '5,2',
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
                'default' => null,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
                'default' => null,
            ]
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->createTable('biomes');
    }

    public function down()
    {
        $this->forge->dropTable('biomes');
    }
}
