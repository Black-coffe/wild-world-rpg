<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateQuestStepsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'quest_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'character_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'step_order' => [
                'type'       => 'INT',
                'constraint' => 11,
            ],
            'description' => [
                'type' => 'TEXT'
            ],
            'is_completed' => [
                'type'       => 'BOOLEAN',
                'default'    => false
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ]
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('quest_id', 'quests', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('character_id', 'characters', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('quest_steps');
    }

    public function down()
    {
        $this->forge->dropTable('quest_steps');
    }
}
