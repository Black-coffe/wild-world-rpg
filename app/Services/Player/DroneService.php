<?php

declare(strict_types=1);

namespace App\Services\Player;

use App\Services\GameSettings\GameSettingsService;

/**
 * W1/W2 (ADR-058) — Drone-recon foundation. Pure GameSettings-reader
 * (зеркало V25 CaravanService / V18 RobotService).
 *
 * Дрон-разведчик = ручной разведывательный квадрокоптер. Per-instance battery
 * (= crafted_items_log.durability_count) — V18 паттерн полный re-use, zero new column.
 *
 * Балансировочные knob'ы (live-tunable, ADR-024, category=resources):
 *  - drone.scout.enabled (bool, killswitch)
 *  - drone.scout.radius_cells (int=10)
 *  - drone.scout.battery_drain_per_launch (int=100)
 *  - drone.scout.battery_max (int=100)
 *  - drone.scout.base_charge_minutes_per_full (int=120)
 *  - drone.scout.caravan_offer_chance (float=0.02, для W5)
 */
final class DroneService
{
    private GameSettingsService $settings;

    public function __construct(?GameSettingsService $settings = null)
    {
        $this->settings = $settings ?? new GameSettingsService();
    }

    /**
     * Killswitch всего слоя Drone-recon. false → action отвергает, cron no-op,
     * кнопка скрыта; скрафченные дроны остаются с замороженным durability_count.
     */
    public function isEnabled(): bool
    {
        $v = $this->settings->get('drone.scout.enabled', true);
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Радиус scan-зоны (Чебышёв, ADR-019 compat). Передаётся в
     * ExploredCellsModel::revealAround() при запуске дрона. Default 10 (окно 21×21).
     */
    public function radiusCells(): int
    {
        $v = $this->settings->get('drone.scout.radius_cells', 10);
        return is_numeric($v) && (int) $v >= 1 ? (int) $v : 10;
    }

    /**
     * Сколько единиц durability_count вычитается при запуске. Default 100 =
     * 1 запуск на полный заряд (с дефолтным battery_max=100).
     */
    public function batteryDrainPerLaunch(): int
    {
        $v = $this->settings->get('drone.scout.battery_drain_per_launch', 100);
        return is_numeric($v) && (int) $v >= 1 ? (int) $v : 100;
    }

    /**
     * Максимум charge (= новое значение durability_count при крафте).
     * Cron-recharge clamp'ит durability_count к этому потолку.
     */
    public function batteryMax(): int
    {
        $v = $this->settings->get('drone.scout.battery_max', 100);
        return is_numeric($v) && (int) $v >= 1 ? (int) $v : 100;
    }

    /**
     * За сколько минут on_base дрон заряжается с 0 до battery_max. Default 120 (2 ч).
     * P2-friendly: 30 мин/день on_base → 4 дня до full → 1 scan/4 дня.
     */
    public function chargeMinutesPerFull(): int
    {
        $v = $this->settings->get('drone.scout.base_charge_minutes_per_full', 120);
        return is_numeric($v) && (int) $v >= 1 ? (int) $v : 120;
    }

    /**
     * Charge per minute (computed). Используется DroneRechargeCron для UPDATE
     * durability_count += rate × interval_minutes (clamp battery_max).
     */
    public function chargeRatePerMinute(): float
    {
        $minutes = $this->chargeMinutesPerFull();
        if ($minutes <= 0) {
            return 0.0;
        }
        return $this->batteryMax() / $minutes;
    }

    /**
     * Шанс что NPC-караван (V25, ADR-057) выставит blueprint DroneScout в offer.
     * 0.02 = 2% per-spawn. W5 (Caravan integration) читает это значение.
     */
    public function caravanOfferChance(): float
    {
        $v = $this->settings->get('drone.scout.caravan_offer_chance', 0.02);
        if (! is_numeric($v)) {
            return 0.02;
        }
        $f = (float) $v;
        if ($f < 0) {
            return 0.0;
        }
        if ($f > 1) {
            return 1.0;
        }
        return $f;
    }

    /**
     * Можно ли запустить дрон с этим зарядом. true если durability >= drain
     * И killswitch включён.
     */
    public function canLaunch(int $currentCharge): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }
        return $currentCharge >= $this->batteryDrainPerLaunch();
    }

    // ──────────────────────────────────────────────────────────────────
    // W3b (ADR-060) — Cargo drone extension. Параллельные knob'ы под
    // префиксом `cargo*`, читают из `drone.cargo.*` GameSettings.
    // ──────────────────────────────────────────────────────────────────

    /**
     * Killswitch cargo-слоя (W3b). Независим от scout-slayer'а.
     * false → CargoDroneSendAction отвергает, кнопка в move-keyboard остаётся
     * в lock-state, DroneRechargeCron пропускает cargo-инстансы.
     */
    public function cargoIsEnabled(): bool
    {
        $v = $this->settings->get('drone.cargo.enabled', true);
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Полезная нагрузка карго-дрона за один вылет (kg). Default 30.
     * CargoDroneSendAction вычисляет qty = floor(payload_kg / resource.weight),
     * затем clamp по реальному запасу в character_resources.
     */
    public function cargoPayloadKg(): int
    {
        $v = $this->settings->get('drone.cargo.payload_kg', 30);
        return is_numeric($v) && (int) $v >= 1 ? (int) $v : 30;
    }

    /**
     * Сколько единиц durability_count вычитается при отправке cargo. Default 100.
     */
    public function cargoBatteryDrainPerLaunch(): int
    {
        $v = $this->settings->get('drone.cargo.battery_drain_per_launch', 100);
        return is_numeric($v) && (int) $v >= 1 ? (int) $v : 100;
    }

    /**
     * Макс. заряд одного cargo-инстанса (= durability_count при крафте). Default 100.
     */
    public function cargoBatteryMax(): int
    {
        $v = $this->settings->get('drone.cargo.battery_max', 100);
        return is_numeric($v) && (int) $v >= 1 ? (int) $v : 100;
    }

    /**
     * За сколько минут on_base cargo-дрон заряжается с 0 до battery_max. Default 180.
     */
    public function cargoChargeMinutesPerFull(): int
    {
        $v = $this->settings->get('drone.cargo.base_charge_minutes_per_full', 180);
        return is_numeric($v) && (int) $v >= 1 ? (int) $v : 180;
    }

    /**
     * Charge per minute для cargo (computed). DroneRechargeCron generalize
     * читает per-type для UPDATE durability_count += rate × interval.
     */
    public function cargoChargeRatePerMinute(): float
    {
        $minutes = $this->cargoChargeMinutesPerFull();
        if ($minutes <= 0) {
            return 0.0;
        }
        return $this->cargoBatteryMax() / $minutes;
    }

    /**
     * Можно ли отправить cargo с этим зарядом. true если durability >= drain
     * И cargoIsEnabled.
     */
    public function canSendCargo(int $currentCharge): bool
    {
        if (! $this->cargoIsEnabled()) {
            return false;
        }
        return $currentCharge >= $this->cargoBatteryDrainPerLaunch();
    }

    // ──────────────────────────────────────────────────────────────────
    // W4 (ADR-063) — Repair drone extension. Параллельные knob'ы под
    // префиксом `repair*`, читают из `drone.repair.*` GameSettings.
    // Gold-only batch ремонтник: один клик чинит ВСЕХ роботов чара с
    // current<base до base за 1 battery drain. V19 RobotRepair остаётся
    // живым (resources+gold, per-robot, мгновенно) как параллельный путь.
    // ──────────────────────────────────────────────────────────────────

    /**
     * Killswitch repair-слоя (W4). Независим от scout/cargo killswitch'ей.
     * false → RepairDroneRunAction отвергает, кнопка в Робот-меню остаётся
     * в lock-state, DroneRechargeCron пропускает repair-инстансы.
     */
    public function repairIsEnabled(): bool
    {
        $v = $this->settings->get('drone.repair.enabled', true);
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Доля template-ресурсов рецепта робота, учитываемая в gold-формуле ремонта.
     * Default 0.6 (зеркало V19 RobotRepairService::costFraction).
     */
    public function repairCostFraction(): float
    {
        $v = $this->settings->get('drone.repair.cost_fraction', 0.6);
        return is_numeric($v) && (float) $v > 0 ? (float) $v : 0.6;
    }

    /**
     * Drone-overhead markup сверх рыночной gold-стоимости ресурсов.
     * Default 1.2 (+20% — мягче V23 NPC markup=1.5).
     */
    public function repairMarkup(): float
    {
        $v = $this->settings->get('drone.repair.markup', 1.2);
        return is_numeric($v) && (float) $v > 0 ? (float) $v : 1.2;
    }

    /**
     * Минимальный итоговый gold-cost batch-операции (защита от 0-cost edge).
     * Default 10.
     */
    public function repairMinCostGold(): int
    {
        $v = $this->settings->get('drone.repair.min_cost_gold', 10);
        return is_numeric($v) && (int) $v >= 0 ? (int) $v : 10;
    }

    /**
     * Сколько единиц durability_count вычитается из repair-инстанса при batch.
     * Default 100 = 1 batch на полный заряд.
     */
    public function repairBatteryDrainPerLaunch(): int
    {
        $v = $this->settings->get('drone.repair.battery_drain_per_launch', 100);
        return is_numeric($v) && (int) $v >= 1 ? (int) $v : 100;
    }

    /**
     * Макс. заряд одного repair-инстанса (= durability_count при крафте). Default 100.
     */
    public function repairBatteryMax(): int
    {
        $v = $this->settings->get('drone.repair.battery_max', 100);
        return is_numeric($v) && (int) $v >= 1 ? (int) $v : 100;
    }

    /**
     * За сколько минут on_base repair-дрон заряжается с 0 до battery_max. Default 240 (4 ч).
     */
    public function repairChargeMinutesPerFull(): int
    {
        $v = $this->settings->get('drone.repair.base_charge_minutes_per_full', 240);
        return is_numeric($v) && (int) $v >= 1 ? (int) $v : 240;
    }

    /**
     * Charge per minute для repair (computed). DroneRechargeCron generalize
     * читает per-type для UPDATE durability_count += rate × interval.
     */
    public function repairChargeRatePerMinute(): float
    {
        $minutes = $this->repairChargeMinutesPerFull();
        if ($minutes <= 0) {
            return 0.0;
        }
        return $this->repairBatteryMax() / $minutes;
    }

    /**
     * Можно ли запустить batch-ремонт с этим зарядом. true если durability >= drain
     * И repairIsEnabled.
     */
    public function canRunRepair(int $currentCharge): bool
    {
        if (! $this->repairIsEnabled()) {
            return false;
        }
        return $currentCharge >= $this->repairBatteryDrainPerLaunch();
    }
}
