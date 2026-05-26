<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W1 (ADR-058) — старт Фазы 1 vNext2 — Drone-recon foundation. 6 GameSettings
 * `drone.scout.*` (category=resources) с rich rationale (ADR-024). W1 — split:
 * только seed + crafted_items + LEXICON + image-tail; полный build (DroneService /
 * RecceDroneAction / DroneRechargeCron / DroneScoutCraftedListAction) — в W2.
 *
 * 6 резолюций (см. ADR-058 §«Открытые вопросы»):
 *  1. Battery = per-drone (= crafted_items_log.durability_count, V18 паттерн).
 *  2. Recharge = N мин on_base через cron + lazy fallback (default 120 мин/полный).
 *  3. FOV scan = mark explored + biome (revealAround simple, ADR-019 compat).
 *  4. Caravan = редкий рецепт-чертёж (gold-sink, не готовый дрон).
 *  5. PvP = запрет в MVP (anti-snooping; V14 «Поход» даёт scout пешком).
 *  6. Type = durability-battery (durability_count = charge 0..max, drain at launch).
 *
 * Constitutional rule (CLAUDE.md §🎛️ ADR-024): rationale/effect/above/below NOT NULL.
 * Idempotent: пропускает существующие setting_key.
 */
class W1SeedDroneScoutGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'drone.scout.enabled',
                'category'           => 'resources',
                'value_type'         => 'bool',
                'value_bool'         => 1,
                'default_value_text' => 'true',
                'rationale_text'     => 'Killswitch всего слоя Drone-recon (foundation W1 фазы Drone-family). true — крафт DroneScout доступен, action запуска отрабатывает, recharge-cron тикает. false — мгновенный откат фичи без redeploy: кнопка «🚁 Запустить» скрыта, action отвергает, cron no-op, скрафченные дроны остаются на руках с полным зарядом до повторного включения.',
                'effect_text'        => 'DroneService::isEnabled (W2 build). Влияет на видимость кнопки запуска, gate в RecceDroneAction, no-op флаг DroneRechargeCron и (W5) caravan-offer чертежа.',
                'above_effect_text'  => 'true: Drone-recon работает как описано в ADR-058 (per-instance battery, on-base recharge, scan через revealAround).',
                'below_effect_text'  => 'false: фича выключена, ресурсы потраченные на крафт не возвращаются (drone-инстансы остаются в crafted_items_log с замороженным durability_count). Не разрушает другие системы.',
                'recommended_min'    => null,
                'recommended_max'    => null,
                'hard_min'           => null,
                'hard_max'           => null,
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'drone.scout.radius_cells',
                'category'           => 'resources',
                'value_type'         => 'int',
                'value_int'          => 10,
                'default_value_text' => '10',
                'rationale_text'     => 'Радиус scan-зоны при запуске Drone-recon (метрика Чебышёва, ADR-007). 10 = окно 21×21 = до 441 клетки за один запуск; user-formulation 2026-05-26: «~10 ячеек в радиусе». Покрытие близкое, но не imba: V14 «Поход» (ADR-019) даёт radius=1 за тик движения — дрон выгоднее когда нужен большой обзор без передвижения.',
                'effect_text'        => 'RecceDroneAction (W2) передаёт это значение в ExploredCellsModel::revealAround как параметр radius. Каждая клетка из 21×21 окна маркируется как explored (idempotent, ADR-019 compat).',
                'above_effect_text'  => 'При 20: окно 41×41 = до 1681 клетки за запуск → туман войны раскрывается слишком быстро, V14 «Поход» теряет смысл, разведка пешком обесценивается.',
                'below_effect_text'  => 'При 3: окно 7×7 = 49 клеток за запуск → дрон не оправдывает крафт (V14 за пеший Поход открывает почти столько же при движении), фича становится BUILT-BUT-DEAD ощущением.',
                'recommended_min'    => '5',
                'recommended_max'    => '15',
                'hard_min'           => '1',
                'hard_max'           => '20',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'drone.scout.battery_drain_per_launch',
                'category'           => 'resources',
                'value_type'         => 'int',
                'value_int'          => 100,
                'default_value_text' => '100',
                'rationale_text'     => 'Сколько единиц `durability_count` (= battery) вычитается из crafted_items_log при каждом запуске дрона. 100 = равно battery_max → 1 запуск на полный заряд (затем requires recharge on_base). Не grind, не free-spam.',
                'effect_text'        => 'RecceDroneAction (W2) проверяет durability_count >= drain; если нет — отвергает старт. Если есть — UPDATE crafted_items_log SET durability_count = durability_count - drain WHERE id = <log_id>.',
                'above_effect_text'  => 'При 200 (выше battery_max=100): ни один скрафченный дрон не запустится → фича становится BUILT-BUT-DEAD. Также нарушает контракт «1 scan на 120 мин on_base».',
                'below_effect_text'  => 'При 10: 10 запусков на полный заряд → recharge-цикл 120 мин даёт 10 scan-запусков → 4410 клеток (21×21 × 10) за цикл → разведка слишком дёшева, ползёт инфляция fog-of-war.',
                'recommended_min'    => '50',
                'recommended_max'    => '100',
                'hard_min'           => '1',
                'hard_max'           => '1000',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'drone.scout.battery_max',
                'category'           => 'resources',
                'value_type'         => 'int',
                'value_int'          => 100,
                'default_value_text' => '100',
                'rationale_text'     => 'Макс. заряд (= `durability_count`) одного дрон-инстанса. 100 = одна полная зарядка. Каждый новый скрафченный дрон получает durability_count = battery_max на crafted_items_log row (как у роботов: durability=полное при крафте). Recharge-cron clamp:ит durability_count к battery_max.',
                'effect_text'        => 'CraftedItemsModel при создании drone-row установит durability_count = battery_max (W2 build обеспечит соответствие через `crafted_items.durability_count` field; см. миграцию `W1AddDroneScoutItem`). DroneRechargeCron (W2): UPDATE durability_count = LEAST(battery_max, durability_count + rate × N).',
                'above_effect_text'  => 'При 500: 5 запусков на полный заряд → если drain=100, фича становится спамом разведки (5 × 441 = 2205 клеток на 120 мин зарядки).',
                'below_effect_text'  => 'При 50 (ниже drain=100): ни один дрон не запустится → BUILT-BUT-DEAD.',
                'recommended_min'    => '50',
                'recommended_max'    => '300',
                'hard_min'           => '1',
                'hard_max'           => '10000',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'drone.scout.base_charge_minutes_per_full',
                'category'           => 'resources',
                'value_type'         => 'int',
                'value_int'          => 120,
                'default_value_text' => '120',
                'rationale_text'     => 'За сколько минут on_base дрон заряжается с 0 до battery_max. 120 мин = 2 часа (user-formulation: «когда игрок на базе проводит больше N часов»). Rate (charge/мин) = battery_max / minutes_per_full = 100/120 ≈ 0.83. P2-friendly: 30 мин/день on_base = 25 charge/день → 4 дня до full → 1 scan/4 дня. Active игрок 2 ч/день = 1 scan/день.',
                'effect_text'        => 'DroneRechargeCron (W2): every-N-min для каждого on_base/covered чара с drone-инстансом → UPDATE durability_count = LEAST(battery_max, durability + (battery_max / minutes_per_full) × N). Lazy fallback при read (RecceDroneAction) — учитывает время с last_charge_at если cron пропустил тики.',
                'above_effect_text'  => 'При 1440 (24 часа): дрон заряжается сутки → почти всегда «заряд не готов», фича становится разочарованием.',
                'below_effect_text'  => 'При 10 мин: заряд почти instant → дрон спам, разведка тривиальна, fog-of-war ломается.',
                'recommended_min'    => '60',
                'recommended_max'    => '480',
                'hard_min'           => '1',
                'hard_max'           => '10080',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'drone.scout.caravan_offer_chance',
                'category'           => 'resources',
                'value_type'         => 'float',
                'value_float'        => 0.02,
                'default_value_text' => '0.02',
                'rationale_text'     => 'Шанс что NPC-караван (V25, ADR-057) выставит редкий рецепт-чертёж DroneScout в offer (резолюция #4 ADR-058). 0.02 = 2% per-spawn — редкое, gold-sink unlock рецепта без замены крафта. W1 ничего не читает (фича — W5 «Caravan integration», 🏁 закрытие Фазы 1). Выставлен сейчас для consistency.',
                'effect_text'        => 'SpawnCaravanCron (W5 enhancement): на spawn-этапе каравана rolls vs этот chance — если выпало, offer = blueprint вместо ресурса. CaravanBuyAction (W5) применит unlock рецепта к чару (раз и навсегда).',
                'above_effect_text'  => 'При 0.3: 30% каравана = чертёж → быстрое unlock рецепта обходит Workshop progression (П9 anti-P2W нарушение).',
                'below_effect_text'  => 'При 0.001: чертёж почти никогда не появляется → фича становится мифом, redundant с прямым крафтом.',
                'recommended_min'    => '0.01',
                'recommended_max'    => '0.10',
                'hard_min'           => '0.00',
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
                'drone.scout.enabled',
                'drone.scout.radius_cells',
                'drone.scout.battery_drain_per_launch',
                'drone.scout.battery_max',
                'drone.scout.base_charge_minutes_per_full',
                'drone.scout.caravan_offer_chance',
            ])
            ->delete();
    }
}
