<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-101 Фаза 0 — ростер жителей поселения (settlement_npcs).
 *
 * Связь поселение↔NPC: какие именные/фракционные/нейтральные NPC «живут» в поселении и в какой
 * роли. role: mayor (мэр-лидер) / vendor (торговец) / guard (стража) / quest (квестгивер) /
 * service (услуга). service_key (для vendor/service): trade|repair|casino|recycle|faction_project.
 * SettlementPlacementHandler держит живой спавн каждого жителя на anchor_cell поселения.
 *
 * WipeManifest: settlement_npcs = KEEP (ростер — контент-определение). Классифицировано.
 */
class CreateSettlementNpcsTable extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('settlement_npcs')) {
            return;
        }
        $this->forge->addField([
            'id'            => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'settlement_id' => ['type' => 'INT', 'unsigned' => true, 'comment' => 'FK settlements.id'],
            'npc_id'        => ['type' => 'INT', 'unsigned' => true, 'comment' => 'FK npcs.id'],
            'role'          => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'vendor', 'comment' => 'mayor|vendor|guard|quest|service'],
            'service_key'   => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true, 'comment' => 'trade|repair|casino|recycle|faction_project'],
            'sort_order'    => ['type' => 'INT', 'default' => 0],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['settlement_id', 'npc_id']);
        $this->forge->addKey('settlement_id');
        $this->forge->createTable('settlement_npcs');
    }

    public function down(): void
    {
        $this->forge->dropTable('settlement_npcs', true);
    }
}
