<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-085 Склад Фаза 1b — колонка `resources.weight` (kg/единица) + наполнение 78 ресурсов.
 *
 * 🔴 Снимает БЛОКЕР Фазы 1a (найден Tier-3): `WeightCapacityService::getCurrentLoad()`
 * делает `SUM(r.weight × cr.quantity)`, но колонки `resources.weight` НЕ существовало →
 * активация weight-cap = DatabaseException. Эта миграция добавляет колонку → активация
 * становится возможна (но остаётся dormant: killswitch `inventory.weight_cap.enabled`=false).
 *
 * **Веса — dormant defaults** (kg/единица), схема по `type`-категории. Малые значения,
 * чтобы cap (формула l1_base+level×per_level + Склад-бонус) был осмыслен, но не абсурдно
 * тесен. **Калибровка per-resource — в activation balance-pass** (по реальным инвентарям;
 * хордеры П8 grandfather'ятся — идущий инвентарь сохраняется, гейтятся только НОВЫЕ добавления).
 *
 * Weight — data-атрибут ресурса (как sell_price column), не GameSettings-knob; cap-формула
 * (l1_base/per_level/warehouse_bonus) уже live-tunable в GameSettings (W3a/ADR-085).
 */
class ADR085WarehousePhase1bResourceWeight extends Migration
{
    public function up(): void
    {
        if (! $this->db->fieldExists('weight', 'resources')) {
            $this->forge->addColumn('resources', [
                'weight' => [
                    'type'       => 'DECIMAL',
                    'constraint' => '6,2',
                    'null'       => false,
                    'default'    => 0.00,
                    'after'      => 'rarity',
                ],
            ]);
        }

        // Схема весов (kg/единица) по type-категории. Порядок CASE важен:
        // material (объёмное сырьё) > food > seed > rare (компактное) > прочее (crafting).
        $this->db->query(
            "UPDATE resources SET weight = CASE
                WHEN type LIKE '%material%' THEN 1.00
                WHEN type LIKE '%food%'     THEN 0.30
                WHEN type LIKE '%seed%'     THEN 0.05
                WHEN type LIKE '%rare%'     THEN 0.20
                ELSE 0.50
             END
             WHERE weight = 0 OR weight IS NULL"
        );
    }

    public function down(): void
    {
        if ($this->db->fieldExists('weight', 'resources')) {
            $this->forge->dropColumn('resources', 'weight');
        }
    }
}
