<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W5 (ADR-064) — Caravan drone-offer GameSettings extension.
 *
 * Закрытие W1 dead promise (drone.scout.caravan_offer_chance существовал но
 * никто не читал — BUILT-BUT-DEAD) + расширение симметрии на 4 типа дронов.
 *
 * 7 новых ключей (drone.scout.caravan_offer_chance уже в W1 seed):
 *  - drone.cargo.caravan_offer_chance = 0.02 (2% per-spawn cron tick)
 *  - drone.repair.caravan_offer_chance = 0.02
 *  - drone.combat.caravan_offer_chance = 0.02
 *  - drone.scout.caravan_markup_multiplier = 3.0 (recipe.gold × 3)
 *  - drone.cargo.caravan_markup_multiplier = 3.0
 *  - drone.repair.caravan_markup_multiplier = 3.0
 *  - drone.combat.caravan_markup_multiplier = 3.0
 *
 * SpawnCaravanCron per spawn: после resource-pick, для каждого drone-типа
 * roll chance (mt_rand); первый matching заменяет offer payload. Combined
 * effective rate ≈ 1 drone-offer / ~12 часов при default 0.02 × 4 типа × 5
 * max_active × ~12 spawns/час → 1 hit / ~3 часа в среднем (но всего 5 active
 * caravans → drone-offer редко доступен на карте одновременно).
 *
 * Markup 3.0 = premium для не-крафтящих игроков. P5/P8 (whales) с накопленным
 * gold получают доступ без RoboticsWorkshop. P2/P3/P9 — не impacted (могут
 * крафтить дешевле через workshop).
 *
 * Idempotent: пропускает существующие setting_key.
 */
class W5SeedDroneCaravanOfferGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'drone.cargo.caravan_offer_chance',
                'category'           => 'resources',
                'value_type'         => 'float',
                'value_float'        => 0.02,
                'default_value_text' => '0.02',
                'rationale_text'     => 'Шанс (0..1) per-spawn cron tick что NPC-караван (V25 ADR-057) выставит готовый Cargo-drone вместо обычного редкого ресурса. 0.02 = 2 процента. SpawnCaravanCron при rolling: первый matching drone-тип заменяет offer payload. Симметрично scout/repair/combat — все 4 равны 0.02 default → ~1 drone-offer в среднем раз в несколько часов на карте (5 max_active + lifetime 120 мин).',
                'effect_text'        => 'DroneService::caravanOfferChanceFor("cargo") читает значение. SpawnCaravanCron::run() после pickRandomRareResource: mt_rand vs (chance × 10000). Если hit — INSERT с offer_type=drone_cargo, drone_type=DroneCargo, gold_price=computeDroneOfferGold("cargo").',
                'above_effect_text'  => 'При 0.5: половина каравaнов = cargo-drone offer → ломает rarity / floods market готовыми дронами без крафта.',
                'below_effect_text'  => 'При 0: cargo-drone никогда не предлагается каравaнами → закрытие пути для не-крафтящих P5/P8.',
                'recommended_min'    => '0.005',
                'recommended_max'    => '0.10',
                'hard_min'           => '0',
                'hard_max'           => '1',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'drone.repair.caravan_offer_chance',
                'category'           => 'resources',
                'value_type'         => 'float',
                'value_float'        => 0.02,
                'default_value_text' => '0.02',
                'rationale_text'     => 'Шанс (0..1) per-spawn cron tick что NPC-караван выставит готовый Repair-drone. 0.02 = 2 процента. Симметрично scout/cargo/combat — все 4 равны 0.02 default. Premium для не-крафтящих с накопленным gold.',
                'effect_text'        => 'DroneService::caravanOfferChanceFor("repair") читает значение. SpawnCaravanCron rolls перед resource-fallback.',
                'above_effect_text'  => 'При 0.5: половина каравaнов = repair-drone offer → trivializes RoboticsWorkshop L3 крафт.',
                'below_effect_text'  => 'При 0: repair-drone никогда не предлагается каравaнами.',
                'recommended_min'    => '0.005',
                'recommended_max'    => '0.10',
                'hard_min'           => '0',
                'hard_max'           => '1',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'drone.combat.caravan_offer_chance',
                'category'           => 'combat',
                'value_type'         => 'float',
                'value_float'        => 0.02,
                'default_value_text' => '0.02',
                'rationale_text'     => 'Шанс (0..1) per-spawn cron tick что NPC-караван выставит готовый Combat-drone. 0.02 = 2 процента. Premium tier (combat = L4 RoboticsWorkshop в крафте → caravan = премиум-альтернатива через gold). Один из 4 равных по default 0.02 для всех типов.',
                'effect_text'        => 'DroneService::caravanOfferChanceFor("combat") читает значение. SpawnCaravanCron rolls перед resource-fallback.',
                'above_effect_text'  => 'При 0.5: половина каравaнов = combat-drone offer → PvP мета смещается «купи дрон вместо стройки WT» → проблема за-баланса.',
                'below_effect_text'  => 'При 0: combat-drone никогда не предлагается каравaнами → не-крафтящие игроки не имеют доступа к PvP-defensive layer.',
                'recommended_min'    => '0.005',
                'recommended_max'    => '0.05',
                'hard_min'           => '0',
                'hard_max'           => '1',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'drone.scout.caravan_markup_multiplier',
                'category'           => 'resources',
                'value_type'         => 'float',
                'value_float'        => 3.0,
                'default_value_text' => '3.0',
                'rationale_text'     => 'Множитель recipe.gold для определения caravan-offer price scout-drone. 3.0 = 3-кратная цена vs crafting (но без ресурсов, только gold). Premium для не-крафтящих P5/P8 с накопленным gold (V21 dashboard: 108M накопленного gold на проде у топ-чаров).',
                'effect_text'        => 'CaravanService::computeDroneOfferGold("scout") = ceil(CraftRecipes::DroneScout.gold × markup). SpawnCaravanCron INSERT.gold_price = вычисленное.',
                'above_effect_text'  => 'При 10.0: цена 80000 за DroneScout (vs 8000 крафт) → BUILT-BUT-DEAD, никто не покупает.',
                'below_effect_text'  => 'При 1.0: цена = crafting gold-cost без markup → caravan дешевле крафта (т.к. ресурсов не нужно) → trivializes crafting.',
                'recommended_min'    => '2.0',
                'recommended_max'    => '5.0',
                'hard_min'           => '0.1',
                'hard_max'           => '20.0',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'drone.cargo.caravan_markup_multiplier',
                'category'           => 'resources',
                'value_type'         => 'float',
                'value_float'        => 3.0,
                'default_value_text' => '3.0',
                'rationale_text'     => 'Множитель recipe.gold для cargo-drone caravan-offer price. 3.0 default. Премиум-альтернатива RoboticsWorkshop L2 крафту.',
                'effect_text'        => 'CaravanService::computeDroneOfferGold("cargo") = ceil(CraftRecipes::DroneCargo.gold × markup).',
                'above_effect_text'  => 'При 10.0: BUILT-BUT-DEAD.',
                'below_effect_text'  => 'При 1.0: trivializes crafting.',
                'recommended_min'    => '2.0',
                'recommended_max'    => '5.0',
                'hard_min'           => '0.1',
                'hard_max'           => '20.0',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'drone.repair.caravan_markup_multiplier',
                'category'           => 'resources',
                'value_type'         => 'float',
                'value_float'        => 3.0,
                'default_value_text' => '3.0',
                'rationale_text'     => 'Множитель recipe.gold для repair-drone caravan-offer price. 3.0 default. Премиум-альтернатива RoboticsWorkshop L3 крафту.',
                'effect_text'        => 'CaravanService::computeDroneOfferGold("repair") = ceil(CraftRecipes::DroneRepair.gold × markup).',
                'above_effect_text'  => 'При 10.0: BUILT-BUT-DEAD.',
                'below_effect_text'  => 'При 1.0: trivializes crafting.',
                'recommended_min'    => '2.0',
                'recommended_max'    => '5.0',
                'hard_min'           => '0.1',
                'hard_max'           => '20.0',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'drone.combat.caravan_markup_multiplier',
                'category'           => 'combat',
                'value_type'         => 'float',
                'value_float'        => 3.0,
                'default_value_text' => '3.0',
                'rationale_text'     => 'Множитель recipe.gold для combat-drone caravan-offer price. 3.0 default. Премиум-альтернатива RoboticsWorkshop L4 крафту (capstone Phase 1).',
                'effect_text'        => 'CaravanService::computeDroneOfferGold("combat") = ceil(CraftRecipes::DroneCombat.gold × markup).',
                'above_effect_text'  => 'При 10.0: BUILT-BUT-DEAD.',
                'below_effect_text'  => 'При 1.0: trivializes crafting.',
                'recommended_min'    => '2.0',
                'recommended_max'    => '5.0',
                'hard_min'           => '0.1',
                'hard_max'           => '20.0',
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
                continue;
            }
            $this->db->table('game_settings')->insert($row);
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->whereIn('setting_key', [
                'drone.cargo.caravan_offer_chance',
                'drone.repair.caravan_offer_chance',
                'drone.combat.caravan_offer_chance',
                'drone.scout.caravan_markup_multiplier',
                'drone.cargo.caravan_markup_multiplier',
                'drone.repair.caravan_markup_multiplier',
                'drone.combat.caravan_markup_multiplier',
            ])
            ->delete();
    }
}
