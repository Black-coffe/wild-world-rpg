<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * V24 (ADR-056) — флаг `insured` в crafted_items_log для селективной страховки
 * дорогих предметов (robots/workbench/transport). Pre-paid вечный полис: игрок
 * платит gold за конкретную строку лога, при смерти эта строка пропускается в
 * `LootProcessor::computeCraftLoss`. Идемпотентно.
 */
class V24AddInsuredToCraftedItemsLog extends Migration
{
    public function up(): void
    {
        if (! $this->db->fieldExists('insured', 'crafted_items_log')) {
            $this->forge->addColumn('crafted_items_log', [
                'insured' => [
                    'type'    => 'TINYINT',
                    'default' => 0,
                    'null'    => false,
                    'after'   => 'quantity',
                ],
            ]);
        }
    }

    public function down(): void
    {
        if ($this->db->fieldExists('insured', 'crafted_items_log')) {
            $this->forge->dropColumn('crafted_items_log', 'insured');
        }
    }
}
