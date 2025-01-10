<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePlayerDetectionTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true
            ],
            'detector_player_id' => [
                'type' => 'INT',
                'unsigned' => true,
            ],
            'detected_player_id' => [
                'type' => 'INT',
                'unsigned' => true,
            ],
            'detector_cell' => [
                'type' => 'INT',
                'unsigned' => true,
            ],
            'detected_cell' => [
                'type' => 'INT',
                'unsigned' => true,
            ],
            'distance' => [
                'type' => 'FLOAT',
            ],
            'detected_at' => [
                'type' => 'DATETIME',
                'null' => false,
                'default' => new \CodeIgniter\Database\RawSql('CURRENT_TIMESTAMP'),
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('player_detection');
    }

    public function down()
    {
        $this->forge->dropTable('player_detection');
    }
}
