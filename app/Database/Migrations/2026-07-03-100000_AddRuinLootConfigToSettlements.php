<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-101 Фаза 4 — колонка loot_config для охраняемых руин.
 *
 * JSON-таблица повторяемого лута руины: {"resources":{"name_en":maxAmount,...}}. Только для
 * type=ruins; прочие поселения оставляют NULL. Мировой контент (settlements=KEEP в WipeManifest) —
 * НЕ player-колонка, классификация не требуется.
 */
class AddRuinLootConfigToSettlements extends Migration
{
    public function up(): void
    {
        if (! $this->db->fieldExists('loot_config', 'settlements')) {
            $this->forge->addColumn('settlements', [
                'loot_config' => [
                    'type'    => 'TEXT',
                    'null'    => true,
                    'after'   => 'description_ru',
                    'comment' => 'JSON loot-таблица повторяемого лута руины (type=ruins)',
                ],
            ]);
        }
    }

    public function down(): void
    {
        if ($this->db->fieldExists('loot_config', 'settlements')) {
            $this->forge->dropColumn('settlements', 'loot_config');
        }
    }
}
