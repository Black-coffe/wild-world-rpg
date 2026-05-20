<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S20 (v0.51.202) — seed 3 GameSettings keys для T3 utility craft durations
 * (ADR-026 reusable pattern, ROADMAP-CRAFT §7 Фаза 4 5/5).
 *
 * Admin-tunable длительность через `tier3.utility.<key>.craft_duration_hours`:
 *   - diamond_pickaxe = 4h  (премиум добывающий инструмент, 300 прочности)
 *   - sapper_shovel   = 2h  (усиленная лопата, 120 прочности)
 *   - golden_hoe      = 2h  (премиум мотыга, 86 прочности)
 *
 * Wire-up: GenericCraftActionStart::calculateCraftingDuration через
 * recipe.duration_override_setting_key (ADR-026). Fallback на tasks.min/max_duration.
 *
 * Constitutional rule (CLAUDE.md §🎛️): rationale/effect/above/below NOT NULL.
 * Idempotent: пропускает существующие setting_key.
 */
class S20SeedT3UtilityGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'tier3.utility.diamond_pickaxe.craft_duration_hours',
                'value_int'          => 4,
                'default_value_text' => '4',
                'rationale_text'     => '4 часа для премиум добывающего инструмента (300 прочности, до +2.2× бонус по глине). Самый дорогой T3-инструмент — 4h оправдывают огромную прочность и бонус, ставят в один ряд с Legendary-крафтами L20.',
                'effect_text'        => 'Длительность task craftDiamondPickaxe (override tasks.min/max_duration). GenericCraftActionStart читает через recipe.duration_override_setting_key.',
                'above_effect_text'  => 'При 8h — overnight commit; кирка станет «sleep-on-it», игроки продолжат лутать её вместо крафта.',
                'below_effect_text'  => 'При 2h — flat с лопатой/мотыгой, теряется premium самого мощного инструмента.',
            ],
            [
                'setting_key'        => 'tier3.utility.sapper_shovel.craft_duration_hours',
                'value_int'          => 2,
                'default_value_text' => '2',
                'rationale_text'     => '2 часа для усиленной лопаты (entry-T3 утилита). Быстрая заготовка для копающих ресурсов (глина/песок/ил/травы). Parity с другими entry-T3.',
                'effect_text'        => 'Длительность task craftSapperShovel (override tasks.min/max_duration). См. wire-up в diamond_pickaxe.',
                'above_effect_text'  => 'При 4h — лопата становится дороже по времени, чем оправдывает её бонус; выберут только кирку.',
                'below_effect_text'  => 'При 1h — слишком дёшево по времени для T3-инструмента, обесценивает T1/T2 лопаты.',
            ],
            [
                'setting_key'        => 'tier3.utility.golden_hoe.craft_duration_hours',
                'value_int'          => 2,
                'default_value_text' => '2',
                'rationale_text'     => '2 часа для премиум мотыги (фермерский T3). Parity с лопатой (тот же entry-tier). Усиливает сбор овощей/трав/мхов для фермеров.',
                'effect_text'        => 'Длительность task craftGoldenHoe (override tasks.min/max_duration). См. wire-up в diamond_pickaxe.',
                'above_effect_text'  => 'При 4h — фермеры не станут крафтить, останутся на обычной мотыге.',
                'below_effect_text'  => 'При 1h — обесценивает обычную мотыгу (Hoe T1).',
            ],
        ];

        $shared = [
            'category'        => 'craft',
            'value_type'      => 'int',
            'recommended_min' => '2',
            'recommended_max' => '12',
            'hard_min'        => '1',
            'hard_max'        => '48',
            'created_at'      => $now,
            'updated_at'      => $now,
        ];

        foreach ($rows as $row) {
            $existing = $this->db->table('game_settings')
                ->where('setting_key', $row['setting_key'])
                ->get()
                ->getRowArray();
            if (! empty($existing)) {
                continue;
            }
            $this->db->table('game_settings')->insert(array_merge($shared, $row));
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->whereIn('setting_key', [
                'tier3.utility.diamond_pickaxe.craft_duration_hours',
                'tier3.utility.sapper_shovel.craft_duration_hours',
                'tier3.utility.golden_hoe.craft_duration_hours',
            ])
            ->delete();
    }
}
