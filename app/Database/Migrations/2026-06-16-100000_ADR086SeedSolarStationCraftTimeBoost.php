<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-086 Фаза 1a — Солнечная станция получает реальный эффект: craft-TIME буст
 * электроники (Wiring + ElectronicComponents), стак с Workshop.
 *
 * 4 GameSettings (category=buildings):
 *  - building.solarstation.boost.enabled (bool, OFF) — killswitch (dormant на ship).
 *  - building.solarstation.l2/l3/l10.craft_time_multiplier (float) — множители времени
 *    крафта электроники (cascade + ADR-042 интерполяция L4-L9 между L3 и L10-якорем).
 *
 * **Ship-time safe (DORMANT):** killswitch false → BuildingEffectsService пропускает
 * SolarStation-ветку (boostBuildingEnabled() → false) → craft-time электроники
 * байт-идентичен текущему (Workshop-only). 0 эффекта на живых игроков. Активация —
 * осознанно после Tier-3 на проде (буст — pure plus, риск низкий).
 *
 * Гейт роботов/телепорта на Станцию — отдельная Фаза 1b (player-hostile, grandfather
 * + lock-button + balance-pass), НЕ в этой миграции.
 *
 * Constitutional (ADR-024): rationale/effect/above/below NOT NULL. Idempotent.
 */
class ADR086SeedSolarStationCraftTimeBoost extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'building.solarstation.boost.enabled',
                'category'           => 'buildings',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Killswitch craft-time бафа Солнечной станции на электронику (Wiring/ElectronicComponents). ADR-086 Фаза 1a. false на ship → буст не применяется (×1.0), 0 эффекта на живых. Активация осознанно после Tier-3 на проде: даёт Станции реальный смысл апгрейда вместо «только prereq Арсенала».',
                'effect_text'        => 'BuildingEffectsService::boostBuildingEnabled() гейтит SolarStation-ветку getCraftTimeMultiplier. Рецепты Wiring/ElectronicComponents декларируют boost_building_time=SolarStation. false → ветка пропускается → Workshop-only тайминг.',
                'above_effect_text'  => 'true: владельцы Станции L2+ крафтят электронику быстрее (стак мультипликативно с Workshop). Появляется стимул строить/качать Станцию помимо гейта Арсенала.',
                'below_effect_text'  => 'false: электроника крафтится без ускорения Станцией (текущее прод-поведение, dormant).',
                'recommended_min'    => null,
                'recommended_max'    => null,
                'hard_min'           => null,
                'hard_max'           => null,
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'building.solarstation.l2.craft_time_multiplier',
                'category'           => 'buildings',
                'value_type'         => 'float',
                'value_float'        => 0.95,
                'default_value_text' => '0.95',
                'rationale_text'     => 'Множитель времени крафта электроники за Станцию L2 (−5%). Буст узкий (2 рецепта) и стакается с глобальным Workshop → намеренно мягче Workshop (0.90), чтобы стак не делал электронику почти мгновенной. Cascade: L1 Станция = baseline 1.0 (эффект начинается с L2).',
                'effect_text'        => 'BuildingEffectsService::getCraftTimeMultiplier × этот множитель для Wiring/ElectronicComponents при Станции L2+ и killswitch ON. Стак мультипликативный с Workshop/едой/специализацией/фракц-буфом.',
                'above_effect_text'  => 'При 1.0: L2-апгрейд Станции не ускоряет крафт → нет награды за уровень, буст мёртв на L2.',
                'below_effect_text'  => 'При 0.80: слишком сильное ускорение уже на L2 → обесценивает L3+ и со стаком Workshop электроника крафтится почти мгновенно.',
                'recommended_min'    => '0.85',
                'recommended_max'    => '0.98',
                'hard_min'           => '0.50',
                'hard_max'           => '1.00',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'building.solarstation.l3.craft_time_multiplier',
                'category'           => 'buildings',
                'value_type'         => 'float',
                'value_float'        => 0.90,
                'default_value_text' => '0.90',
                'rationale_text'     => 'Множитель времени крафта электроники за Станцию L3 (−10%). Удвоение L2-эффекта — ощутимая награда за апгрейд. С L10-якорем интерполируется L4-L9 (ADR-042), без якоря — плато на L3.',
                'effect_text'        => 'BuildingEffectsService cascade/interpolate: Станция L3 → ×0.90 на электронику. Активно при killswitch ON.',
                'above_effect_text'  => 'При 0.95 (=L2): апгрейд L2→L3 не ощущается, «зачем качал Станцию».',
                'below_effect_text'  => 'При 0.75: электроника + стак Workshop L3 (0.75×0.75=0.5625) крафтится слишком быстро для узкого буста.',
                'recommended_min'    => '0.80',
                'recommended_max'    => '0.95',
                'hard_min'           => '0.50',
                'hard_max'           => '1.00',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'building.solarstation.l10.craft_time_multiplier',
                'category'           => 'buildings',
                'value_type'         => 'float',
                'value_float'        => 0.80,
                'default_value_text' => '0.80',
                'rationale_text'     => 'L10-якорь множителя времени электроники (−20%). Включает ADR-042-интерполяцию L4-L9 между L3 (0.90) и L10 (0.80) → каждый апгрейд Станции L4-L10 улучшает буст (устраняет dead-upgrade плато). Мягче Workshop L10-якоря (0.55), т.к. буст узкий и стакается.',
                'effect_text'        => 'BuildingEffectsService::resolveLevelMultiplier интерполирует L4-L9 при наличии L3+L10. Активно при killswitch ON.',
                'above_effect_text'  => 'При 0.90 (=L3): L4-L10 апгрейды Станции = мёртвое плато (нет смысла качать выше L3).',
                'below_effect_text'  => 'При 0.60: эндгейм-электроника почти мгновенна (стак Workshop L10 0.55 × 0.60 = 0.33, −67%) → обесценивает крафт-тайминг.',
                'recommended_min'    => '0.70',
                'recommended_max'    => '0.88',
                'hard_min'           => '0.50',
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
                'building.solarstation.boost.enabled',
                'building.solarstation.l2.craft_time_multiplier',
                'building.solarstation.l3.craft_time_multiplier',
                'building.solarstation.l10.craft_time_multiplier',
            ])
            ->delete();
    }
}
