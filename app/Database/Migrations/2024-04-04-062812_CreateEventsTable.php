<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateEventsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'event_id' => [
                'type' => 'INT',
                'constraint' => 5,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'name' => [
                'type' => 'VARCHAR',
                'constraint' => '255',
            ],
            // NEW FIELD: English Translation
            'name_english' => [
                'type' => 'VARCHAR',
                'constraint' => '255',
                'null' => true, // Allow it to be null initially
            ],
            'description' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'biome_ids' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'event_type' => [
                'type' => 'ENUM',
                'constraint' => ['local', 'global'],
                'default' => 'local',
            ],
            'random_coverage' => [
                'type' => 'BOOLEAN',
                'default' => false,
            ],
            'duration' => [
                'type' => 'INT',
                'constraint' => 11,
                'null' => true,
            ],
            'frequency_per_week' => [
                'type' => 'INT',
                'constraint' => 7,
                'null' => true,
                'default' => 1,
            ],
            'effect_type' => [
                'type' => 'ENUM',
                'constraint' => ['damage', 'heal', 'buff', 'debuff', 'none'],
                'default' => 'none',
            ],
            'effect_value' => [
                'type' => 'INT',
                'constraint' => 11,
                'null' => true,
            ],
            'protection_item_id' => [
                'type' => 'INT',
                'constraint' => 5,
                'unsigned' => true,
                'null' => true,
            ],
            'start_time' => [
                'type' => 'TIME',
                'null' => true,
            ],
            'end_time' => [
                'type' => 'TIME',
                'null' => true,
            ],
            'img_path' => [
                'type' => 'TEXT',
                'null' => true,
                'default' => 'uploads/telegram/default_event_image.png',
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('event_id', true);
        $this->forge->createTable('events');
    }

    public function down()
    {
        $this->forge->dropTable('events');
    }
}
