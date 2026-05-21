<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * V7 (ROADMAP-CRAFT-vNext) — привязка активного земледелия (V6) к уровню теплицы.
 *
 * Pure-overlay поверх V6 (S11-S15 паттерн `building.<name>.l<N>.<param>`, default ×1.0,
 * 0 регрессии). BuildingEffectsService резолвит уровень Greenhouse → cascade-множитель:
 *  - `harvest_yield_multiplier` (КАЧЕСТВО): урожай активной посадки × (>1.0). Применяется
 *    в PlantCropCompletionHandler при сборе.
 *  - `grow_time_multiplier` (СКОРОСТЬ/scheduling): время роста × (<1.0). Применяется
 *    в PlantCropActionStart при посадке.
 *
 * L2/L3 определены, L4-L10 — plateau cascade на L3 (как S11/S12/S13/S15). Прод-аудит
 * 2026-05-21: 5 теплиц (3×L1, 2×L2) → L2 живой сразу, L3 — для первого апгрейдера.
 * Фича сама даёт повод качать теплицу (раньше уровни влияли только на пассив).
 *
 * Constitutional ADR-024: rationale/effect/above/below NOT NULL. Idempotent.
 */
class V7SeedGreenhouseFarmingEffects extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'building.greenhouse.l2.harvest_yield_multiplier',
                'value_float'        => 1.15,
                'default_value_text' => '1.15',
                'rationale_text'     => '+15% урожая активной посадки на L2 теплицы (КАЧЕСТВО). Даёт повод апгрейдить теплицу ради земледелия (раньше уровень влиял только на пассивную продукцию). Пример: ягоды 8 → 9 за посадку.',
                'effect_text'        => 'BuildingEffectsService::getGreenhouseYieldMultiplier × базовый урожай (× rotation) в PlantCropCompletionHandler при сборе. По уровню теплицы при сборе (апгрейд во время роста учитывается).',
                'above_effect_text'  => 'При 1.50 (+50%) на L2 — урожай слишком жирный для второго уровня, ломает прогрессию (L3 теряет смысл) и инфляция еды.',
                'below_effect_text'  => 'При 1.02 (+2%) — прибавка незаметна (8 → 8 после округления), L2-апгрейд не окупается ради земледелия.',
                'recommended_min'    => '1.05',
                'recommended_max'    => '1.40',
                'hard_min'           => '1.00',
                'hard_max'           => '3.00',
            ],
            [
                'setting_key'        => 'building.greenhouse.l3.harvest_yield_multiplier',
                'value_float'        => 1.30,
                'default_value_text' => '1.30',
                'rationale_text'     => '+30% урожая активной посадки на L3 теплицы — крупная награда за прокачку. Стакается с севооборотом (rotation +20%): L3 + севооборот ягоды 8 → round(8×1.2×1.3)=12. L4-L10 plateau cascade.',
                'effect_text'        => 'BuildingEffectsService::getGreenhouseYieldMultiplier × базовый урожай (× rotation) в PlantCropCompletionHandler.',
                'above_effect_text'  => 'При 2.00 (+100%) — посадка удваивает урожай, пассив теплицы и баланс еды обесцениваются.',
                'below_effect_text'  => 'При 1.10 — L3 даёт лишь +10% над baseline (≈ L2), плохая прогрессия, крупный апгрейд не ощущается.',
                'recommended_min'    => '1.15',
                'recommended_max'    => '1.60',
                'hard_min'           => '1.00',
                'hard_max'           => '3.00',
            ],
            [
                'setting_key'        => 'building.greenhouse.l2.grow_time_multiplier',
                'value_float'        => 0.90,
                'default_value_text' => '0.90',
                'rationale_text'     => '-10% времени роста на L2 теплицы (СКОРОСТЬ/scheduling). Зеркало Workshop craft-time (S11). Чаще собираешь урожай → больше посадок за день. Пример: ягоды 30 → 27 мин.',
                'effect_text'        => 'BuildingEffectsService::getGreenhouseGrowTimeMultiplier × farming.grow.<crop>_minutes в PlantCropActionStart (end_time замораживается при посадке). Min 1 минута.',
                'above_effect_text'  => 'При 0.99 — ускорение 1% незаметно (30 → 30 после округления), L2 не ощущается.',
                'below_effect_text'  => 'При 0.60 на L2 — слишком быстро для второго уровня (L3 теряет смысл), near-spam фарм.',
                'recommended_min'    => '0.80',
                'recommended_max'    => '0.95',
                'hard_min'           => '0.10',
                'hard_max'           => '1.00',
            ],
            [
                'setting_key'        => 'building.greenhouse.l3.grow_time_multiplier',
                'value_float'        => 0.80,
                'default_value_text' => '0.80',
                'rationale_text'     => '-20% времени роста на L3 теплицы — заметное ускорение цикла. Пример: овощи 60 → 48 мин, ягоды 30 → 24 мин. L4-L10 plateau cascade.',
                'effect_text'        => 'BuildingEffectsService::getGreenhouseGrowTimeMultiplier × farming.grow.<crop>_minutes в PlantCropActionStart. Min 1 минута.',
                'above_effect_text'  => 'При 0.90 — L3 = +10% над L2, слабая прогрессия, крупный апгрейд не ощущается.',
                'below_effect_text'  => 'При 0.50 — рост вдвое быстрее, near-spam фарм, обесценивание времени как sink.',
                'recommended_min'    => '0.65',
                'recommended_max'    => '0.88',
                'hard_min'           => '0.10',
                'hard_max'           => '1.00',
            ],
        ];

        $shared = [
            'category'   => 'buildings',
            'value_type' => 'float',
            'created_at' => $now,
            'updated_at' => $now,
        ];

        foreach ($rows as $row) {
            $exists = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->get()->getRowArray();
            if (! empty($exists)) {
                continue;
            }
            $this->db->table('game_settings')->insert(array_merge($shared, $row));
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')->whereIn('setting_key', [
            'building.greenhouse.l2.harvest_yield_multiplier',
            'building.greenhouse.l3.harvest_yield_multiplier',
            'building.greenhouse.l2.grow_time_multiplier',
            'building.greenhouse.l3.grow_time_multiplier',
        ])->delete();
    }
}
