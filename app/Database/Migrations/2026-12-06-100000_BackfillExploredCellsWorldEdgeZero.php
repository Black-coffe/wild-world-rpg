<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Бэкфилл тумана войны на краю мира X=0 / Y=0 (баг-репорт 03.09.2026 «ордината 0 не отображается»).
 *
 * `ExploredCellsModel::revealAround()` зажимал окно раскрытия в 1..1000, а сетка мира — 0..999.
 * Ряд Y=0 и столбец X=0 не раскрывались никогда, даже когда игрок стоял на них: на карте они
 * оставались ⬛️. Код исправлен (зажим 0..999); эта миграция одноразово дораскрывает край тем,
 * кто уже ходил вдоль него: для каждой раскрытой клетки с X=1 или Y=1 добавляем её соседей
 * (по Чебышёву, радиус 1) на X=0 / Y=0, которых ещё нет у персонажа.
 *
 * Идемпотентно (LEFT JOIN … IS NULL). Схему не меняет — WipeManifest не трогаем.
 * down() = no-op: backfilled-клетку нельзя отличить от органически раскрытой.
 */
class BackfillExploredCellsWorldEdgeZero extends Migration
{
    public function up()
    {
        $db = \Config\Database::connect();

        $db->query(
            'INSERT INTO explored_cells
                (character_id, telegram_user_id, map_cell_id, biome_id, character_level, created_at, updated_at)
             SELECT ec.character_id, MAX(ec.telegram_user_id), m2.cell_number, MAX(m2.biome_id),
                    MAX(ec.character_level), NOW(), NOW()
             FROM explored_cells ec
             JOIN map m1 ON m1.cell_number = ec.map_cell_id
                        AND (m1.coordinate_x = 1 OR m1.coordinate_y = 1)
             JOIN map m2 ON (m2.coordinate_x = 0 OR m2.coordinate_y = 0)
                        AND m2.coordinate_x BETWEEN m1.coordinate_x - 1 AND m1.coordinate_x + 1
                        AND m2.coordinate_y BETWEEN m1.coordinate_y - 1 AND m1.coordinate_y + 1
             LEFT JOIN explored_cells ex ON ex.character_id = ec.character_id AND ex.map_cell_id = m2.cell_number
             WHERE ex.id IS NULL
             GROUP BY ec.character_id, m2.cell_number'
        );
    }

    public function down()
    {
        // Бэкфилл необратим — backfilled-клетки неотличимы от органически раскрытых.
    }
}
