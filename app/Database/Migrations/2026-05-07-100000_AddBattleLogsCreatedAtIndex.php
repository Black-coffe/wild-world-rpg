<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * v0.51.43 — index на battle_logs(created_at) для efficient TTL cleanup.
 *
 * battle_logs мав тільки PRIMARY KEY на id. На 2026-05-07 = 65,928 рядків
 * (102 distinct days, ~646 fights/day average, longtext blobs у log_data).
 * 23,372 рядки старше 90 днів — кандидат на DELETE через battles:cleanup.
 *
 * Без index'а DELETE WHERE created_at < ? = full table scan на longtext-heavy
 * таблиці. З index'ом — range scan + targeted delete.
 *
 * См. lore/refactor/Performance-DB.md (corrected 2026-05-07 — старі §7+§8 застаріли).
 */
class AddBattleLogsCreatedAtIndex extends Migration
{
    public function up()
    {
        $db = \Config\Database::connect();
        $db->query('CREATE INDEX idx_bl_created_at ON battle_logs(created_at)');
    }

    public function down()
    {
        $db = \Config\Database::connect();
        $db->query('DROP INDEX idx_bl_created_at ON battle_logs');
    }
}
