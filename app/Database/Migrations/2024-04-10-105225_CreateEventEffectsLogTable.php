<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateEventEffectsLogTable extends Migration
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
            'character_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
            ],
            'event_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
            ],
            'cell_number' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true, // Установите null, если не для всех записей будет доступен номер ячейки
            ],
            'biome_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true, // Установите null, если не для всех записей будет доступен ID биома
            ],
            'effect_details' => [
                'type' => 'TEXT',
                'null' => false,
            ],
            'event_time' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'additional_info' => [
                'type' => 'TEXT',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('character_id', 'characters', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('event_id', 'events', 'event_id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('event_effects_log');
    }

    public function down()
    {
        $this->forge->dropTable('event_effects_log');
    }
}
