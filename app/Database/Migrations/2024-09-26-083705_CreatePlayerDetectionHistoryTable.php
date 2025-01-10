<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePlayerDetectionHistoryTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'detector_player_id' => [
                'type'       => 'INT',
                'unsigned'   => true,
            ],
            'detected_player_id' => [
                'type'       => 'INT',
                'unsigned'   => true,
            ],
            'detected_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->createTable('player_detection_history');
    }

    public function down()
    {
        $this->forge->dropTable('player_detection_history');
    }
}
