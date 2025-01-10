<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateQuestRequirementsTable extends Migration
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
            'requirement' => [
                'type' => 'TEXT'
            ]
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('quest_id', 'quests', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('quest_requirements');
    }

    public function down()
    {
        $this->forge->dropTable('quest_requirements');
    }
}
