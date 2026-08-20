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
 *  - drone.scout.caravan_offer_chance (float=0.02)
 *
 * W5 (ADR-064): + combat-слой (drone.combat.*) для defensive time-window
 * initiative-buff + caravan-offer integration (drone.<type>.caravan_offer_chance +
 * drone.<type>.caravan_markup_multiplier per scout/cargo/repair/combat).
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
     * Эффективный шаг заряда scout-дрона за минуту on-base — ровно то, что
     * прибавляет DroneRechargeCron за один тик (round(rate), но не ниже 1).
     * Floor=1 гарантирует, что дрон не застрянет навсегда при медленных
     * настройках (rate<0.5 → round=0 → дрон не заряжался бы вовсе).
     * Нужен для honest-ETA: расчёт «через сколько минут готов» совпадает с тем,
     * как реально тикает крон.
     */
    public function chargeStepPerMinute(): int
    {
        $step = (int) round($this->chargeRatePerMinute());
        return $step >= 1 ? $step : 1;
    }

    /**
     * Сколько минут on-base нужно, чтобы заряд дорос с $current до $target.
     * 0, если целевое значение уже достигнуто. Совпадает с реальным темпом крона
     * (chargeStepPerMinute), поэтому ETA честный (без over/under-promise на дефолтах).
     */
    public function minutesToCharge(int $current, int $target): int
    {
        $need = $target - $current;
        if ($need <= 0) {
            return 0;
        }
        return (int) ceil($need / $this->chargeStepPerMinute());
    }

    /**
     * Минут on-base до готовности к запуску (заряд достигнет battery_drain_per_launch).
     */
    public function minutesToReady(int $current): int
    {
        return $this->minutesToCharge($current, $this->batteryDrainPerLaunch());
    }

    /**
     * Минут on-base до полного заряда (battery_max).
     */
    public function minutesToFull(int $current): int
    {
        return $this->minutesToCharge($current, $this->batteryMax());
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
     * Доля скорости зарядки ВНЕ базы (0.0 — в поле дрон не заряжается вовсе).
     *
     * Просьба игрока (Анжела, 18.08.2026): «карго-дрон должен заряжаться везде, на базе
     * быстро, в поле — медленнее». До этого дрон в поле не заряжался ни на единицу:
     * `DroneRechargeCron` просто пропускал всех, кто не стоит на своей клетке, — и
     * дальняя вылазка означала мёртвый дрон до самого возвращения домой.
     *
     * База остаётся быстрее по определению: в поле та же скорость умножается на эту
     * долю. Ключ `drone.field_charge_percent` (0..100).
     */
    public function fieldChargeFactor(): float
    {
        $v = $this->settings->get('drone.field_charge_percent', 0);
        $percent = is_numeric($v) ? (int) $v : 0;
        $percent = max(0, min(100, $percent));

        return $percent / 100;
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

    // ──────────────────────────────────────────────────────────────────
    // W5 (ADR-064) — Combat drone extension. Параллельные knob'ы под
    // префиксом `combat*`, читают из `drone.combat.*` GameSettings.
    // Defensive time-window дрон: запуск выставляет characters.combat_drone_active_until
    // = NOW + activation_minutes, drains battery. PvP-attack в window →
    // DefenseStructureService подмешивает initiative_bonus_percent к tower-bonus,
    // clamp'ed до max_combined_initiative_percent. RNG-fence: zero new mt_rand.
    // ──────────────────────────────────────────────────────────────────

    /**
     * Killswitch combat-слоя (W5). Независим от scout/cargo/repair killswitch'ей.
     * false → CombatDroneActivateAction отвергает, кнопка в Перс остаётся в lock-state,
     * DroneRechargeCron пропускает combat-инстансы, DefenseStructureService skip add-drone-bonus.
     */
    public function combatIsEnabled(): bool
    {
        $v = $this->settings->get('drone.combat.enabled', true);
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Бонус инициативы защитнику-владельцу при активном combat-дроне (целое в процентах).
     * Default 12. DefenseStructureService суммирует с tower-bonus и применяет cap.
     */
    public function combatInitiativeBonusPercent(): int
    {
        $v = $this->settings->get('drone.combat.initiative_bonus_percent', 12);
        return is_numeric($v) && (int) $v >= 0 ? (int) $v : 12;
    }

    /**
     * Длительность активного buff после запуска (минуты). Default 30.
     * CombatDroneActivateAction: UPDATE characters.combat_drone_active_until = NOW + N min.
     */
    public function combatActivationMinutes(): int
    {
        $v = $this->settings->get('drone.combat.activation_minutes', 30);
        return is_numeric($v) && (int) $v >= 1 ? (int) $v : 30;
    }

    /**
     * Сколько единиц durability_count вычитается из combat-инстанса при активации. Default 100.
     */
    public function combatBatteryDrainPerLaunch(): int
    {
        $v = $this->settings->get('drone.combat.battery_drain_per_launch', 100);
        return is_numeric($v) && (int) $v >= 1 ? (int) $v : 100;
    }

    /**
     * Макс. заряд одного combat-инстанса (= durability_count при крафте). Default 100.
     */
    public function combatBatteryMax(): int
    {
        $v = $this->settings->get('drone.combat.battery_max', 100);
        return is_numeric($v) && (int) $v >= 1 ? (int) $v : 100;
    }

    /**
     * За сколько минут on_base combat-дрон заряжается с 0 до battery_max. Default 360 (6 ч).
     */
    public function combatChargeMinutesPerFull(): int
    {
        $v = $this->settings->get('drone.combat.base_charge_minutes_per_full', 360);
        return is_numeric($v) && (int) $v >= 1 ? (int) $v : 360;
    }

    /**
     * Charge per minute для combat (computed). DroneRechargeCron generalize
     * читает per-type для UPDATE durability_count += rate × interval.
     */
    public function combatChargeRatePerMinute(): float
    {
        $minutes = $this->combatChargeMinutesPerFull();
        if ($minutes <= 0) {
            return 0.0;
        }
        return $this->combatBatteryMax() / $minutes;
    }

    /**
     * Soft cap для combined initiative-bonus (WatchTower + Combat drone).
     * DefenseStructureService применяет min(tower + drone, cap). Default 25.
     */
    public function combatMaxCombinedInitiativePercent(): int
    {
        $v = $this->settings->get('drone.combat.max_combined_initiative_percent', 25);
        return is_numeric($v) && (int) $v >= 0 ? (int) $v : 25;
    }

    /**
     * Можно ли активировать combat-дрон с этим зарядом. true если durability >= drain
     * И combatIsEnabled.
     */
    public function canActivateCombat(int $currentCharge): bool
    {
        if (! $this->combatIsEnabled()) {
            return false;
        }
        return $currentCharge >= $this->combatBatteryDrainPerLaunch();
    }

    // ──────────────────────────────────────────────────────────────────
    // ADR-174 (story transport-17) — AutonomousDrone (Инженеры) recharge
    // extension. Killswitch общий с транспортом (`world.vehicle.enabled`),
    // отдельного `drone.drone_auto.enabled` не заводим: дрон-транспорт
    // существует только пока включена транспортная система целиком. Max
    // переиспользует уже посеянный `world.vehicle.drone_auto.charges_full`
    // (story transport-02) — второго источника правды не заводим.
    // ──────────────────────────────────────────────────────────────────

    /**
     * Killswitch зарядки транспортного дрона — общий тумблер транспорта.
     * false → DroneRechargeCron пропускает AutonomousDrone-инстансы целиком
     * (транспорт выключен целиком, а не только зарядка).
     */
    public function droneAutoIsEnabled(): bool
    {
        $v = $this->settings->get('world.vehicle.enabled', false);
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Макс. заряд транспортного дрона — переиспользует `world.vehicle.drone_auto.
     * charges_full` (story transport-02, на проде 350). Второго источника правды
     * не заводим: то же значение, к которому VehicleActivationService зажимает
     * текущий заряд машины.
     */
    public function droneAutoBatteryMax(): int
    {
        $v = $this->settings->get('world.vehicle.drone_auto.charges_full', 350);
        return is_numeric($v) && (int) $v >= 1 ? (int) $v : 350;
    }

    /**
     * Charge per minute для транспортного дрона. В отличие от остальных
     * четырёх дронов ставка хранится напрямую (а не как base_charge_minutes_
     * per_full → computed): «минут до полного» для транспорта нигде не
     * существовало до этой story, заводить его как промежуточную величину
     * было бы лишней абстракцией. DroneRechargeCron читает этот метод пятым
     * типом по общему контракту (enabled/max/rate).
     */
    public function droneAutoChargeRatePerMinute(): float
    {
        $v = $this->settings->get('world.vehicle.drone_auto.charge_per_minute', 1.2);
        if (! is_numeric($v)) {
            return 1.2;
        }

        // В отличие от isEnabled/batteryMax здесь НЕ подстраховываем ≤0 дефолтом:
        // DroneRechargeCron сам гейтит `rate <= 0.0 → continue` (тот же контракт, что
        // у остальных четырёх дронов, где ставка = 0 при base_charge_minutes_per_full=0).
        return (float) $v;
    }

    // ──────────────────────────────────────────────────────────────────
    // W5 (ADR-064) — Caravan drone-offer helpers. Симметричное чтение
    // per-type chance + markup. SpawnCaravanCron rolls через эти helper'ы.
    // ──────────────────────────────────────────────────────────────────

    /**
     * Шанс (0..1) что NPC-караван (V25) выставит готовый дрон указанного типа.
     * $droneType ∈ {scout, cargo, repair, combat}.
     * Default 0.02 per-type. Закрывает W1 dead promise drone.scout.caravan_offer_chance.
     */
    public function caravanOfferChanceFor(string $droneType): float
    {
        if (! in_array($droneType, ['scout', 'cargo', 'repair', 'combat'], true)) {
            return 0.0;
        }
        $v = $this->settings->get("drone.{$droneType}.caravan_offer_chance", 0.02);
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
     * Множитель recipe.gold для caravan-offer цены (premium для не-крафтящих).
     * Default 3.0 per-type. CaravanService::computeDroneOfferGold использует.
     */
    public function caravanMarkupMultiplierFor(string $droneType): float
    {
        if (! in_array($droneType, ['scout', 'cargo', 'repair', 'combat'], true)) {
            return 0.0;
        }
        $v = $this->settings->get("drone.{$droneType}.caravan_markup_multiplier", 3.0);
        if (! is_numeric($v) || (float) $v <= 0) {
            return 3.0;
        }
        return (float) $v;
    }
}
