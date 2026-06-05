<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-101 Фаза 0 — статические NPC-поселения (Rust-style: аутпост/логово/оплоты/руины).
 *
 * Поселение = постоянное именованное место на КУРИРУЕМОЙ якорной клетке (не рандом). type:
 * outpost (нейтральный safe-хаб) / bandit (враждебный) / faction (оплот фракции) / ruins
 * (охраняемые руины). zone_policy: safe (нет PvP/нельзя напасть на жителей) / hostile / neutral.
 * faction_id — для оплотов; enter_min_standing — порог репутации безопасного входа.
 *
 * Размещение жителей — SettlementPlacementHandler (gated settlements.enabled). Спавны жителей в
 * npc_spawns (TRANSIENT). Само поселение и ростер — KEEP (мировой контент). enabled=0 dormant.
 *
 * WipeManifest: settlements = KEEP. Классифицировано.
 */
class CreateSettlementsTable extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('settlements')) {
            return;
        }
        $this->forge->addField([
            'id'                 => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'code'               => ['type' => 'VARCHAR', 'constraint' => 64, 'comment' => 'Машинный ключ (crossroads_outpost)'],
            'name_ru'            => ['type' => 'VARCHAR', 'constraint' => 120],
            'type'               => ['type' => 'VARCHAR', 'constraint' => 16, 'comment' => 'outpost|bandit|faction|ruins'],
            'anchor_cell'        => ['type' => 'INT', 'null' => true, 'comment' => 'FK map.cell_number — курируемая якорная клетка'],
            'coordinate_x'       => ['type' => 'INT', 'null' => true],
            'coordinate_y'       => ['type' => 'INT', 'null' => true],
            'faction_id'         => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'comment' => 'FK factions (оплоты)'],
            'zone_policy'        => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'neutral', 'comment' => 'safe|hostile|neutral'],
            'enter_min_standing' => ['type' => 'INT', 'null' => true, 'comment' => 'Порог репутации безопасного входа (оплоты)'],
            'icon'               => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true, 'comment' => 'emoji для карты'],
            'description_ru'     => ['type' => 'TEXT', 'null' => true],
            'enabled'            => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'comment' => '0 = dormant (не размещается/не видно)'],
            'created_at'         => ['type' => 'DATETIME', 'null' => true],
            'updated_at'         => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('code');
        $this->forge->addKey('anchor_cell');
        $this->forge->createTable('settlements');
    }

    public function down(): void
    {
        $this->forge->dropTable('settlements', true);
    }
}
