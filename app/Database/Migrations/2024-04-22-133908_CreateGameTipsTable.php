<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateGameTipsTable extends Migration
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
            'tip_type' => [
                'type'       => 'ENUM',
                'constraint' => ['биомы', 'ресурсы', 'крафт', 'персонаж', 'события', 'NPC', 'общие'],
            ],
            'content' => [
                'type' => 'TEXT'
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
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('game_tips');
    }

    public function down()
    {
        $this->forge->dropTable('game_tips');
    }
}
