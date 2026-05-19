<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S13a (v0.51.195) — seed GameSettings keys для Laboratory level effects (ROADMAP-CRAFT §6).
 *
 * До S13a Laboratory level = pure cosmetic (handler `LaboratoryHandler.php` —
 * info-action; `buildings.effects = "research_and_development"` — dead string).
 *
 * Pattern reuse S11 Workshop (`building.<name>.l<N>.craft_time_multiplier`).
 * Семантика — bonus для **medical** recipes (zone_name='медицина'): Bandage,
 * Antiseptic, PainReliefPower та інші 5. Recipe декларує `boost_building_time
 * => 'Laboratory'`. Service stack'ить multipliers: Workshop × Laboratory.
 *
 * L4-L10 cascade на L3 multiplier (plateau, як S11/S12).
 *
 * Constitutional rule (CLAUDE.md §🎛️ ADR-024): rationale/effect/above/below NOT NULL.
 * Idempotent: пропускає існуючі setting_key.
 */
class S13aSeedLaboratoryGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'building.laboratory.l2.craft_time_multiplier',
                'category'           => 'buildings',
                'value_type'         => 'float',
                'value_float'        => 0.90,
                'default_value_text' => '0.90',
                'rationale_text'     => '-10% часу медичного крафту на L2 — стимулює гравців з фокусом на survival/healing (П2/П5/П10) будувати Laboratory. Stack з Workshop (Workshop L2 + Lab L2 = 0.90 × 0.90 = 0.81, -19% для медичних).',
                'effect_text'        => 'Множник craft duration для recipes з `boost_building_time => Laboratory` у GenericCraftActionStart::calculateCraftingDuration(). Multiplicative з Workshop (stacking). На сьогодні застосовується до 8 medical recipes (zone_name=медицина).',
                'above_effect_text'  => 'При 0.95 — ефект 5% непомітний для Bandage (3-4 хв → 3-4 хв, округлення), L2 апгрейд не виправданий.',
                'below_effect_text'  => 'При 0.70 — L2 сильніше за S11 Workshop L3 (-25%), Lab L3 (-25% планується) втрачає сенс прогресії.',
                'recommended_min'    => '0.80',
                'recommended_max'    => '0.95',
                'hard_min'           => '0.10',
                'hard_max'           => '1.00',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'building.laboratory.l3.craft_time_multiplier',
                'category'           => 'buildings',
                'value_type'         => 'float',
                'value_float'        => 0.75,
                'default_value_text' => '0.75',
                'rationale_text'     => '-25% часу медичного крафту на L3 — крупна винагорода для healing-фокусних гравців. Stack з Workshop L3: 0.75 × 0.75 = 0.5625 (-44% для медичних). L4-L10 plateau cascade.',
                'effect_text'        => 'Множник craft duration для medical recipes. Workshop L3 + Lab L3 → craft duration = base × 0.75 × 0.75 = 0.5625 × base. Min clamp 1 хв.',
                'above_effect_text'  => 'При 0.85 — L3 = +5% над L2, погана прогресія, гравці не відчувають крупний апгрейд.',
                'below_effect_text'  => 'При 0.50 — медичний крафт стає near-instant зі Workshop + Lab stack (0.50 × 0.75 = 0.375, -62%), ламає healing economy.',
                'recommended_min'    => '0.65',
                'recommended_max'    => '0.85',
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
                'building.laboratory.l2.craft_time_multiplier',
                'building.laboratory.l3.craft_time_multiplier',
            ])
            ->delete();
    }
}
