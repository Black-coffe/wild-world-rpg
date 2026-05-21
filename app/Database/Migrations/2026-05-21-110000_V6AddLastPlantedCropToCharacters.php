<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * V6 (ADR-033) — rotation-memory для севооборота.
 *
 * `characters.last_planted_crop` (nullable VARCHAR) хранит ключ последней
 * посаженной культуры (berries/mushrooms/fruit/crops). При старте посадки:
 * crop != last_planted_crop && farming.rotation.enabled → бонус к урожаю.
 *
 * Минимальная per-character rotation-память (детерминир., O(1) lookup,
 * тестируемо). Альтернатива «вывести из истории тасков» отклонена в ADR
 * (heavier query, неоднозначная семантика).
 */
class V6AddLastPlantedCropToCharacters extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('characters', [
            'last_planted_crop' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'null'       => true,
                'default'    => null,
                'comment'    => 'V6 (ADR-033): ключ последней посаженной культуры (berries/mushrooms/fruit/crops) для rotation-бонуса',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('characters', 'last_planted_crop');
    }
}
