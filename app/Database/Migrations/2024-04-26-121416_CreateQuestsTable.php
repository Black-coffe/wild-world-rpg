<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateQuestsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true
            ],
            'title_ru' => [
                'type'       => 'VARCHAR',
                'constraint' => '255',
            ],
            'title_en' => [
                'type'       => 'VARCHAR',
                'constraint' => '255',
            ],
            'description' => [
                'type'       => 'TEXT'
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['active', 'completed', 'available']
            ],
            'min_level' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true
            ],
            'reward_type' => [
                'type'       => 'VARCHAR',
                'constraint' => '255',
                'null'       => true
            ],
            'reward' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true
            ]
        ]);

        $this->forge->addKey('id', true);
        $this->forge->createTable('quests');
    }

    public function down()
    {
        $this->forge->dropTable('quests');
    }
}
