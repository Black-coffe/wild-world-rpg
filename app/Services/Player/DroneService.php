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
}
