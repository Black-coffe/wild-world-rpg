<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W4 (ADR-063) — Repair drone build. 7 GameSettings `drone.repair.*`
 * (category=resources) с rich rationale (ADR-024).
 *
 * Резолюции (см. ADR-063):
 *  - enabled=true (killswitch). При false: кнопка в Робот-меню остаётся в lock-state.
 *  - cost_fraction=0.6 — зеркало V19 RobotRepairService; используется как множитель
 *    в gold-formula = Σ(qty[res] × price[res] × cost_fraction × markup).
 *  - markup=1.2 — drone-overhead vs V23 NPC markup=1.5 (мягче, т.к. требуется крафт + battery).
 *  - min_cost_gold=10 — защита от 0-cost mathematical edge.
 *  - battery_drain_per_launch=100, battery_max=100 — 1 batch на полный заряд.
 *  - base_charge_minutes_per_full=240 (4 ч, slower vs cargo 3 ч — premium tool).
 *
 * Idempotent: пропускает существующие setting_key.
 */
class W4SeedDroneRepairGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'drone.repair.enabled',
                'category'           => 'resources',
                'value_type'         => 'bool',
                'value_bool'         => 1,
                'default_value_text' => 'true',
                'rationale_text'     => 'Killswitch слоя Repair-drone (W4 ADR-063). true — крафт DroneRepair доступен, batch-action отрабатывает, recharge-cron тикает по 3 типам (scout + cargo + repair). false — кнопка дрон-ремонтника в All-Robots остаётся в lock-state (правило #4 CLAUDE.md), action отвергает; скрафченные инстансы замораживаются с текущим зарядом до повторного включения. Не разрушает V19 ручной ремонт (RobotRepair: gold+ресурсы per-robot) — он остаётся живым параллельным путём.',
                'effect_text'        => 'DroneService::repairIsEnabled() читает это значение. Влияет: видимость кнопки «Дрон-ремонтник» в AllRobotsAction, gate в RepairDroneRunAction, no-op флаг для repair-type в DroneRechargeCron.',
                'above_effect_text'  => 'true: Repair-drone работает как описано в ADR-063 (gold-only batch на всех роботов чара с durability < base, 1 battery drain per launch, 4-ч recharge).',
                'below_effect_text'  => 'false: фича выключена, ресурсы потраченные на крафт DroneRepair не возвращаются (инстансы остаются в crafted_items_log с замороженным durability_count). V19 ручной ремонт работает без изменений.',
                'recommended_min'    => null,
                'recommended_max'    => null,
                'hard_min'           => null,
                'hard_max'           => null,
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'drone.repair.cost_fraction',
                'category'           => 'resources',
                'value_type'         => 'float',
                'value_float'        => 0.6,
                'default_value_text' => '0.6',
                'rationale_text'     => 'Доля template-ресурсов рецепта робота, учитываемая в gold-формуле ремонта. Зеркало V19 cost_fraction=0.6 (RobotRepairService): за полный recovery durability дрон-ремонтник конвертирует 60 процентов рецептурных ресурсов в gold через resources.price × markup. Игрок не платит ресурсами — только золотом. Сохраняет паритет «дрон выходит дешевле перекрафта», но за gold вместо resources.',
                'effect_text'        => 'DroneService::repairCostFraction() читает это значение. RepairDroneRunAction вычисляет: foreach robot: cost = Σ (recipe.resources[res].qty × resources.price[res]) × cost_fraction × dur_frac × markup. dur_frac = (base - current) / base.',
                'above_effect_text'  => 'При 1.0: полная конвертация — DroneRepair стоит как перекрафт за gold; для P8 с большим балансом всё ещё выгодно (никаких других потерь). При 1.5: дороже перекрафта → BUILT-BUT-DEAD.',
                'below_effect_text'  => 'При 0.2: дрон-ремонт почти бесплатен, V19 ручной (resources+gold) становится strictly worse → P3/casual забрасывают V19.',
                'recommended_min'    => '0.40',
                'recommended_max'    => '0.80',
                'hard_min'           => '0.05',
                'hard_max'           => '2.00',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'drone.repair.markup',
                'category'           => 'resources',
                'value_type'         => 'float',
                'value_float'        => 1.2,
                'default_value_text' => '1.2',
                'rationale_text'     => 'Drone-overhead markup сверх рыночной gold-стоимости ресурсов. 1.2 = +20 процентов (мягче V23 NPC markup=1.5: NPC платит наёмному рабочему, дрон ходит сам — overhead меньше, но не ноль: запчасти + износ слотов + аренда хранилища на базе). Делает DroneRepair дороже за gold-юнит, чем теоретический «free convert», но всё ещё выгоднее перекрафта при больших накопленных балансах (V21 dashboard: 108M gold накоплено на проде).',
                'effect_text'        => 'DroneService::repairMarkup() читает это значение. RepairDroneRunAction применяет к итоговой gold-сумме per-robot: cost_gold = ceil(Σ × cost_fraction × dur_frac × markup).',
                'above_effect_text'  => 'При 3.0: дрон-ремонт дороже V19 ручного в 3 раза — никто не использует, BUILT-BUT-DEAD. При 2.0: edge с эндгеймом — пока не оптимально.',
                'below_effect_text'  => 'При 0.5: дрон ремонтирует дешевле рыночной стоимости ресурсов — экономика ломается, P8 фармит роботов→разбирает→чинит для арбитража.',
                'recommended_min'    => '1.05',
                'recommended_max'    => '1.50',
                'hard_min'           => '0.10',
                'hard_max'           => '5.00',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'drone.repair.min_cost_gold',
                'category'           => 'resources',
                'value_type'         => 'int',
                'value_int'          => 10,
                'default_value_text' => '10',
                'rationale_text'     => 'Минимальный итоговый gold-cost batch-операции (защита от 0-cost mathematical edge). Если cumulative cost после применения cost_fraction/markup/dur_frac оказался ниже этого порога (например один робот с 99/100 durability и копеечным рецептом) — округляется вверх до min_cost_gold. Защищает от exploit «бесплатная конвертация».',
                'effect_text'        => 'DroneService::repairMinCostGold() читает это значение. RepairDroneRunAction применяет max(min_cost_gold, sum_of_individual_costs) к финальному списанию gold.',
                'above_effect_text'  => 'При 1000: дёшевые мелкие ремонты невыгодны → игрок копит до нескольких серьёзно изношенных роботов → batch reduces UX (стимул не использовать).',
                'below_effect_text'  => 'При 0: возможен edge с 0-cost ремонтом (например после rounding всё свалится в 0 для крошечной dur_diff) → теоретически infinite-use, фича обходит экономику.',
                'recommended_min'    => '1',
                'recommended_max'    => '100',
                'hard_min'           => '0',
                'hard_max'           => '10000',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'drone.repair.battery_drain_per_launch',
                'category'           => 'resources',
                'value_type'         => 'int',
                'value_int'          => 100,
                'default_value_text' => '100',
                'rationale_text'     => 'Сколько единиц durability_count вычитается из repair-инстанса при batch-запуске. 100 = равно battery_max → один batch на полный заряд. Симметрично scout/cargo: предсказуемая стоимость, исключает free-spam. Cooldown = battery recharge time (4 ч). За день active игрок (2 ч on-base) получает 0.5 charge → batch raз/2 дня; P8 с тяжёлой инфрой роботов получает rate-limit естественно.',
                'effect_text'        => 'DroneService::repairBatteryDrainPerLaunch() читает это значение. RepairDroneRunAction проверяет durability_count >= drain; если нет — отвергает старт. После успеха: UPDATE crafted_items_log SET durability_count = durability_count - drain WHERE id = log_id.',
                'above_effect_text'  => 'При 200 (выше battery_max=100): ни один скрафченный repair-дрон не запустится → BUILT-BUT-DEAD.',
                'below_effect_text'  => 'При 10: 10 batch операций на полный заряд → infinite-uptime ремонт, gold-sink теряет потенциал.',
                'recommended_min'    => '50',
                'recommended_max'    => '100',
                'hard_min'           => '1',
                'hard_max'           => '1000',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'drone.repair.battery_max',
                'category'           => 'resources',
                'value_type'         => 'int',
                'value_int'          => 100,
                'default_value_text' => '100',
                'rationale_text'     => 'Макс. заряд (= durability_count) одного repair-инстанса. 100 = один batch на полной зарядке. Каждый новый скрафченный DroneRepair получает durability_count = battery_max на crafted_items_log row.',
                'effect_text'        => 'crafted_items.DroneRepair.durability_count=100 при крафте. DroneRechargeCron generalize loop clamp до battery_max per type.',
                'above_effect_text'  => 'При 500: 5 batch операций на полный заряд → подрывает rate-limit, gold-sink становится спорадичен.',
                'below_effect_text'  => 'При 50 (ниже drain=100): ни один repair-дрон не запустится → BUILT-BUT-DEAD.',
                'recommended_min'    => '50',
                'recommended_max'    => '300',
                'hard_min'           => '1',
                'hard_max'           => '10000',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'drone.repair.base_charge_minutes_per_full',
                'category'           => 'resources',
                'value_type'         => 'int',
                'value_int'          => 240,
                'default_value_text' => '240',
                'rationale_text'     => 'За сколько минут on_base repair-дрон заряжается с 0 до battery_max. 240 мин = 4 часа (cargo=180 мин, repair медленнее — premium tool). Rate = 100/240 ≈ 0.42 charge/мин. P2-friendly: 30 мин/день on_base = 12.5 charge/день → 8 дней до full → 1 batch/8 дней. Active 2 ч/день = ~1 batch/2 дня. На full timer 24/7 on-base = 6 batches/день — естественный rate-limit для эндгейма.',
                'effect_text'        => 'DroneRechargeCron generalize (W4) per-type re-read base_charge_minutes_per_full. Lazy fallback при read учитывает время с last_charge если cron пропустил тики.',
                'above_effect_text'  => 'При 1440 (24 ч/full): один batch/день — недостаточно для P8 эндгейма с парком 10+ роботов, gold-sink не реализуется.',
                'below_effect_text'  => 'При 60 мин: 24 batches/день → unlimited gold-sink → ускоряет infinite-uptime ремонта, экономика теряет cooldown.',
                'recommended_min'    => '120',
                'recommended_max'    => '720',
                'hard_min'           => '1',
                'hard_max'           => '10080',
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
                'drone.repair.enabled',
                'drone.repair.cost_fraction',
                'drone.repair.markup',
                'drone.repair.min_cost_gold',
                'drone.repair.battery_drain_per_launch',
                'drone.repair.battery_max',
                'drone.repair.base_charge_minutes_per_full',
            ])
            ->delete();
    }
}
