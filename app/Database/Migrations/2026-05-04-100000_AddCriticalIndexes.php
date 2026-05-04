<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * F0.4 — критичные индексы для hot-path Worker'а и боя.
 *
 * Решает узкие места, найденные аудитом 2026-05-04:
 *  - Worker.processTasks() делает full scan character_tasks WHERE status=? AND end_time<?
 *  - GatherTaskHandler делает full scan character_resources WHERE id_characters=? AND id_resources=?
 *  - PlayerDetectionService и логика инструментов делают полный обход crafted_items_log по character_id
 *
 * См. mmorpg-vault/lore/refactor/Performance-DB.md и ADR-012 (Phase A).
 */
class AddCriticalIndexes extends Migration
{
    public function up()
    {
        $db = \Config\Database::connect();

        // character_tasks — hot-path Worker'а
        $db->query('CREATE INDEX idx_ct_status_endtime ON character_tasks(status, end_time)');
        $db->query('CREATE INDEX idx_ct_character_status ON character_tasks(character_id, status)');

        // character_resources — saveFoundResources / lookup ресурса игрока
        $db->query('CREATE INDEX idx_cr_char_resource ON character_resources(id_characters, id_resources)');
        $db->query('CREATE INDEX idx_cr_character ON character_resources(id_characters)');

        // crafted_items_log — getItemByNameEngAndCharacterId, инвентарь, прочность инструментов
        $db->query('CREATE INDEX idx_cil_character ON crafted_items_log(character_id)');
    }

    public function down()
    {
        $db = \Config\Database::connect();

        $db->query('DROP INDEX idx_ct_status_endtime ON character_tasks');
        $db->query('DROP INDEX idx_ct_character_status ON character_tasks');
        $db->query('DROP INDEX idx_cr_char_resource ON character_resources');
        $db->query('DROP INDEX idx_cr_character ON character_resources');
        $db->query('DROP INDEX idx_cil_character ON crafted_items_log');
    }
}
