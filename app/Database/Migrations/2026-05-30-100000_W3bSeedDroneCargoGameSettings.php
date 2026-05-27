<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W3b (ADR-060) — Cargo drone build. 5 GameSettings `drone.cargo.*`
 * (category=resources) с rich rationale (ADR-024).
 *
 * Резолюции (см. ADR-060):
 *  - payload_kg=30 default (30% L1-cap, стратегичнее 50 — игрок приоритизирует).
 *  - battery_drain_per_launch=100 (= battery_max → 1 доставка на полный заряд).
 *  - battery_max=100 (как scout).
 *  - base_charge_minutes_per_full=180 (cargo тяжелее scout, 3 ч vs 2 ч).
 *  - enabled=true default (W3a weight_capacity=9999 off → cargo полезен как
 *    «переложить из инвентаря в склад» без отдельного UI на базе).
 *
 * Idempotent: пропускает существующие setting_key.
 */
class W3bSeedDroneCargoGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'drone.cargo.enabled',
                'category'           => 'resources',
                'value_type'         => 'bool',
                'value_bool'         => 1,
                'default_value_text' => 'true',
                'rationale_text'     => 'Killswitch всего слоя Cargo-drone (W3b ADR-060). true — крафт DroneCargo доступен, action отправки отрабатывает, recharge-cron тикает по обоим типам (scout + cargo). false — кнопка карго-дрона в move-keyboard остаётся в lock-state (правило #4 CLAUDE.md), action отвергает, скрафченные дроны замораживаются с текущим зарядом до повторного включения.',
                'effect_text'        => 'DroneService::cargoIsEnabled() читает это значение. Влияет: видимость кнопки в move-keyboard, gate в CargoDroneSendAction, no-op флаг для cargo-type в DroneRechargeCron.',
                'above_effect_text'  => 'true: Cargo-drone работает как описано в ADR-060 (per-instance battery, on-base recharge, 30 kg payload, доставка в base_storage с любой клетки).',
                'below_effect_text'  => 'false: фича выключена, ресурсы потраченные на крафт DroneCargo не возвращаются (инстансы остаются в crafted_items_log с замороженным durability_count). Не разрушает scout-слой.',
                'recommended_min'    => null,
                'recommended_max'    => null,
                'hard_min'           => null,
                'hard_max'           => null,
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'drone.cargo.payload_kg',
                'category'           => 'resources',
                'value_type'         => 'int',
                'value_int'          => 30,
                'default_value_text' => '30',
                'rationale_text'     => 'Сколько килограмм ресурсов карго-дрон переносит на базу за один вылет. 30 kg = 30 процентов L1-cap (100 kg в WeightCapacityService). Стратегичный выбор vs 50 kg: игрок приоритизирует тяжёлые ресурсы (камни 1.0 kg/unit vs древесина 0.05 kg/unit). Защищает weight-cap от trivialization когда механика будет включена.',
                'effect_text'        => 'CargoDroneSendAction (W3b) вычисляет qty к отправке: floor(payload_kg / resource.weight), затем clamp по реальному запасу в character_resources. DB-транзакция: decrement char_resources, BaseStorageModel::deliver, drain durability_count.',
                'above_effect_text'  => 'При 200 kg: 2× L1-cap → одна доставка очищает весь инвентарь, weight-cap теряет смысл, П8/optimizer не вынужден приоритизировать груз.',
                'below_effect_text'  => 'При 5 kg: тонкий ручеёк — несколько камней за вылет, recharge 3 ч → разочарование, П1/casual бросает фичу.',
                'recommended_min'    => '20',
                'recommended_max'    => '60',
                'hard_min'           => '1',
                'hard_max'           => '500',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'drone.cargo.battery_drain_per_launch',
                'category'           => 'resources',
                'value_type'         => 'int',
                'value_int'          => 100,
                'default_value_text' => '100',
                'rationale_text'     => 'Сколько единиц durability_count вычитается из cargo-инстанса при отправке. 100 = равно battery_max → одна доставка на полный заряд. Симметрично scout-механике: предсказуемая стоимость, исключает free-spam.',
                'effect_text'        => 'CargoDroneSendAction (W3b) проверяет durability_count >= drain; если нет — отвергает старт. После успеха: UPDATE crafted_items_log SET durability_count = durability_count - drain WHERE id = log_id.',
                'above_effect_text'  => 'При 200 (выше battery_max=100): ни один скрафченный карго-дрон не отправится → BUILT-BUT-DEAD.',
                'below_effect_text'  => 'При 10: 10 отправок на полный заряд → 300 kg/цикл = вес-полный-инвентарь L25 за 3 ч зарядки → weight-cap игнорируется.',
                'recommended_min'    => '50',
                'recommended_max'    => '100',
                'hard_min'           => '1',
                'hard_max'           => '1000',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'drone.cargo.battery_max',
                'category'           => 'resources',
                'value_type'         => 'int',
                'value_int'          => 100,
                'default_value_text' => '100',
                'rationale_text'     => 'Макс. заряд (= durability_count) одного карго-инстанса. 100 = одна полная зарядка. Каждый новый скрафченный карго-дрон получает durability_count = battery_max на crafted_items_log row.',
                'effect_text'        => 'CraftedItemsModel при создании drone-row устанавливает durability_count = battery_max (W3b: crafted_items.DroneCargo.durability_count=100). DroneRechargeCron generalize loop clamp до battery_max per type.',
                'above_effect_text'  => 'При 500: 5 доставок на полный заряд → 150 kg/3 ч → trivializes weight-cap.',
                'below_effect_text'  => 'При 50 (ниже drain=100): ни один карго-дрон не отправится → BUILT-BUT-DEAD.',
                'recommended_min'    => '50',
                'recommended_max'    => '300',
                'hard_min'           => '1',
                'hard_max'           => '10000',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'drone.cargo.base_charge_minutes_per_full',
                'category'           => 'resources',
                'value_type'         => 'int',
                'value_int'          => 180,
                'default_value_text' => '180',
                'rationale_text'     => 'За сколько минут on_base карго-дрон заряжается с 0 до battery_max. 180 мин = 3 часа (cargo тяжелее scout: 120→180). Rate = 100/180 ≈ 0.56 charge/мин. P2-friendly: 30 мин/день on_base = 17 charge/день → ~6 дней до full → 1 доставка/6 дней. Active игрок 2 ч/день = ~1 доставка/2 дня.',
                'effect_text'        => 'DroneRechargeCron generalize (W3b) per-type re-read base_charge_minutes_per_full. Lazy fallback при read учитывает время с last_charge если cron пропустил тики.',
                'above_effect_text'  => 'При 1440 (24 ч): почти всегда «заряд не готов», фича становится разочарованием.',
                'below_effect_text'  => 'При 30 мин: 4 доставки/час → trivializes weight-cap при включении.',
                'recommended_min'    => '90',
                'recommended_max'    => '480',
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
                'drone.cargo.enabled',
                'drone.cargo.payload_kg',
                'drone.cargo.battery_drain_per_launch',
                'drone.cargo.battery_max',
                'drone.cargo.base_charge_minutes_per_full',
            ])
            ->delete();
    }
}
