<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * v0.51.45 — 3 micro-індекси з Performance-DB audit (§11+§12+§13).
 *
 * Аудит 2026-05-04 стверджував що індекси на map.cell_number додані 2024-09-26,
 * але SHOW INDEX FROM map на проді 2026-05-07 показав їх відсутність. Outdated
 * note. Додаємо тепер.
 *
 * 1) UNIQUE uk_map_cell_number — data integrity guard (одна ячейка = один номер).
 *    Verified 0 duplicates на проді 2026-05-07. cell_number NOT NULL — safe.
 *    Speeds up `mapModel->where('cell_number', X)->first()` — найгарячіший
 *    map lookup pattern (десятки handler'ів).
 *
 * 2) idx_map_biome — FK lookup, used у GatherTaskHandler::getAvailableResources
 *    (`like('biome_id', csv, 'both')`) та інших біом-orientованих queries.
 *
 * 3) idx_explored_char_cell — composite (character_id, map_cell_id) для
 *    ExplorationTaskHandler::countAllResults() (per Performance-DB §13).
 *    Раніше MySQL вибирав один із FK single-column indexes — composite
 *    задовольняє обидва predicates напряму.
 *
 * map = 1M rows — UNIQUE build займає кілька секунд через InnoDB. Migration
 * applies online (modern MySQL). На проді low-traffic вікно — fine.
 */
class AddPerformanceMicroIndexes extends Migration
{
    public function up()
    {
        $db = \Config\Database::connect();

        // §11 — UNIQUE on map.cell_number (1M rows, NOT NULL, deterministic)
        $db->query('CREATE UNIQUE INDEX uk_map_cell_number ON map(cell_number)');

        // §12 — INDEX on map.biome_id (FK lookup)
        $db->query('CREATE INDEX idx_map_biome ON map(biome_id)');

        // §13 — composite INDEX on explored_cells(character_id, map_cell_id)
        $db->query('CREATE INDEX idx_explored_char_cell ON explored_cells(character_id, map_cell_id)');
    }

    public function down()
    {
        $db = \Config\Database::connect();

        $db->query('DROP INDEX uk_map_cell_number ON map');
        $db->query('DROP INDEX idx_map_biome ON map');
        $db->query('DROP INDEX idx_explored_char_cell ON explored_cells');
    }
}
