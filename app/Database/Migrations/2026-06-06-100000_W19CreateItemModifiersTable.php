<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W19 (ADR-074) — таблица модификаторов экземпляров предметов («Зачарование»).
 *
 * Один модификатор на экземпляр (UNIQUE item_type+item_instance_id → перезапись через upsert).
 * `item_instance_id` = `characters_weapons.id` (per-instance, НЕ определение weapons.id → зачарование
 * одного меча не баффает чужие). Foundation: item_type='weapon', stat='damage'. Idempotent. utf8mb4.
 */
class W19CreateItemModifiersTable extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('item_modifiers')) {
            return;
        }

        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'character_id'     => ['type' => 'INT', 'constraint' => 11],
            'item_type'        => ['type' => 'VARCHAR', 'constraint' => 16],
            'item_instance_id' => ['type' => 'INT', 'constraint' => 11],
            'stat'             => ['type' => 'VARCHAR', 'constraint' => 32],
            'bonus_pct'        => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'created_at'       => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'updated_at'       => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['item_type', 'item_instance_id']);
        $this->forge->addKey('character_id');
        $this->forge->createTable('item_modifiers', false, [
            'ENGINE'  => 'InnoDB',
            'CHARSET' => 'utf8mb4',
            'COLLATE' => 'utf8mb4_unicode_ci',
        ]);
    }

    public function down(): void
    {
        $this->forge->dropTable('item_modifiers', true);
    }
}
