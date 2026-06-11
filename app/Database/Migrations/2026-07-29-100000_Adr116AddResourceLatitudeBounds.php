<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-116 (E16) — широтные границы ресурса (min_y/max_y) для эксклюзивных ресурсов пояса.
 *
 * Ресурс с min_y/max_y добывается ТОЛЬКО в клетках coordinate_y ∈ [min_y, max_y) (поверх биом-гейта).
 * NULL = без широтного гейта (все существующие ресурсы → поведение byte-identical). Гейтинг —
 * в GatherCellResourceQuery (читает cell.coordinate_y). resources = KEEP (контент), min_y/max_y —
 * контент-атрибуты, не player-данные → WipeManifest не затрагивается.
 */
class Adr116AddResourceLatitudeBounds extends Migration
{
    public function up(): void
    {
        $fields = [];
        if (! $this->db->fieldExists('min_y', 'resources')) {
            $fields['min_y'] = ['type' => 'INT', 'null' => true, 'after' => 'level_required'];
        }
        if (! $this->db->fieldExists('max_y', 'resources')) {
            $fields['max_y'] = ['type' => 'INT', 'null' => true, 'after' => 'min_y'];
        }
        if ($fields !== []) {
            $this->forge->addColumn('resources', $fields);
        }
    }

    public function down(): void
    {
        foreach (['min_y', 'max_y'] as $col) {
            if ($this->db->fieldExists($col, 'resources')) {
                $this->forge->dropColumn('resources', $col);
            }
        }
    }
}
