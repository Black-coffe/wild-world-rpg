<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Services\Crafting\RequiredResourcesParser;
use CodeIgniter\Database\Migration;

/**
 * S2 (v0.51.184) — нормализация `crafted_items.required_resources` в каноническую JSON-форму.
 *
 * Колонка исторически хранилась в 5 несовместимых форматах:
 *   1. Free-text v1a (newline):     "Травы - 2 шт.\nКора деревьев - 3 шт."
 *   2. Free-text v1b (comma):       "Wool - 4 шт., Leather - 2 шт."
 *   3. PHP-array-like v2:           "'Кактус' => 4,\n'Грибы' => 6,"
 *   4. JSON object v3 (целевой):    {"Травы": 2}
 *   5. JSON array v3-bis (legacy):  [{"resource_id": 1, "quantity": 2}]
 *
 * Данные read-only в runtime (используются только `CraftTreeService` для admin
 * visualization). `Config\CraftRecipes.php` — runtime source of truth.
 *
 * Эта миграция через `RequiredResourcesParser` нормализует ВСЕ строки в
 * формат #4. Idempotent: повторный прогон даст 0 изменений.
 */
class NormalizeCraftedItemsRequiredResourcesToJson extends Migration
{
    public function up(): void
    {
        $parser = new RequiredResourcesParser();
        $rows   = $this->db->table('crafted_items')
            ->select('id, required_resources')
            ->get()
            ->getResultArray();

        $converted   = 0;
        $skipped     = 0;
        $unparseable = 0;

        foreach ($rows as $row) {
            $id  = isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : 0;
            $raw = isset($row['required_resources']) && is_string($row['required_resources'])
                ? trim($row['required_resources'])
                : '';

            if ($id === 0) {
                continue;
            }

            // Empty stays empty — idempotent
            if ($raw === '' || $raw === '[]' || $raw === '{}') {
                $skipped++;
                continue;
            }

            $parsed = $parser->parse($raw);

            if (empty($parsed)) {
                log_message('warning',
                    "[NormalizeCraftedItemsRequiredResourcesToJson] crafted_items #{$id} "
                    . "required_resources unparseable: " . substr($raw, 0, 100));
                $unparseable++;
                continue;
            }

            $newJson = $parser->encode($parsed);

            // Idempotent: skip if already canonical JSON equal
            if ($newJson === $raw) {
                $skipped++;
                continue;
            }

            $this->db->table('crafted_items')
                ->where('id', $id)
                ->update(['required_resources' => $newJson]);
            $converted++;
        }

        log_message('info',
            "[NormalizeCraftedItemsRequiredResourcesToJson] converted={$converted}, "
            . "skipped={$skipped}, unparseable={$unparseable}, total=" . count($rows));
    }

    public function down(): void
    {
        // No-op: невозможно надёжно восстановить v1/v2 формат из JSON.
        // Idempotent re-runs of up() безопасны — поэтому down() здесь rollback не делает.
    }
}
