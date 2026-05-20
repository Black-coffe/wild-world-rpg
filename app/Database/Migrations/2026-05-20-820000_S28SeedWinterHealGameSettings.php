<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S28 (ADR-032) — heal-эффекты 5 зимних consumable (admin-tunable, ADR-024).
 *
 * UsePharmacyAction (data-driven, S19) читает medical.<snake>.heal_health/.heal_tired.
 * category=combat (как S19). 10 ключей (5 items × 2). Idempotent.
 */
class S28SeedWinterHealGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // [snake, ru, heal_health, heal_tired]
        $items = [
            ['winter_herbal_brew',  'Горячий травяной отвар', 40, 20],
            ['winter_honey_mead',   'Медовый сбитень',        25, 45],
            ['winter_warming_balm', 'Согревающая мазь',       60, 0],
            ['winter_camp_stew',    'Походная похлёбка',      35, 35],
            ['winter_preserves',    'Зимние заготовки',       50, 25],
        ];

        $rows = [];
        foreach ($items as [$snake, $ru, $hp, $tired]) {
            $rows[] = [
                'setting_key'        => "medical.{$snake}.heal_health",
                'value_int'          => $hp,
                'default_value_text' => (string) $hp,
                'rationale_text'     => "Сколько HP восстанавливает «{$ru}» (зимний сезонный consumable). Баланс с прочими хилками: сезонные — доступная альтернатива на время Зимы.",
                'effect_text'        => "UsePharmacyAction начисляет +{$hp} здоровья при использовании (cap 100).",
                'above_effect_text'  => 'Выше — сезонная хилка вытесняет обычную медицину, ломает экономику лечения.',
                'below_effect_text'  => 'Ниже/0 — предмет не лечит здоровье (только выносливость, если задана).',
            ];
            $rows[] = [
                'setting_key'        => "medical.{$snake}.heal_tired",
                'value_int'          => $tired,
                'default_value_text' => (string) $tired,
                'rationale_text'     => "Сколько выносливости восстанавливает «{$ru}» (зимний сезонный consumable). Холод истощает — тёплые припасы возвращают силы.",
                'effect_text'        => "UsePharmacyAction начисляет +{$tired} выносливости при использовании (cap 100).",
                'above_effect_text'  => 'Выше — сезонная хилка слишком сильно восстанавливает стамину, обесценивает отдых/еду.',
                'below_effect_text'  => 'Ниже/0 — предмет не восстанавливает выносливость (только здоровье, если задано).',
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
        foreach (['winter_herbal_brew', 'winter_honey_mead', 'winter_warming_balm', 'winter_camp_stew', 'winter_preserves'] as $snake) {
            $keys[] = "medical.{$snake}.heal_health";
            $keys[] = "medical.{$snake}.heal_tired";
        }
        $this->db->table('game_settings')->whereIn('setting_key', $keys)->delete();
    }
}
