<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-186 — окно противостояния перед полевым PvP у базы.
 *
 * Таблица `pvp_standoffs` (PLAYER_DATA — Config\WipeManifest, линк attacker_id/defender_id):
 * снимок открытого/закрытого окна между атакующим и защитником на клетке базы. Защитник
 * получает `window_sec` (default 300 с, `pvp.standoff.window_sec`) на реакцию — укрыться,
 * убежать, встретить удар первым; по истечении атакующий может ударить без ожидания.
 *
 * Физическая невозможность двух открытых окон на одного защитника — не PHP-проверка
 * (гонка двух атакующих в один момент), а БД-инвариант: генерируемая колонка
 * `open_defender_id` равна `defender_id` только при `status='open'`, и `UNIQUE` по ней
 * не даёт вставить вторую такую строку. Закрытые окна (`fled`/`countered`/`held`/`expired`/
 * `cancelled`) не участвуют — колонка для них NULL, а NULL в UNIQUE не конфликтует.
 *
 * Код, который читает/пишет эту таблицу (`PvpStandoffService`, `StandoffNotifier`), — story
 * `-06`. Здесь — только фундамент.
 */
class Adr186CreatePvpStandoffs extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'attacker_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'defender_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'cell_number' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'started_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'expires_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['open', 'fled', 'countered', 'held', 'expired', 'cancelled'],
                'default'    => 'open',
            ],
            'notified_expired' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['attacker_id', 'status']);
        $this->forge->addKey(['defender_id', 'status']);
        $this->forge->addKey(['status', 'expires_at']);
        $this->forge->createTable('pvp_standoffs', true, ['ENGINE' => 'InnoDB', 'DEFAULT CHARSET' => 'utf8mb4']);

        // Forge не умеет генерируемые колонки — добавляем и уникализируем сырым SQL.
        $this->db->query(
            "ALTER TABLE `pvp_standoffs` ADD COLUMN `open_defender_id` INT UNSIGNED "
            . "GENERATED ALWAYS AS (IF(`status` = 'open', `defender_id`, NULL)) STORED"
        );
        $this->db->query(
            'ALTER TABLE `pvp_standoffs` ADD UNIQUE KEY `uq_pvp_standoffs_open_defender` (`open_defender_id`)'
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('pvp_standoffs', true);
    }
}
