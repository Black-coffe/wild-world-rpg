<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W15 (vNext2, Фаза 3, ADR-069) — faction-aligned caravans: колонка `faction_id`.
 *
 * Аффилиация каравана к фракции (1-4). NULL = нейтральный караван (V25/W5/W14 поведение,
 * 0 регрессии). Эффект (скидка члену / наценка ривалу) считается в look/buy по фракции
 * игрока. Idempotent.
 */
class W15AddCaravanFactionId extends Migration
{
    public function up(): void
    {
        if (! $this->db->fieldExists('faction_id', 'caravans')) {
            $this->forge->addColumn('caravans', [
                'faction_id' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'null'       => true,
                    'after'      => 'caravan_group_id',
                ],
            ]);
        }
    }

    public function down(): void
    {
        if ($this->db->fieldExists('faction_id', 'caravans')) {
            $this->forge->dropColumn('caravans', 'faction_id');
        }
    }
}
