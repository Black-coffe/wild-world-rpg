<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-119 (E19, гейт L50) — коллекции + музей базы.
 *
 * 3 таблицы:
 *  - collections (KEEP)               — контент-определения коллекций (сеты для собирательства).
 *  - collection_slots (KEEP)          — слоты коллекции: какой ресурс и сколько сдать.
 *  - character_collection_slots (PLAYER_DATA) — заполненные игроком слоты (сдача-синк, навсегда).
 *
 * utf8mb4 (имена/описания с emoji/кириллицей). Классификация — Config\WipeManifest.
 */
class Adr119CreateCollections extends Migration
{
    public function up(): void
    {
        // collections (KEEP — контент)
        $this->forge->addField([
            'id'               => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'collection_key'   => ['type' => 'VARCHAR', 'constraint' => 64],
            'name'             => ['type' => 'VARCHAR', 'constraint' => 128],
            'description'      => ['type' => 'VARCHAR', 'constraint' => 512, 'default' => ''],
            'theme'            => ['type' => 'VARCHAR', 'constraint' => 64, 'default' => ''],
            'min_level'        => ['type' => 'INT', 'constraint' => 11, 'default' => 50],
            'reward_title_key' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'icon'             => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => '🏛'],
            'sort_order'       => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'enabled'          => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('collection_key');
        $this->forge->createTable('collections', true, ['ENGINE' => 'InnoDB', 'DEFAULT CHARSET' => 'utf8mb4']);

        // collection_slots (KEEP — контент)
        $this->forge->addField([
            'id'            => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'collection_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'resource_name' => ['type' => 'VARCHAR', 'constraint' => 128],
            'required_qty'  => ['type' => 'INT', 'constraint' => 11, 'default' => 1],
            'sort_order'    => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('collection_id');
        $this->forge->createTable('collection_slots', true, ['ENGINE' => 'InnoDB', 'DEFAULT CHARSET' => 'utf8mb4']);

        // character_collection_slots (PLAYER_DATA — сдача-синк)
        $this->forge->addField([
            'id'                 => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'character_id'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'collection_slot_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'donated_at'         => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['character_id', 'collection_slot_id']);
        $this->forge->addKey('character_id');
        $this->forge->createTable('character_collection_slots', true, ['ENGINE' => 'InnoDB', 'DEFAULT CHARSET' => 'utf8mb4']);
    }

    public function down(): void
    {
        $this->forge->dropTable('character_collection_slots', true);
        $this->forge->dropTable('collection_slots', true);
        $this->forge->dropTable('collections', true);
    }
}
