<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-135 — «Трофейная подать»: PvP-доминирование → временный zero-sum налог с добычи.
 *
 * Таблица character_tributes (PLAYER_DATA — Config\WipeManifest, линк master_id/vassal_id):
 * активные и исторические отношения дани между игроками. Победитель (master_id),
 * доминировавший над жертвой (vassal_id) в PvP (N побед за окно И 0 поражений), получает
 * временный (duration_days) переток rate% ТОЛЬКО с raw-добычи жертвы, с дневным cap.
 *
 * Снятие (status): rematch (жертва победила хозяина), expired (авто-таймер),
 * bought_out (выкуп-burn), collusion_void (анти-сговор), admin.
 *
 * Всё под killswitch tribute.enabled=false (dormant до Фазы 5). Фаза 0 — только схема.
 */
class Adr135CreateCharacterTributes extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'master_id'            => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],               // победитель/сюзерен (characters.id)
            'vassal_id'            => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],               // обложенный данью (characters.id)
            'rate'                 => ['type' => 'DECIMAL', 'constraint' => '5,4', 'default' => 0.1000],        // снапшот ставки подати на момент создания
            'status'               => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'active'],        // active | rematch | expired | bought_out | collusion_void | admin
            'total_collected'      => ['type' => 'INT', 'constraint' => 11, 'default' => 0],                   // суммарно перетекло единиц ресурса (audit)
            'collected_today'      => ['type' => 'INT', 'constraint' => 11, 'default' => 0],                   // дневной аккумулятор для cap
            'collected_today_date' => ['type' => 'DATE', 'null' => true],                                      // дата аккумулятора (сброс при смене суток)
            'started_at'           => ['type' => 'DATETIME', 'null' => true],
            'expires_at'           => ['type' => 'DATETIME', 'null' => true],                                  // авто-снятие (started_at + duration_days)
            'lifted_by'            => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],               // rematch | timer | buyout | collusion | admin
            'lifted_at'            => ['type' => 'DATETIME', 'null' => true],
            'created_at'           => ['type' => 'DATETIME', 'null' => true],
            'updated_at'           => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['vassal_id', 'status']);   // hot-path: при добыче ищем active подать жертвы
        $this->forge->addKey(['master_id', 'status']);   // дашборд хозяина / лимит данников
        $this->forge->addKey('expires_at');              // крон авто-снятия по таймеру
        $this->forge->createTable('character_tributes', true, ['ENGINE' => 'InnoDB', 'DEFAULT CHARSET' => 'utf8mb4']);
    }

    public function down(): void
    {
        $this->forge->dropTable('character_tributes', true);
    }
}
