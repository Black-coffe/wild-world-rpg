<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W18 (ADR-072) — таблица PvP-ладдера: одна строка на персонажа (cumulative + weekly).
 *
 * Источник (ADR-072 audit-decision): дуэли W17 + PvP-атаки (post-combat scoring ВНЕ simulateFight).
 * `points` — all-time cumulative; `week_*` — текущая неделя (сбрасывается weekly-cron'ом после broadcast).
 * `faction_id` — снапшот фракции при записи (global = все, per-faction = фильтр; переживает смену стороны).
 * Idempotent (ifNotExists). utf8mb4 для консистентности.
 */
class W18CreatePvpLadderTable extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('pvp_ladder')) {
            return;
        }

        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'character_id' => ['type' => 'INT', 'constraint' => 11],
            'faction_id'   => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'duel_wins'    => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'duel_losses'  => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'duel_draws'   => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'pvp_wins'     => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'pvp_losses'   => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'points'       => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'week_points'  => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'week_wins'    => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('character_id');
        $this->forge->addKey('faction_id');
        $this->forge->addKey('points');
        $this->forge->createTable('pvp_ladder', false, [
            'ENGINE'  => 'InnoDB',
            'CHARSET' => 'utf8mb4',
            'COLLATE' => 'utf8mb4_unicode_ci',
        ]);
    }

    public function down(): void
    {
        $this->forge->dropTable('pvp_ladder', true);
    }
}
