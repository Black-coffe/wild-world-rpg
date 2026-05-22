<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-042 — L10-якоря для устранения L4-L10 «dead-upgrade плато» зданий
 * (constitutional admin-tunable, ADR-024). category=buildings, value_type=float.
 *
 * BuildingEffectsService::resolveLevelMultiplier линейно интерполирует L4-L9 между
 * существующим L3-ключом и этим L10-якорем (v = v3 + (v10−v3)×(L−3)/7). До ADR-042
 * L4-L10 каскадили на L3 (плато = «платишь до 1M золота за ноль»). Idempotent.
 */
class ADR042SeedBuildingL10AnchorGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'building.workshop.l10.craft_time_multiplier',
                'value_float'        => 0.55,
                'default_value_text' => '0.55',
                'rationale_text'     => 'Множитель времени крафта Мастерской на L10 (якорь кривой). L3=0.75 (−25%), L10=0.55 (−45%); L4-L9 интерполируются. Умеренный эндгейм-бонус за полную прокачку (применяется ко ВСЕМ рецептам).',
                'effect_text'        => 'getCraftTimeMultiplier × это на L10; L4-L9 линейно между 0.75 и 0.55. Стакается с Lab/Robotics.',
                'above_effect_text'  => 'При 0.40 (−60%) крафт на эндгейме почти мгновенный → обесценивает время как механику.',
                'below_effect_text'  => 'При 0.75 (=L3) возвращается плато — апгрейд L4-L10 снова бесполезен.',
                'recommended_min'    => '0.45',
                'recommended_max'    => '0.75',
                'hard_min'           => '0.1',
                'hard_max'           => '1.0',
            ],
            [
                'setting_key'        => 'building.laboratory.l10.craft_time_multiplier',
                'value_float'        => 0.55,
                'default_value_text' => '0.55',
                'rationale_text'     => 'Множитель времени медицинского крафта Лаборатории на L10 (якорь). L3=0.75, L10=0.55. Стакается с Мастерской (Workshop×Lab L10 = 0.30 = −70% медицина, экстремальный эндгейм).',
                'effect_text'        => 'getCraftTimeMultiplier (extraBuilding=Laboratory) × это на L10; L4-L9 интерполируются.',
                'above_effect_text'  => 'При 0.40 стак с Workshop даёт почти мгновенную медицину → ломает survival-баланс.',
                'below_effect_text'  => 'При 0.75 плато — апгрейд Лаборатории L4+ бесполезен.',
                'recommended_min'    => '0.45',
                'recommended_max'    => '0.75',
                'hard_min'           => '0.1',
                'hard_max'           => '1.0',
            ],
            [
                'setting_key'        => 'building.roboticsworkshop.l10.craft_time_multiplier',
                'value_float'        => 0.55,
                'default_value_text' => '0.55',
                'rationale_text'     => 'Множитель времени крафта роботов (Мастерская робототехники) на L10 (якорь). L3=0.75, L10=0.55. Стакается с Мастерской.',
                'effect_text'        => 'getCraftTimeMultiplier (extraBuilding=RoboticsWorkshop) × это на L10; L4-L9 интерполируются.',
                'above_effect_text'  => 'При 0.40 роботы крафтятся почти мгновенно на эндгейме.',
                'below_effect_text'  => 'При 0.75 плато — апгрейд бесполезен.',
                'recommended_min'    => '0.45',
                'recommended_max'    => '0.75',
                'hard_min'           => '0.1',
                'hard_max'           => '1.0',
            ],
            [
                'setting_key'        => 'building.blastfurnace.l10.craft_yield_multiplier',
                'value_float'        => 1.70,
                'default_value_text' => '1.70',
                'rationale_text'     => 'Множитель выхода металл-фрагментов (Доменная печь) на L10 (якорь). L3=1.35 (+35%), L10=1.70 (+70%); L4-L9 интерполируются. Эндгейм-бонус к выходу базового компонента.',
                'effect_text'        => 'getCraftYieldMultiplier × это на L10 для рецептов с boost_building=BlastFurnace.',
                'above_effect_text'  => 'При 3.0+ выход утраивается → инфляция металл-фрагментов.',
                'below_effect_text'  => 'При 1.35 (=L3) плато — апгрейд печи L4+ бесполезен.',
                'recommended_min'    => '1.35',
                'recommended_max'    => '2.5',
                'hard_min'           => '1.0',
                'hard_max'           => '5.0',
            ],
            [
                'setting_key'        => 'building.teleportationcenter.l10.teleport_cost_multiplier',
                'value_float'        => 0.45,
                'default_value_text' => '0.45',
                'rationale_text'     => 'Множитель цены телепорта (Центр телепортации) на L10 (якорь). L3=0.70 (−30%), L10=0.45 (−55%); L4-L9 интерполируются. (Лимит маяков масштабируется отдельно до L10.)',
                'effect_text'        => 'getTeleportCostMultiplier × это на L10; TeleportCostService множит базовую цену.',
                'above_effect_text'  => 'При 0.2 (−80%) телепорты почти бесплатны на эндгейме → теряется золото-sink перемещения.',
                'below_effect_text'  => 'При 0.70 (=L3) скидка перестаёт расти выше L3 (плато цены).',
                'recommended_min'    => '0.35',
                'recommended_max'    => '0.70',
                'hard_min'           => '0.1',
                'hard_max'           => '1.0',
            ],
            [
                'setting_key'        => 'building.greenhouse.l10.harvest_yield_multiplier',
                'value_float'        => 1.65,
                'default_value_text' => '1.65',
                'rationale_text'     => 'Множитель урожая активной посадки (Теплица) на L10 (якорь). L3=1.30, L10=1.65; L4-L9 интерполируются. (Пассив теплицы масштабируется отдельно через GameBalance.greenhouseLevels.)',
                'effect_text'        => 'getGreenhouseYieldMultiplier × это на L10; PlantCropCompletionHandler множит урожай.',
                'above_effect_text'  => 'При 3.0+ одна посадка заваливает едой → инфляция.',
                'below_effect_text'  => 'При 1.30 (=L3) плато — апгрейд теплицы не усиливает активный урожай.',
                'recommended_min'    => '1.30',
                'recommended_max'    => '2.5',
                'hard_min'           => '1.0',
                'hard_max'           => '5.0',
            ],
            [
                'setting_key'        => 'building.greenhouse.l10.grow_time_multiplier',
                'value_float'        => 0.60,
                'default_value_text' => '0.60',
                'rationale_text'     => 'Множитель времени роста активной посадки (Теплица) на L10 (якорь). L3=0.80, L10=0.60; L4-L9 интерполируются.',
                'effect_text'        => 'getGreenhouseGrowTimeMultiplier × это на L10; PlantCropActionStart множит время роста.',
                'above_effect_text'  => 'При 0.3 урожай почти мгновенный на эндгейме → спам-фарм.',
                'below_effect_text'  => 'При 0.80 (=L3) плато — апгрейд не ускоряет рост.',
                'recommended_min'    => '0.50',
                'recommended_max'    => '0.80',
                'hard_min'           => '0.1',
                'hard_max'           => '1.0',
            ],
        ];

        $defaults = [
            'category'        => 'buildings',
            'value_type'      => 'float',
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
        $this->db->table('game_settings')->whereIn('setting_key', [
            'building.workshop.l10.craft_time_multiplier',
            'building.laboratory.l10.craft_time_multiplier',
            'building.roboticsworkshop.l10.craft_time_multiplier',
            'building.blastfurnace.l10.craft_yield_multiplier',
            'building.teleportationcenter.l10.teleport_cost_multiplier',
            'building.greenhouse.l10.harvest_yield_multiplier',
            'building.greenhouse.l10.grow_time_multiplier',
        ])->delete();
    }
}
