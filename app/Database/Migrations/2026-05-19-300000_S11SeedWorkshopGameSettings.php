<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S11 (v0.51.193) — seed GameSettings keys для Workshop level effects (ROADMAP-CRAFT §6).
 *
 * До S11 уровень Workshop = pure косметика (S3 wired UI suffix L<n>, но
 * никакого gameplay-эффекта; `buildings.effects = "crafting_bonus"` был dead string).
 *
 * Pattern: `building.<building_name_lower>.l<N>.<param_name>`.
 * Reading: `BuildingEffectsService::getCraftTimeMultiplier($charId)` резолвит
 * Workshop level из `character_buildings.level` → читает соответствующий ключ →
 * fallback на 1.0 (= no effect).
 *
 * **L4-L10 plateau decision (user pick 2026-05-19):** L4-L10 наследуют L3
 * multiplier (0.75) до явного расширения ROADMAP'ом будущих сессий. Cascade
 * реализован в service'е (lookup от текущего level вниз до первого defined).
 *
 * Constitutional rule (CLAUDE.md §🎛️ ADR-024): rationale/effect/above/below
 * NOT NULL.
 *
 * Idempotent: пропускает существующие setting_key.
 */
class S11SeedWorkshopGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'building.workshop.l2.craft_time_multiplier',
                'category'           => 'buildings',
                'value_type'         => 'float',
                'value_float'        => 0.90,
                'default_value_text' => '0.90',
                'rationale_text'     => '-10% craft time на L2 — ощутимая, но не разорительная награда за первый upgrade (50k gold, character_level≥1). Контракт «каждый уровень даёт что-то заметное» без power-creep.',
                'effect_text'        => 'Множитель craft duration в GenericCraftActionStart::calculateCraftingDuration() для всех recipe (любая task через task_id). Применяется ПОСЛЕ char-stats формулы. Если у char Workshop L2 → итоговая длительность = round(duration_after_stats × 0.90).',
                'above_effect_text'  => 'При 0.95 — экономия ~5% (~1 мин на 20-минутный craft), визуально незаметно, ослабляет «ощущение апгрейда». При 0.99 — мёртвая фича, игроки жалуются «зачем тратил 50k золота».',
                'below_effect_text'  => 'При 0.70 — L2 уже сравним с ROADMAP-планируемым L3 (-25%), L3 теряет смысл, ломает progression curve. При 0.50 — L2 апгрейд = must-have, не «выбор», игроки чувствуют принуждение.',
                'recommended_min'    => '0.80',
                'recommended_max'    => '0.95',
                'hard_min'           => '0.10',
                'hard_max'           => '1.00',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'building.workshop.l3.craft_time_multiplier',
                'category'           => 'buildings',
                'value_type'         => 'float',
                'value_float'        => 0.75,
                'default_value_text' => '0.75',
                'rationale_text'     => '-25% craft time на L3 — крупная награда за финальный upgrade Workshop в Phase 3 (75k gold, character_level≥12). L4-L10 наследуют (plateau) до будущих сессий с явными эффектами. 2 чара уже на L5 на проде получат этот бонус сразу после S11.',
                'effect_text'        => 'Множитель craft duration в GenericCraftActionStart::calculateCraftingDuration(). Применяется ПОСЛЕ char-stats формулы. Workshop L3+ → итоговая длительность = round(duration_after_stats × 0.75). На L1 = 1.00 (нет effect), L2 = 0.90, L3-L10 = 0.75.',
                'above_effect_text'  => 'При 0.85 — L3 даёт +5% к L2, плохо чувствуется как «крупный» апгрейд, П1/П5 жалуются на отсутствие momentum. При 0.95 — L3 = тривиальное продолжение L2.',
                'below_effect_text'  => 'При 0.60 — L3 крафт в 1.67× быстрее, ломает craft economy (timer-based gates перестают работать). При 0.40 — L3 craft = почти instant, T3 craft loses meaning как time-gated activity.',
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
                'building.workshop.l2.craft_time_multiplier',
                'building.workshop.l3.craft_time_multiplier',
            ])
            ->delete();
    }
}
