<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-133 — «🎲 Оракул острова»: золотой пари-мютюэль рынок предсказаний.
 *
 * 3 таблицы (классификация — Config\WipeManifest):
 *  - oracle_markets  (TRANSIENT)   — экземпляры рынков за цикл (недельный/дневной), регенерируются кроном.
 *  - oracle_outcomes (TRANSIENT)   — исходы рынка (фракции / yes-no), привязаны к market_id.
 *  - oracle_bets     (PLAYER_DATA) — ставки игроков (эскроу-дебет золота при размещении).
 *
 * Движок (OracleSettlementHandler) — зеркало PvpLadderWeeklyBroadcastHandler. Всё под
 * killswitch oracle.enabled (dormant). utf8mb4 (вопросы/метки с emoji/кириллицей).
 */
class Adr133CreateOracleMarkets extends Migration
{
    public function up(): void
    {
        // oracle_markets (TRANSIENT) — один экземпляр на (market_key, cycle).
        $this->forge->addField([
            'id'              => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'market_key'      => ['type' => 'VARCHAR', 'constraint' => 64],                                  // стабильный ключ типа рынка: faction_race / event_today
            'market_type'     => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'weekly'],           // weekly | daily
            'question'        => ['type' => 'VARCHAR', 'constraint' => 512, 'default' => ''],
            'metric_key'      => ['type' => 'VARCHAR', 'constraint' => 64, 'default' => ''],                  // какой резолвер исхода
            'cycle'           => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => ''],                  // o-W (неделя) / Y-m-d (день)
            'status'          => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'open'],              // open | locked | settled | void
            'rake_pct'        => ['type' => 'DECIMAL', 'constraint' => '5,4', 'default' => 0.0800],           // снапшот рейка на момент создания
            'snapshot_start'  => ['type' => 'TEXT', 'null' => true],                                          // JSON стартового агрегата (для дельты)
            'snapshot_final'  => ['type' => 'TEXT', 'null' => true],                                          // JSON финального агрегата
            'winning_outcome' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'opens_at'        => ['type' => 'DATETIME', 'null' => true],
            'locks_at'        => ['type' => 'DATETIME', 'null' => true],
            'resolves_at'     => ['type' => 'DATETIME', 'null' => true],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
            'updated_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['market_key', 'cycle']);
        $this->forge->addKey('status');
        $this->forge->createTable('oracle_markets', true, ['ENGINE' => 'InnoDB', 'DEFAULT CHARSET' => 'utf8mb4']);

        // oracle_outcomes (TRANSIENT) — исходы рынка.
        $this->forge->addField([
            'id'        => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'market_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'code'      => ['type' => 'VARCHAR', 'constraint' => 64],
            'label'     => ['type' => 'VARCHAR', 'constraint' => 128, 'default' => ''],
            'sort_order' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['market_id', 'code']);
        $this->forge->addKey('market_id');
        $this->forge->createTable('oracle_outcomes', true, ['ENGINE' => 'InnoDB', 'DEFAULT CHARSET' => 'utf8mb4']);

        // oracle_bets (PLAYER_DATA) — ставки игроков. Эскроу-дебет золота при размещении.
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'market_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'character_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'outcome_code' => ['type' => 'VARCHAR', 'constraint' => 64],
            'stake'        => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'status'       => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'placed'], // placed | won | lost | refunded
            'payout'       => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
            'resolved_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('market_id');
        $this->forge->addKey('character_id');
        $this->forge->createTable('oracle_bets', true, ['ENGINE' => 'InnoDB', 'DEFAULT CHARSET' => 'utf8mb4']);
    }

    public function down(): void
    {
        $this->forge->dropTable('oracle_bets', true);
        $this->forge->dropTable('oracle_outcomes', true);
        $this->forge->dropTable('oracle_markets', true);
    }
}
