<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * V1 (ADR-032, vNext) — heal-эффекты 5 весенних consumable (admin-tunable, ADR-024).
 *
 * UsePharmacyAction (data-driven, S19) читает medical.<snake>.heal_health/.heal_tired.
 * category=combat (как S19/S28). 10 ключей (5 items × 2). Зеркало S28 winter heal.
 * Idempotent.
 */
class V1SeedSpringHealGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // [snake, ru, heal_health, heal_tired]
        $items = [
            ['spring_first_herb_tea',     'Чай из первой травы',   25, 45],
            ['spring_birch_sap',          'Берёзовый сок',         30, 40],
            ['spring_primrose_infusion',  'Настой первоцвета',     55, 15],
            ['spring_wild_greens',        'Весенняя зелень',       35, 30],
            ['spring_shoots_decoction',   'Отвар молодых побегов', 45, 25],
        ];

        $rows = [];
        foreach ($items as [$snake, $ru, $hp, $tired]) {
            $rows[] = [
                'setting_key'        => "medical.{$snake}.heal_health",
                'value_int'          => $hp,
                'default_value_text' => (string) $hp,
                'rationale_text'     => "Сколько HP восстанавливает «{$ru}» (весенний сезонный consumable). Баланс с прочими хилками: сезонные — доступная альтернатива на время Весны.",
                'effect_text'        => "UsePharmacyAction начисляет +{$hp} здоровья при использовании (cap 100).",
                'above_effect_text'  => 'Выше — сезонная хилка вытесняет обычную медицину, ломает экономику лечения.',
                'below_effect_text'  => 'Ниже/0 — предмет не лечит здоровье (только выносливость, если задана).',
            ];
            $rows[] = [
                'setting_key'        => "medical.{$snake}.heal_tired",
                'value_int'          => $tired,
                'default_value_text' => (string) $tired,
                'rationale_text'     => "Сколько выносливости восстанавливает «{$ru}» (весенний сезонный consumable). Освежающие весенние припасы возвращают силы после зимы.",
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
        foreach (['spring_first_herb_tea', 'spring_birch_sap', 'spring_primrose_infusion', 'spring_wild_greens', 'spring_shoots_decoction'] as $snake) {
            $keys[] = "medical.{$snake}.heal_health";
            $keys[] = "medical.{$snake}.heal_tired";
        }
        $this->db->table('game_settings')->whereIn('setting_key', $keys)->delete();
    }
}
