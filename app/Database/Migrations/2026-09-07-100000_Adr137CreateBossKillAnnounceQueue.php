<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * WB11 (ADR-137 «Узлы») — очередь анонсов о повергнутых узлах.
 *
 * Анонс на КАЖДЫЙ килл синхронным broadcast'ом был ОТРЕЗАН (ADR-137 §0.3: self-DoS канала при ~147
 * достижимых + cron-блок). Выживший v1 — **дайджест раз в сутки**: kill-путь складывает квалифицирующий
 * килл (`boss_level ≥ world.nodes.announce_min_level`) сюда, NodeAnnounceDigestHandler раз в день собирает
 * ОДНУ обезличенную «Сводку с пустоши» (радист Корвин: «пал босс L{N} в районе [биом]» — без точных X,Y/ника,
 * анти-PvP-наводка) и шлёт opted-in игрокам, после чего строки потребляются.
 *
 * WipeManifest (ADR-087): boss_kill_announce_queue = **TRANSIENT** — очередь, наполняется заново. Без
 * player-связи (агрегат события мира). Классификация добавляется в Config\WipeManifest этой же сессией.
 */
class Adr137CreateBossKillAnnounceQueue extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'            => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'boss_point_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'boss_level'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'biome_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'status'        => ['type' => 'ENUM', 'constraint' => ['pending', 'sent'], 'default' => 'pending'],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['status', 'created_at']);
        $this->forge->createTable('boss_kill_announce_queue', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('boss_kill_announce_queue', true);
    }
}
