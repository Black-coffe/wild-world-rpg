<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Story transport-17 (`docs/specs/transport-system/transport-17-drone-recharge-on-base.md`) —
 * ADR-174: единственное механическое отличие Инженеров — «заряд на базе, не ремонт».
 * `DroneRechargeCron` уже читает `world.vehicle.drone_auto.charges_full` (посеян story
 * transport-02) как потолок заряда; эта миграция добавляет НОВЫЙ ключ — ставку заряда
 * в минуту, которой раньше не существовало ни в игре, ни в GameSettings.
 *
 * Дефолт 1.2 заряда/мин выбран сравнением с уже живыми дронами (все читают из
 * `drone.<type>.base_charge_minutes_per_full`, разворачивая в rate = max / minutes):
 * scout ~0.833/мин (2ч на 100), cargo ~0.556/мин (3ч на 100), repair/combat ~0.417/мин
 * (4-6ч на 100). Транспортный дрон несёт гораздо больший потолок (350, не 100), поэтому
 * прямое сравнение ставок вводит в заблуждение — важно время до полного заряда: при
 * 1.2/мин 350 зарядов набегают за ~292 минуты (~4ч52м) — дольше scout, но заметно
 * короче суток; попадает в тот же диапазон «несколько часов», что и repair/combat,
 * не требуя от игрока сидеть на базе сутки и не заряжаясь мгновенно.
 *
 * Idempotent (как SeedVehicleGameSettings) — по `setting_key`. `game_settings` = KEEP
 * (WipeManifest не трогаем — новых таблиц/колонок нет).
 */
class SeedDroneAutoChargeRate extends Migration
{
    private const KEY = 'world.vehicle.drone_auto.charge_per_minute';

    public function up(): void
    {
        $exists = $this->db->table('game_settings')->where('setting_key', self::KEY)->get()->getRowArray();
        if (! empty($exists)) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        $this->db->table('game_settings')->insert([
            'setting_key'        => self::KEY,
            'category'           => 'world',
            'value_type'         => 'float',
            'value_float'        => 1.2,
            'value_int'          => null,
            'value_bool'         => null,
            'value_string'       => null,
            'default_value_text' => '1.2',
            'rationale_text'     => '🛸 Автономный дрон (Инженеры) — ставка заряда durability_count/мин на своей базе (ADR-174: «заряд на базе, не ремонт» — единственное механическое отличие фракции). 1.2/мин при потолке world.vehicle.drone_auto.charges_full=350 даёт полный заряд с нуля примерно за 4ч52м — дольше scout-дрона (2ч на 100), но заметно короче суток, ближе к repair/combat дронам (4-6ч на 100) с их вспомогательной ролью.',
            'effect_text'        => 'DroneRechargeCron::run() читает через DroneService::droneAutoChargeRatePerMinute() пятым типом (AutonomousDrone) по тому же контракту, что scout/cargo/repair/combat: durability_count += rate × interval_minutes, зажато сверху charges_full, только пока персонаж на своей активной базе.',
            'above_effect_text'  => 'Выше — транспортный дрон восстанавливается заметно быстрее остальных дронов при том же потолке заряда, зависимость Инженеров от энергии перестаёт ощущаться (заряд почти мгновенный относительно похода).',
            'below_effect_text'  => 'Ниже — полный цикл растягивается на сутки и больше, дрон эффективно превращается в одноразовую машину до следующего дня — экономика ремонта (Non-goals: ремонт остаётся живым путём) станет единственным практичным вариантом, «заряд на базе» перестанет быть рабочей альтернативой.',
            'recommended_min'    => '0.50',
            'recommended_max'    => '3.00',
            'hard_min'           => '0.10',
            'hard_max'           => '10.00',
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_settings')->where('setting_key', self::KEY)->delete();
    }
}
