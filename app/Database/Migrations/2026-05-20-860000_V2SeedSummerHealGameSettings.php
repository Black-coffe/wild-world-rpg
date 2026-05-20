<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * V2 (ADR-032, vNext) — heal-эффекты 5 летних consumable (admin-tunable, ADR-024).
 *
 * UsePharmacyAction (data-driven, S19) читает medical.<snake>.heal_health/.heal_tired.
 * category=combat (как S19/S28/V1). 10 ключей (5 items × 2). Зеркало V1 spring heal.
 * Idempotent.
 */
class V2SeedSummerHealGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // [snake, ru, heal_health, heal_tired]
        $items = [
            ['summer_cold_kvass',  'Холодный квас',  25, 45],
            ['summer_berry_mors',  'Лесной морс',    30, 40],
            ['summer_fruit_water', 'Фруктовая вода', 35, 35],
            ['summer_mint_tea',    'Мятный отвар',   45, 25],
            ['summer_aloe_balm',   'Алоэ-бальзам',   55, 15],
        ];

        $rows = [];
        foreach ($items as [$snake, $ru, $hp, $tired]) {
            $rows[] = [
                'setting_key'        => "medical.{$snake}.heal_health",
                'value_int'          => $hp,
                'default_value_text' => (string) $hp,
                'rationale_text'     => "Сколько HP восстанавливает «{$ru}» (летний сезонный consumable). Баланс с прочими хилками: сезонные — доступная альтернатива на время Лета.",
                'effect_text'        => "UsePharmacyAction начисляет +{$hp} здоровья при использовании (cap 100).",
                'above_effect_text'  => 'Выше — сезонная хилка вытесняет обычную медицину, ломает экономику лечения.',
                'below_effect_text'  => 'Ниже/0 — предмет не лечит здоровье (только выносливость, если задана).',
            ];
            $rows[] = [
                'setting_key'        => "medical.{$snake}.heal_tired",
                'value_int'          => $tired,
                'default_value_text' => (string) $tired,
                'rationale_text'     => "Сколько выносливости восстанавливает «{$ru}» (летний сезонный consumable). Зной истощает — прохладительные припасы возвращают силы.",
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
        foreach (['summer_cold_kvass', 'summer_berry_mors', 'summer_fruit_water', 'summer_mint_tea', 'summer_aloe_balm'] as $snake) {
            $keys[] = "medical.{$snake}.heal_health";
            $keys[] = "medical.{$snake}.heal_tired";
        }
        $this->db->table('game_settings')->whereIn('setting_key', $keys)->delete();
    }
}
