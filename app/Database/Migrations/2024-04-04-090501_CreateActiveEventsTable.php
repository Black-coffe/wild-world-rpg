<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateActiveEventsTable extends Migration
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
            'event_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => false,
            ],
            'start_time' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'end_time' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'status' => [
                'type' => 'ENUM',
                'constraint' => ['active', 'completed', 'cancelled'],
                'default' => 'active',
            ],
            'effect_applied' => [
                'type' => 'BOOLEAN',
                'default' => false,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('event_id', 'events', 'event_id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('active_events');
    }

    public function down()
    {
        $this->forge->dropForeignKey('active_events', 'active_events_event_id_foreign');
        $this->forge->dropTable('active_events');
    }
}
