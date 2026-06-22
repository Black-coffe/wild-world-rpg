<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * WB6 (ADR-137 «Узлы») — таблица активных боёв игрока с узлом (state-machine).
 *
 * Многоходовый бой: тап игрока = чанк `combat.nodes.rounds_per_tap` раундов (BossEncounterService
 * через детерминированный DamageService). HP узла персистентен в `boss_points.current_health`
 * (фундамент со-опа WB8); HP игрока на время боя — `player_hp` (снапшот с characters.health на
 * старте, пишется обратно на терминальном исходе). Один активный бой на персонажа.
 *
 * `last_special_round_no` (сверх базового DDL ADR §4) — для отката спецприёма
 * (`combat.nodes.special_cooldown_rounds`); `last_action_at` — для GC-крона (stale active → fled).
 *
 * WipeManifest (ADR-087): boss_encounters = **PLAYER_DATA** (by character) — активный прогресс
 * игрока, новый сезон с нуля. Классификация добавляется в Config\WipeManifest этой же сессией.
 */
class Adr137CreateBossEncounters extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'boss_point_id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'character_id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'round_no'              => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'player_hp'             => ['type' => 'INT', 'constraint' => 11], // HP игрока на время боя (может уйти в 0)
            'status'                => ['type' => 'ENUM', 'constraint' => ['active', 'won', 'fled', 'lost'], 'default' => 'active'],
            'damage_dealt'          => ['type' => 'INT', 'constraint' => 11, 'default' => 0], // суммарный урон игрока по узлу (ledger WB8)
            'last_special_round_no' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0], // откат спецприёма
            'last_action_at'        => ['type' => 'DATETIME', 'null' => true], // GC stale → fled
            'created_at'            => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('boss_point_id');
        $this->forge->addKey(['character_id', 'status']);
        $this->forge->addKey(['status', 'last_action_at']);
        $this->forge->createTable('boss_encounters', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('boss_encounters', true);
    }
}
