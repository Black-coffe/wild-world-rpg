<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateCharacterGameTipTable extends Migration
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
            'character_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'game_tip_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'viewed_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addForeignKey('character_id', 'characters', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('game_tip_id', 'game_tips', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('character_game_tips');
    }

    public function down()
    {
        $this->forge->dropTable('character_game_tips');
    }
}
