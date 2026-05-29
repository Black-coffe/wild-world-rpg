<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W23 (ADR-078) — GameSettings для рыбных блюд: killswitch + heal/сытость.
 *
 * Killswitch cooking.fish_dishes.enabled (default OFF dormant) — рыбные блюда
 * не показываются в меню готовки до активации батчем в конце ROADMAP.
 *
 * 9 balance-ключей (admin-tunable, ADR-024): на каждое из 3 блюд —
 * medical.<snake>.heal_health / heal_tired (combat) + food.<snake>.well_fed_minutes (craft).
 * Значения откалиброваны между mushroom_soup (25/35/30) и hearty_stew (45/55/60).
 */
class W23SeedFishDishesGameSettings extends Migration
{
    public function up(): void
    {
        $rows = [];

        // Killswitch (dormant).
        $rows[] = [
            'setting_key'        => 'cooking.fish_dishes.enabled',
            'value_type'         => 'bool',
            'value_bool'         => 0,
            'default_value_text' => 'false',
            'category'           => 'craft',
            'rationale_text'     => 'Killswitch для W23 рыбных блюд (Уха / Жареная рыба / Рыбные консервы). Default OFF — dormant до активации батчем в конце ROADMAP vNext2.',
            'effect_text'        => 'При true — в меню «🔥 Костёр — готовка» появляются 3 рыбных блюда, дающих рыбе (давно добываемой в Реках) кулинарное применение. При false — скрыты.',
            'above_effect_text'  => 'n/a — булев ключ',
            'below_effect_text'  => 'n/a — булев ключ',
            'recommended_min'    => null,
            'recommended_max'    => null,
            'hard_min'           => null,
            'hard_max'           => null,
        ];

        // 3 блюда × heal_health / heal_tired / well_fed_minutes.
        // [snake, rus, heal_health, heal_tired, well_fed_minutes]
        $dishes = [
            ['fish_soup',     'Уха',             35, 45, 45],
            ['grilled_fish',  'Жареная рыба',    40, 30, 35],
            ['fish_preserve', 'Рыбные консервы', 45, 45, 80],
        ];

        foreach ($dishes as [$snake, $rus, $healHp, $healTd, $wellFed]) {
            $rows[] = [
                'setting_key'        => "medical.{$snake}.heal_health",
                'value_type'         => 'int',
                'value_int'          => $healHp,
                'default_value_text' => (string) $healHp,
                'category'           => 'combat',
                'rationale_text'     => "Сколько HP восстанавливает блюдо «{$rus}» (рыбное, костёр). Рыба питательна (rarity 7) → heal между грибной похлёбкой (25) и сытным рагу (45).",
                'effect_text'        => "UsePharmacyAction начисляет +{$healHp} здоровья при поедании «{$rus}».",
                'above_effect_text'  => 'Выше — рыбные блюда становятся слишком сильным источником лечения, обесценивают медикаменты.',
                'below_effect_text'  => 'Ниже — рыбная готовка теряет смысл, рыба остаётся dead-end-ресурсом.',
                'recommended_min'    => 0,
                'recommended_max'    => 100,
                'hard_min'           => 0,
                'hard_max'           => 1000,
            ];
            $rows[] = [
                'setting_key'        => "medical.{$snake}.heal_tired",
                'value_type'         => 'int',
                'value_int'          => $healTd,
                'default_value_text' => (string) $healTd,
                'category'           => 'combat',
                'rationale_text'     => "Сколько выносливости восстанавливает блюдо «{$rus}». Еда в первую очередь снимает усталость.",
                'effect_text'        => "UsePharmacyAction начисляет +{$healTd} выносливости при поедании «{$rus}».",
                'above_effect_text'  => 'Выше — слишком дёшево снимать усталость едой, теряется sink ресурсов.',
                'below_effect_text'  => 'Ниже — еда слабо помогает с выносливостью, мотивация готовить падает.',
                'recommended_min'    => 0,
                'recommended_max'    => 100,
                'hard_min'           => 0,
                'hard_max'           => 1000,
            ];
            $rows[] = [
                'setting_key'        => "food.{$snake}.well_fed_minutes",
                'value_type'         => 'int',
                'value_int'          => $wellFed,
                'default_value_text' => (string) $wellFed,
                'category'           => 'craft',
                'rationale_text'     => "Сколько минут держится «Сытость» после блюда «{$rus}» (крафт быстрее + добыча щедрее). Консервы дольше (заготовка), горячее — короче.",
                'effect_text'        => "UsePharmacyAction ставит characters.well_fed_until = now + {$wellFed} мин при поедании «{$rus}».",
                'above_effect_text'  => 'Выше — «Сытость» почти постоянна, обесценивает регулярную готовку.',
                'below_effect_text'  => 'Ниже — buff мелькает, готовить ради сытости невыгодно.',
                'recommended_min'    => 10,
                'recommended_max'    => 180,
                'hard_min'           => 0,
                'hard_max'           => 1440,
            ];
        }

        foreach ($rows as $row) {
            $exists = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->countAllResults();
            if ($exists === 0) {
                $this->db->table('game_settings')->insert($row);
            }
        }
    }

    public function down(): void
    {
        $keys = ['cooking.fish_dishes.enabled'];
        foreach (['fish_soup', 'grilled_fish', 'fish_preserve'] as $snake) {
            $keys[] = "medical.{$snake}.heal_health";
            $keys[] = "medical.{$snake}.heal_tired";
            $keys[] = "food.{$snake}.well_fed_minutes";
        }
        $this->db->table('game_settings')->whereIn('setting_key', $keys)->delete();
    }
}
