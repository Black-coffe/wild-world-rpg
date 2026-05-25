<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * V17 (ROADMAP-vNext Фаза 4, ADR-048) — per-branch scaling перка специализации.
 *
 * Углубляет V16 (ADR-047): множитель времени крафта матч-ветки РАСТЁТ по уровню
 * персонажа, интерполируясь между anchor'ами L5 (вход) и L25 (эндгейм). Кривые
 * различаются per-branch (узкие ветки сильнее per-craft, инженер шире/слабее).
 *
 *   weaponsmith  L5 0.90 → L25 0.80  (−10% → −20%)
 *   medic        L5 0.90 → L25 0.83  (−10% → −17%)
 *   engineer     L5 0.90 → L25 0.85  (−10% → −15%)
 *
 * Все стартуют одинаково (−10% на L5 = V16). Без этих ключей SpecializationService
 * падает на V16 global `specialization.craft_time_multiplier` (0.90 плоско) → 0
 * регрессии. category=craft (ADR-024 rich rationale). Idempotent.
 */
class V17SeedSpecializationPerkScaling extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // branch => [label, L5, L25, domainNote]
        $branches = [
            'weaponsmith' => ['Оружейник', 0.90, 0.80, 'узкая ветка (только оружие), высокоценные крафты — сильнейший payoff'],
            'medic'       => ['Медик', 0.90, 0.83, 'узкая ветка (медицина), крафты и так быстрые'],
            'engineer'    => ['Инженер', 0.90, 0.85, 'широкая ветка (производство/защита/инструменты) — слабее per-craft, компенсируется охватом'],
        ];

        $rows = [];
        foreach ($branches as $branch => [$label, $l5, $l25, $note]) {
            $rows[] = [
                'setting_key'        => "specialization.{$branch}.l5.craft_time_multiplier",
                'value_type'         => 'float',
                'value_float'        => $l5,
                'default_value_text' => (string) $l5,
                'rationale_text'     => "Множитель времени крафта ветки «{$label}» на ВХОДЕ (уровень 5, когда специализация открывается). " . number_format((1 - $l5) * 100, 0) . "% ускорение. Все ветки стартуют одинаково (= V16).",
                'effect_text'        => "SpecializationService::craftTimeMultiplierForLevel — нижний anchor интерполяции по уровню (L5↔L25). Применяется к крафту матч-категории {$label}.",
                'above_effect_text'  => 'Выше (ближе к 1.0) — вход в специализацию почти не даёт ускорения, выбор ветки не ощущается.',
                'below_effect_text'  => 'Ниже — новичок-специалист сразу слишком силён, обесценивает прокачку уровня/верстака.',
                'recommended_min'    => '0.80',
                'recommended_max'    => '0.97',
                'hard_min'           => '0.50',
                'hard_max'           => '1.00',
            ];
            $rows[] = [
                'setting_key'        => "specialization.{$branch}.l25.craft_time_multiplier",
                'value_type'         => 'float',
                'value_float'        => $l25,
                'default_value_text' => (string) $l25,
                'rationale_text'     => "Множитель времени крафта ветки «{$label}» в ЭНДГЕЙМЕ (уровень 25+). " . number_format((1 - $l25) * 100, 0) . "% ускорение — потолок per-branch кривой ({$note}).",
                'effect_text'        => "SpecializationService::craftTimeMultiplierForLevel — верхний anchor интерполяции. Между L5 и L25 значение линейно интерполируется по уровню персонажа.",
                'above_effect_text'  => 'Выше (ближе к L5) — рост специализации почти не ощущается, нет долгосрочной цели.',
                'below_effect_text'  => 'Ниже — эндгейм-специалист крафтит слишком быстро; следить за перекосом веток (особенно широкий engineer).',
                'recommended_min'    => '0.70',
                'recommended_max'    => '0.95',
                'hard_min'           => '0.50',
                'hard_max'           => '1.00',
            ];
        }

        $defaults = [
            'category'        => 'craft',
            'value_int'       => null,
            'value_float'     => null,
            'value_bool'      => null,
            'value_string'    => null,
            'recommended_min' => null,
            'recommended_max' => null,
            'hard_min'        => null,
            'hard_max'        => null,
            'created_at'      => $now,
            'updated_at'      => $now,
        ];

        foreach ($rows as $row) {
            $exists = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->get()->getRowArray();
            if (! empty($exists)) {
                continue;
            }
            $this->db->table('game_settings')->insert(array_merge($defaults, $row));
        }
    }

    public function down(): void
    {
        $keys = [];
        foreach (['weaponsmith', 'medic', 'engineer'] as $branch) {
            $keys[] = "specialization.{$branch}.l5.craft_time_multiplier";
            $keys[] = "specialization.{$branch}.l25.craft_time_multiplier";
        }
        $this->db->table('game_settings')->whereIn('setting_key', $keys)->delete();
    }
}
