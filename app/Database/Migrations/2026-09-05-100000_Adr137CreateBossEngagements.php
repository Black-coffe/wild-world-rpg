<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * WB8 (ADR-137 «Узлы») — ledger вклада «Облавы» (co-op по урону).
 *
 * Узел держит ОБЩИЙ персистентный HP (`boss_points.current_health`): игрок A ослабил и отступил,
 * игрок B добил. Этот ledger фиксирует, кто сколько урона внёс по узлу за его текущую «жизнь»
 * (уровень), чтобы при килле разделить лут по вкладу (не last-hit ninja, не AFK-пассажиру).
 *
 * Атомарный инкремент `damage_dealt` через INSERT … ON DUPLICATE KEY UPDATE (UNIQUE-ключ
 * (boss_point_id, boss_level, character_id) — дедуп + апсёрт без TOCTOU при двойных тапах).
 * `boss_level` в ключе: эскалация уровня происходит при респавне (NodeRespawnHandler) → в пределах
 * одной жизни уровень постоянен, новая жизнь = новый boss_level = новые строки (старые не коллизят).
 * Строки потребляются при килле (BossLootService::distributeLoot удаляет) + GC по `last_hit_at`
 * (`combat.nodes.engagement_ttl_min`) для недобитых узлов.
 *
 * WipeManifest (ADR-087): boss_engagements = **PLAYER_DATA** (by character) — вклад игрока, новый
 * сезон с нуля. Классификация добавляется в Config\WipeManifest этой же сессией.
 */
class Adr137CreateBossEngagements extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'boss_point_id'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'boss_level'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true], // уровень узла на момент вклада
            'character_id'        => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'damage_dealt'        => ['type' => 'INT', 'constraint' => 11, 'default' => 0], // атомарный инкремент (field=field+delta)
            'rounds_participated' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'first_hit_at'        => ['type' => 'DATETIME', 'null' => true],
            'last_hit_at'         => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['boss_point_id', 'boss_level', 'character_id']); // дедуп + апсёрт-ключ
        $this->forge->addKey('character_id');
        $this->forge->addKey('last_hit_at'); // GC по TTL
        $this->forge->createTable('boss_engagements', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('boss_engagements', true);
    }
}
