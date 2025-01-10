<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateCharacterBuildingsTable extends Migration
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
            'building_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
            ],
            'faction_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
            ],
            'map_cell_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
            ],
            'amount' => [
                'type' => 'INT',
                'constraint' => 11,
                'default' => 1,
            ],
            'character_level_during_construction' => [
                'type' => 'INT',
                'constraint' => 11,
            ],
            'hp' => [
                'type' => 'INT',
                'constraint' => 11,
            ],
            'level' => [
                'type' => 'INT',
                'constraint' => 2,
                'default' => 1,
            ],
            'built_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'building_type' => [
                'type' => 'ENUM',
                'constraint' => ['military', 'residential', 'farming', 'resource', 'engineering'],
            ],
            'tax' => [
                'type' => 'INT',
                'constraint' => 11,
            ],
            'usage' => [
                'type' => 'ENUM',
                'constraint' => ['personal', 'collective', 'all'],
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true, // Или false, если хотите, чтобы поле всегда заполнялось
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true, // Или false, если хотите, чтобы поле всегда заполнялось
            ],
            'last_tax_collected' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'tax_collection_status' => [
                'type' => 'ENUM',
                'constraint' => ['SUCCESS', 'FAILURE'],
                'default' => 'SUCCESS',
            ],
            'disappearance_date' => [
                'type' => 'DATETIME',
                'null' => true,
                'comment' => 'Дата и время исчезновения постройки',
            ],
            'usage_count' => [
                'type' => 'INT',
                'constraint' => 11,
                'null' => true,
                'comment' => 'Количество использований данного строения',
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('character_id', 'characters', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('building_id', 'buildings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('faction_id', 'factions', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('map_cell_id', 'map', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('character_buildings');
    }

    public function down()
    {
        $this->forge->dropTable('character_buildings');
    }
}
