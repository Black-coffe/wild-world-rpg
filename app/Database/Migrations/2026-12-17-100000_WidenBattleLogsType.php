<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * w2-n7-combat-01 — журнал боёв: тип `DUEL` в `battle_logs`.
 *
 * `battle_type` заведена как VARCHAR(3) под 'PVE'/'PVP'. Дуэли арены (ADR-071/124) раньше нигде не
 * сохранялись; теперь они идут в журнал боёв строкой `DUEL` — четыре символа под STRICT не влезают,
 * INSERT падает целиком. Расширяем до VARCHAR(8) (запас под будущие типы, тот же NOT NULL).
 *
 * У `battle_logs` нет собственной `createTable`-миграции (таблица из дампа) — на пустой базе её может
 * не быть, тогда миграция ничего не делает.
 *
 * WipeManifest не трогаем: `battle_logs` уже PLAYER_DATA (`link => player1_id, player2_id`), новых
 * таблиц и колонок нет.
 */
class WidenBattleLogsType extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('battle_logs')) {
            return;
        }
        $this->db->query('ALTER TABLE `battle_logs` MODIFY `battle_type` VARCHAR(8) NOT NULL');
    }

    public function down(): void
    {
        if (! $this->db->tableExists('battle_logs')) {
            return;
        }
        // Строки дуэлей в VARCHAR(3) не помещаются — при откате их нет смысла хранить усечёнными.
        $this->db->query("DELETE FROM `battle_logs` WHERE CHAR_LENGTH(`battle_type`) > 3");
        $this->db->query('ALTER TABLE `battle_logs` MODIFY `battle_type` VARCHAR(3) NOT NULL');
    }
}
