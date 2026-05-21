<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * V8 (vNext, Фаза 2) — heal-эффекты 5 блюд костра (admin-tunable, ADR-024).
 *
 * UsePharmacyAction (data-driven, S19) читает medical.<snake>.heal_health/.heal_tired.
 * category=combat (как S19/S28/V1-V3). 10 ключей (5 блюд × 2). Еда восстанавливает
 * больше ВЫНОСЛИВОСТИ (энергия), чем здоровья — ниша против медицины. Idempotent.
 */
class V8SeedCookingHealGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // [snake, ru, heal_health, heal_tired]
        $items = [
            ['mushroom_soup',  'Грибная похлёбка', 25, 35],
            ['berry_brew',     'Ягодный взвар',    20, 40],
            ['baked_fruit',    'Печёные фрукты',   30, 30],
            ['grain_porridge', 'Зерновая каша',    35, 35],
            ['hearty_stew',    'Сытное рагу',      45, 55],
        ];

        $rows = [];
        foreach ($items as [$snake, $ru, $hp, $tired]) {
            $rows[] = [
                'setting_key'        => "medical.{$snake}.heal_health",
                'value_int'          => $hp,
                'default_value_text' => (string) $hp,
                'rationale_text'     => "Сколько HP восстанавливает «{$ru}» (блюдо костра). Еда лечит здоровье умеренно — основной упор на выносливость; медицина остаётся сильнее по HP.",
                'effect_text'        => "UsePharmacyAction начисляет +{$hp} здоровья при использовании (cap 100).",
                'above_effect_text'  => 'Выше — еда вытесняет медицину по лечению HP, обесценивает аптечки.',
                'below_effect_text'  => 'Ниже/0 — блюдо не лечит здоровье (только выносливость).',
            ];
            $rows[] = [
                'setting_key'        => "medical.{$snake}.heal_tired",
                'value_int'          => $tired,
                'default_value_text' => (string) $tired,
                'rationale_text'     => "Сколько выносливости восстанавливает «{$ru}» (блюдо костра). Сытная еда — главный способ быстро вернуть силы; в этом ниша готовки.",
                'effect_text'        => "UsePharmacyAction начисляет +{$tired} выносливости при использовании (cap 100).",
                'above_effect_text'  => 'Выше — еда слишком сильно восстанавливает стамину, обесценивает отдых.',
                'below_effect_text'  => 'Ниже/0 — блюдо не восстанавливает выносливость (теряет смысл как еда).',
            ];
        }

        $shared = [
            'category'        => 'combat',
            'value_type'      => 'int',
            'recommended_min' => '0',
            'recommended_max' => '100',
            'hard_min'        => '0',
            'hard_max'        => '100',
            'created_at'      => $now,
            'updated_at'      => $now,
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
        $keys = [];
        foreach (['mushroom_soup', 'berry_brew', 'baked_fruit', 'grain_porridge', 'hearty_stew'] as $snake) {
            $keys[] = "medical.{$snake}.heal_health";
            $keys[] = "medical.{$snake}.heal_tired";
        }
        $this->db->table('game_settings')->whereIn('setting_key', $keys)->delete();
    }
}
