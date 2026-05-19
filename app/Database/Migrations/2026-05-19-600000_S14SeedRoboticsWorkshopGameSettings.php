<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S14 (v0.51.196) — seed GameSettings keys для RoboticsWorkshop level effects
 * (ROADMAP-CRAFT §6, але pure-multiplier scope замість T2/T3 robot recipes).
 *
 * **Audit drift (2026-05-19).** ROADMAP описав premise «робото-час працює тільки
 * level=1, треба полагодити». Реальність: формула `6 × workshopLevel` (Explorer)
 * і `2 × workshopLevel` (Gatherer) у `StartRobotExploration/GatheringAction` вже
 * скейлиться лінійно. На проді існують RoboticsWorkshop L1..L10 (6 чарів). Premise
 * хибний; ремонтувати нема чого. T2/T3 robot recipes — окрема велика робота (4
 * нові крафти + 4 completion handlers + 6 images) для дуже нішевої audience
 * (~6 крафтерів роботів / 358 чарів), відкладено на майбутню фазу.
 *
 * **Реальний gap, який закриваємо S14.** RoboticsWorkshop L2/L3 досі pure
 * cosmetic для **процесу крафту самих роботів** (recipe time formula не залежала
 * від building level — лише work time після запуску). Pattern reuse S13a
 * Laboratory: recipe декларує `boost_building_time => 'RoboticsWorkshop'`,
 * multiplier стак з Workshop (S11).
 *
 * **Який ефект на проді.** RobotExplorer + RobotGatherer recipes отримують
 * `boost_building_time => 'RoboticsWorkshop'`. Чар з Robotics L2 крафтить роботів
 * на -10% швидше; L3 → -25%. Stack з Workshop: L3+L3 → 0.75 × 0.75 = 0.5625 (-44%).
 * L4-L10 plateau cascade на L3 (як S11/S12/S13).
 *
 * Constitutional rule (CLAUDE.md §🎛️ ADR-024): rationale/effect/above/below NOT NULL.
 * Idempotent: пропускає існуючі setting_key.
 */
class S14SeedRoboticsWorkshopGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'building.roboticsworkshop.l2.craft_time_multiplier',
                'category'           => 'buildings',
                'value_type'         => 'float',
                'value_float'        => 0.90,
                'default_value_text' => '0.90',
                'rationale_text'     => '-10% часу крафту роботів на L2 RoboticsWorkshop — стимулює гравців-краферів роботів інвестувати у L2 апгрейд. Без цього L2 був корисний лише для work duration (6× / 2× level). Тепер L2 = і швидший крафт, і довші робото-сесії.',
                'effect_text'        => 'Множник craft duration для recipes з `boost_building_time => RoboticsWorkshop` у GenericCraftActionStart::calculateCraftingDuration(). Multiplicative stack з Workshop (S11). На сьогодні застосовується до 2 рецептів: RobotExplorer, RobotGatherer.',
                'above_effect_text'  => 'При 0.95 — ефект 5% непомітний (робот-крафт ~хвилини), L2 апгрейд не виправданий лише для craft-time bonus.',
                'below_effect_text'  => 'При 0.70 — L2 сильніше за S11 Workshop L3 (-25%), Robotics L3 (-25%) втрачає сенс прогресії.',
                'recommended_min'    => '0.80',
                'recommended_max'    => '0.95',
                'hard_min'           => '0.10',
                'hard_max'           => '1.00',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'building.roboticsworkshop.l3.craft_time_multiplier',
                'category'           => 'buildings',
                'value_type'         => 'float',
                'value_float'        => 0.75,
                'default_value_text' => '0.75',
                'rationale_text'     => '-25% часу крафту роботів на L3 RoboticsWorkshop — крупна винагорода для роботного фокусу. Stack з Workshop L3: 0.75 × 0.75 = 0.5625 (-44% для робот-крафту). L4-L10 plateau cascade.',
                'effect_text'        => 'Множник craft duration для RobotExplorer/RobotGatherer. Workshop L3 + Robotics L3 → craft duration = base × 0.75 × 0.75 = 0.5625 × base. Min clamp 1 хв.',
                'above_effect_text'  => 'При 0.85 — L3 = +5% над L2, погана прогресія, гравці не відчувають крупний апгрейд між L2 та L3.',
                'below_effect_text'  => 'При 0.50 — робот-крафт стає near-instant зі Workshop+Robotics stack (0.50 × 0.75 = 0.375, -62%), ламає progression timing.',
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
                'building.roboticsworkshop.l2.craft_time_multiplier',
                'building.roboticsworkshop.l3.craft_time_multiplier',
            ])
            ->delete();
    }
}
