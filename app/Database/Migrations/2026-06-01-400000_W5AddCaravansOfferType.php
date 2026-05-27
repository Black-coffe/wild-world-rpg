<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W5 (ADR-064) — ALTER caravans для drone-offer support.
 *
 * Расширение V25 (ADR-057) single-offer-per-caravan архитектуры:
 *  - offer_type VARCHAR(32) NOT NULL DEFAULT 'resource' — дискриминатор типа offer'а
 *    ('resource' = legacy V25 / 'drone_scout' / 'drone_cargo' / 'drone_repair' /
 *    'drone_combat' — W5).
 *  - drone_type VARCHAR(32) NULL — name_eng драфт-дрона (DroneScout/DroneCargo/...).
 *    NULL для offer_type='resource' (backward compat).
 *  - gold_price INT NULL — цена за готовый дрон (recipe.gold × markup). NULL для resource.
 *
 * Legacy resource-offer'ы (status='active' на момент migration) остаются с
 * offer_type='resource' (default) — backward compat без UPDATE WHERE.
 *
 * CaravanModel::$allowedFields += offer_type/drone_type/gold_price.
 *
 * Idempotent через SHOW COLUMNS.
 */
class W5AddCaravansOfferType extends Migration
{
    public function up(): void
    {
        $cols = [];

        if (! $this->columnExists('caravans', 'offer_type')) {
            $cols['offer_type'] = [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'null'       => false,
                'default'    => 'resource',
                'after'      => 'status',
            ];
        }

        if (! $this->columnExists('caravans', 'drone_type')) {
            $cols['drone_type'] = [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'null'       => true,
                'default'    => null,
                'after'      => 'offer_type',
            ];
        }

        if (! $this->columnExists('caravans', 'gold_price')) {
            $cols['gold_price'] = [
                'type'    => 'INT',
                'null'    => true,
                'default' => null,
                'after'   => 'drone_type',
            ];
        }

        if ($cols !== []) {
            $this->forge->addColumn('caravans', $cols);
        }
    }

    public function down(): void
    {
        foreach (['gold_price', 'drone_type', 'offer_type'] as $col) {
            if ($this->columnExists('caravans', $col)) {
                $this->forge->dropColumn('caravans', $col);
            }
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        $fields = $this->db->getFieldNames($table);
        return is_array($fields) && in_array($column, $fields, true);
    }
}
