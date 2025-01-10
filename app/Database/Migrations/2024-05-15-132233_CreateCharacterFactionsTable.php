<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateCharacterFactionsTable extends Migration
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
            'character_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'faction_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'joined_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'notified_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'notification_count' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 0,
            ],
            'notification_status' => [
                'type'       => 'ENUM',
                'constraint' => ['True', 'False'],
                'default'    => 'False',
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('character_id', 'characters', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('faction_id', 'factions', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('character_factions');
    }

    public function down()
    {
        $this->forge->dropTable('character_factions');
    }
}
