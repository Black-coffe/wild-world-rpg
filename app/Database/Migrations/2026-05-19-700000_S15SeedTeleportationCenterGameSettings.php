<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S15 (v0.51.197) — seed GameSettings keys для TeleportationCenter cost reduction
 * (ROADMAP-CRAFT §6, pure-multiplier scope; closes Phase 3 5/5).
 *
 * **Audit drift (2026-05-19).** ROADMAP описав «Центр L2/L3 = больше доступных
 * маяков одновременно (5/8 от base 3)» — реальність інша. `BeaconPlacementValidator:132-134`
 * вже скейлить ліміт: `min(10, intdiv($playerLevel, 10)) + max(1, min(10, $buildingLevel))`.
 * L1=+1, L2=+2, ..., L10=+10. ROADMAP numbers фейкові. **Beacon-ліміт не зачіпається.**
 *
 * **Реальний gap** — cost reduction. `TeleportCostService::calculateTeleportCost`
 * = `1000 × character.level` без врахування TC level. L2/L3 TeleportationCenter
 * на проде досі тільки розширює beacon limit, але cost не зменшує. ROADMAP
 * claim «снижение стоимости телепортации» = genuine gap, зачиняємо.
 *
 * **На проде**: 5 чарів з TeleportationCenter (2×L1, 1×L2, 2×L3). L2 → -15%,
 * L3 → -30%. Чар з level 20 + TC L3 платить 14000 замість 20000 за teleport.
 *
 * Constitutional rule (CLAUDE.md §🎛️ ADR-024): rationale/effect/above/below NOT NULL.
 * Idempotent: пропускає існуючі setting_key.
 */
class S15SeedTeleportationCenterGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'building.teleportationcenter.l2.teleport_cost_multiplier',
                'category'           => 'buildings',
                'value_type'         => 'float',
                'value_float'        => 0.85,
                'default_value_text' => '0.85',
                'rationale_text'     => '-15% gold-вартості telepor-команди на L2 TeleportationCenter — стимулює гравців-мобільників (П3 разведчик, П6 шахтар) вкладатися в L2 апгрейд. Без знижки L2 цінний лише для +1 beacon (з L1 +1 → L2 +2). Тепер L2 = і +1 beacon, і дешевший прыжок.',
                'effect_text'        => 'Множник вартості teleport_cost у TeleportCostService::calculateTeleportCost(level, charId) через BuildingEffectsService::getTeleportCostMultiplier(). Чар з level 10 + TC L2: cost = round(10000 × 0.85) = 8500 (замість 10000).',
                'above_effect_text'  => 'При 0.95 — ефект 5% непомітний (level 10 чар: 10000 → 9500, економія 500g за прыжок не варта L2 апгрейду).',
                'below_effect_text'  => 'При 0.65 — L2 сильніше за L3 (-30% планується), ламає прогресію L2 → L3.',
                'recommended_min'    => '0.75',
                'recommended_max'    => '0.90',
                'hard_min'           => '0.10',
                'hard_max'           => '1.00',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'building.teleportationcenter.l3.teleport_cost_multiplier',
                'category'           => 'buildings',
                'value_type'         => 'float',
                'value_float'        => 0.70,
                'default_value_text' => '0.70',
                'rationale_text'     => '-30% gold-вартості teleport на L3 TeleportationCenter — крупна винагорода для PvP-mobile грави (П7 PvP-боець, П4 фракційник). Чар з level 20 + TC L3: cost = round(20000 × 0.70) = 14000 замість 20000 (-6000g за прыжок, відчутно). L4-L10 plateau cascade.',
                'effect_text'        => 'Множник teleport cost для гравців з TC L3+. Apply: TeleportCostService::calculateTeleportCost(level, charId) пропускає cascade lookup.',
                'above_effect_text'  => 'При 0.80 — L3 = -20%, лише на 5% краще за L2 (-15%), погана прогресія між L2 і L3.',
                'below_effect_text'  => 'При 0.50 — teleport стає majority-free для high-level чарів (level 20 → 10000g замість 20000), ламає gold sink і PvP-cost economy.',
                'recommended_min'    => '0.55',
                'recommended_max'    => '0.80',
                'hard_min'           => '0.10',
                'hard_max'           => '1.00',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
        ];

        foreach ($rows as $row) {
            $existing = $this->db->table('game_settings')
                ->where('setting_key', $row['setting_key'])
                ->get()
                ->getRowArray();
            if (! empty($existing)) {
                continue; // idempotent
            }
            $this->db->table('game_settings')->insert($row);
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->whereIn('setting_key', [
                'building.teleportationcenter.l2.teleport_cost_multiplier',
                'building.teleportationcenter.l3.teleport_cost_multiplier',
            ])
            ->delete();
    }
}
