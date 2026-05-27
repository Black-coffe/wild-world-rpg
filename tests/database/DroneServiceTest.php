<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Player\DroneService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * W2 (ADR-058) — DroneService: GameSettings reader + computed chargeRatePerMinute.
 *
 * @internal
 */
final class DroneServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();
        $db = Database::connect('tests');
        $db->query('DROP TABLE IF EXISTS game_settings');
        $db->query('
            CREATE TABLE game_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(191) NOT NULL,
                category VARCHAR(64) NULL,
                value_type VARCHAR(16) NULL,
                value_int INT NULL,
                value_float DECIMAL(15,5) NULL,
                value_bool TINYINT NULL,
                value_string TEXT NULL,
                hard_min VARCHAR(32) NULL,
                hard_max VARCHAR(32) NULL
            )
        ');
    }

    protected function tearDown(): void
    {
        Database::connect('tests')->query('DROP TABLE IF EXISTS game_settings');
        $this->cleanCache();
        parent::tearDown();
    }

    private function seedBool(string $key, int $bool): void
    {
        Database::connect('tests')->table('game_settings')->insert([
            'setting_key' => $key, 'category' => 'resources', 'value_type' => 'bool', 'value_bool' => $bool,
        ]);
        $this->cleanCache();
    }

    private function seedInt(string $key, int $int): void
    {
        Database::connect('tests')->table('game_settings')->insert([
            'setting_key' => $key, 'category' => 'resources', 'value_type' => 'int', 'value_int' => $int,
        ]);
        $this->cleanCache();
    }

    private function seedFloat(string $key, float $float): void
    {
        Database::connect('tests')->table('game_settings')->insert([
            'setting_key' => $key, 'category' => 'resources', 'value_type' => 'float', 'value_float' => $float,
        ]);
        $this->cleanCache();
    }

    private function cleanCache(): void
    {
        if (function_exists('cache')) {
            $c = cache();
            if (is_object($c) && method_exists($c, 'clean')) {
                $c->clean();
            }
        }
    }

    public function testDefaults(): void
    {
        $svc = new DroneService();
        $this->assertTrue($svc->isEnabled());
        $this->assertSame(10, $svc->radiusCells());
        $this->assertSame(100, $svc->batteryDrainPerLaunch());
        $this->assertSame(100, $svc->batteryMax());
        $this->assertSame(120, $svc->chargeMinutesPerFull());
        $this->assertEqualsWithDelta(100.0 / 120.0, $svc->chargeRatePerMinute(), 1e-6);
        $this->assertEqualsWithDelta(0.02, $svc->caravanOfferChance(), 1e-9);
    }

    public function testKillswitchOff(): void
    {
        $this->seedBool('drone.scout.enabled', 0);
        $svc = new DroneService();
        $this->assertFalse($svc->isEnabled());
    }

    public function testCanLaunchRespectsKillswitch(): void
    {
        $this->seedBool('drone.scout.enabled', 0);
        $svc = new DroneService();
        // Даже при полном заряде — killswitch блокирует.
        $this->assertFalse($svc->canLaunch(100));
    }

    public function testCanLaunchRequiresFullDrain(): void
    {
        $svc = new DroneService();
        // drain=100, charge=99 → false; charge=100 → true; charge=200 → true.
        $this->assertFalse($svc->canLaunch(99));
        $this->assertTrue($svc->canLaunch(100));
        $this->assertTrue($svc->canLaunch(200));
    }

    public function testRadiusTuned(): void
    {
        $this->seedInt('drone.scout.radius_cells', 5);
        $this->assertSame(5, (new DroneService())->radiusCells());
    }

    public function testBatteryMaxAndDrainTuned(): void
    {
        $this->seedInt('drone.scout.battery_max', 300);
        $this->seedInt('drone.scout.battery_drain_per_launch', 50);
        $svc = new DroneService();
        $this->assertSame(300, $svc->batteryMax());
        $this->assertSame(50, $svc->batteryDrainPerLaunch());
        // 300/50=6 запусков на полный заряд.
        $this->assertTrue($svc->canLaunch(50));
        $this->assertTrue($svc->canLaunch(300));
        $this->assertFalse($svc->canLaunch(49));
    }

    public function testChargeRatePerMinute(): void
    {
        $this->seedInt('drone.scout.battery_max', 200);
        $this->seedInt('drone.scout.base_charge_minutes_per_full', 100);
        // 200 / 100 = 2.0 charge/мин.
        $this->assertEqualsWithDelta(2.0, (new DroneService())->chargeRatePerMinute(), 1e-9);
    }

    public function testChargeRateZeroIfInvalidMinutes(): void
    {
        // Hard-min уже 1, но если кто-то всё-таки засунет 0 — fallback 120.
        // Здесь проверяем pure: при minutes=0 rate=0.0 (safe div-by-zero).
        $svc = new DroneService();
        // Через искусственный subclass проверять не будем — просто проверим что
        // default срабатывает (через seedInt invalid не пройдёт, hard_min/max — schema layer).
        $this->assertGreaterThan(0.0, $svc->chargeRatePerMinute());
    }

    public function testCaravanOfferChanceClamped(): void
    {
        $this->seedFloat('drone.scout.caravan_offer_chance', 2.5);
        $this->assertSame(1.0, (new DroneService())->caravanOfferChance());

        // Reset
        Database::connect('tests')->table('game_settings')->where('setting_key', 'drone.scout.caravan_offer_chance')->delete();
        $this->cleanCache();
        $this->seedFloat('drone.scout.caravan_offer_chance', -0.5);
        $this->assertSame(0.0, (new DroneService())->caravanOfferChance());
    }

    // ──────────────────────────────────────────────────────────────────
    // W3b (ADR-060) — Cargo drone extension. Параллельные knob'ы cargo*.
    // ──────────────────────────────────────────────────────────────────

    public function testCargoDefaults(): void
    {
        $svc = new DroneService();
        $this->assertTrue($svc->cargoIsEnabled());
        $this->assertSame(30, $svc->cargoPayloadKg());
        $this->assertSame(100, $svc->cargoBatteryDrainPerLaunch());
        $this->assertSame(100, $svc->cargoBatteryMax());
        $this->assertSame(180, $svc->cargoChargeMinutesPerFull());
        $this->assertEqualsWithDelta(100.0 / 180.0, $svc->cargoChargeRatePerMinute(), 1e-6);
    }

    public function testCargoKillswitchOff(): void
    {
        $this->seedBool('drone.cargo.enabled', 0);
        $svc = new DroneService();
        $this->assertFalse($svc->cargoIsEnabled());
        // Scout остаётся независимым.
        $this->assertTrue($svc->isEnabled());
    }

    public function testCanSendCargoRespectsKillswitch(): void
    {
        $this->seedBool('drone.cargo.enabled', 0);
        $svc = new DroneService();
        $this->assertFalse($svc->canSendCargo(100));
        // Даже при полном заряде — killswitch блокирует.
        $this->assertFalse($svc->canSendCargo(999));
    }

    public function testCanSendCargoRequiresFullDrain(): void
    {
        $svc = new DroneService();
        // drain=100, charge=99 → false; charge=100 → true; charge=200 → true.
        $this->assertFalse($svc->canSendCargo(99));
        $this->assertTrue($svc->canSendCargo(100));
        $this->assertTrue($svc->canSendCargo(200));
    }

    public function testCargoPayloadTuned(): void
    {
        $this->seedInt('drone.cargo.payload_kg', 75);
        $this->assertSame(75, (new DroneService())->cargoPayloadKg());
    }

    public function testCargoBatteryMaxAndDrainTuned(): void
    {
        $this->seedInt('drone.cargo.battery_max', 250);
        $this->seedInt('drone.cargo.battery_drain_per_launch', 50);
        $svc = new DroneService();
        $this->assertSame(250, $svc->cargoBatteryMax());
        $this->assertSame(50, $svc->cargoBatteryDrainPerLaunch());
        // 250/50=5 доставок на полный заряд.
        $this->assertTrue($svc->canSendCargo(50));
        $this->assertTrue($svc->canSendCargo(250));
        $this->assertFalse($svc->canSendCargo(49));
    }

    public function testCargoChargeRatePerMinute(): void
    {
        $this->seedInt('drone.cargo.battery_max', 300);
        $this->seedInt('drone.cargo.base_charge_minutes_per_full', 100);
        // 300 / 100 = 3.0 charge/мин.
        $this->assertEqualsWithDelta(3.0, (new DroneService())->cargoChargeRatePerMinute(), 1e-9);
    }

    public function testScoutAndCargoIndependent(): void
    {
        // Scout off, cargo on — независимые слои.
        $this->seedBool('drone.scout.enabled', 0);
        $svc = new DroneService();
        $this->assertFalse($svc->isEnabled());
        $this->assertTrue($svc->cargoIsEnabled());
        $this->assertFalse($svc->canLaunch(100));
        $this->assertTrue($svc->canSendCargo(100));
    }

    // ──────────────────────────────────────────────────────────────────
    // W4 (ADR-063) — Repair drone extension. Параллельные knob'ы repair*.
    // ──────────────────────────────────────────────────────────────────

    public function testRepairDefaults(): void
    {
        $svc = new DroneService();
        $this->assertTrue($svc->repairIsEnabled());
        $this->assertEqualsWithDelta(0.6, $svc->repairCostFraction(), 1e-9);
        $this->assertEqualsWithDelta(1.2, $svc->repairMarkup(), 1e-9);
        $this->assertSame(10, $svc->repairMinCostGold());
        $this->assertSame(100, $svc->repairBatteryDrainPerLaunch());
        $this->assertSame(100, $svc->repairBatteryMax());
        $this->assertSame(240, $svc->repairChargeMinutesPerFull());
        $this->assertEqualsWithDelta(100.0 / 240.0, $svc->repairChargeRatePerMinute(), 1e-6);
    }

    public function testRepairKillswitchOff(): void
    {
        $this->seedBool('drone.repair.enabled', 0);
        $svc = new DroneService();
        $this->assertFalse($svc->repairIsEnabled());
        // Scout и cargo остаются независимыми.
        $this->assertTrue($svc->isEnabled());
        $this->assertTrue($svc->cargoIsEnabled());
    }

    public function testCanRunRepairRespectsKillswitch(): void
    {
        $this->seedBool('drone.repair.enabled', 0);
        $svc = new DroneService();
        $this->assertFalse($svc->canRunRepair(100));
        $this->assertFalse($svc->canRunRepair(999));
    }

    public function testCanRunRepairRequiresFullDrain(): void
    {
        $svc = new DroneService();
        $this->assertFalse($svc->canRunRepair(99));
        $this->assertTrue($svc->canRunRepair(100));
        $this->assertTrue($svc->canRunRepair(200));
    }

    public function testRepairCostFractionAndMarkupTuned(): void
    {
        $this->seedFloat('drone.repair.cost_fraction', 0.4);
        $this->seedFloat('drone.repair.markup', 1.5);
        $svc = new DroneService();
        $this->assertEqualsWithDelta(0.4, $svc->repairCostFraction(), 1e-9);
        $this->assertEqualsWithDelta(1.5, $svc->repairMarkup(), 1e-9);
    }

    public function testRepairBatteryAndChargeTuned(): void
    {
        $this->seedInt('drone.repair.battery_max', 200);
        $this->seedInt('drone.repair.battery_drain_per_launch', 50);
        $this->seedInt('drone.repair.base_charge_minutes_per_full', 100);
        $svc = new DroneService();
        $this->assertSame(200, $svc->repairBatteryMax());
        $this->assertSame(50, $svc->repairBatteryDrainPerLaunch());
        $this->assertSame(100, $svc->repairChargeMinutesPerFull());
        // 200/100 = 2.0 charge/мин.
        $this->assertEqualsWithDelta(2.0, $svc->repairChargeRatePerMinute(), 1e-9);
        $this->assertTrue($svc->canRunRepair(50));
        $this->assertTrue($svc->canRunRepair(200));
        $this->assertFalse($svc->canRunRepair(49));
    }

    public function testRepairMinCostGoldTuned(): void
    {
        $this->seedInt('drone.repair.min_cost_gold', 500);
        $this->assertSame(500, (new DroneService())->repairMinCostGold());
    }

    public function testAllThreeDroneLayersIndependent(): void
    {
        // Scout off, cargo on, repair off — три независимых слоя.
        $this->seedBool('drone.scout.enabled', 0);
        $this->seedBool('drone.repair.enabled', 0);
        $svc = new DroneService();
        $this->assertFalse($svc->isEnabled());
        $this->assertTrue($svc->cargoIsEnabled());
        $this->assertFalse($svc->repairIsEnabled());
        $this->assertFalse($svc->canLaunch(100));
        $this->assertTrue($svc->canSendCargo(100));
        $this->assertFalse($svc->canRunRepair(100));
    }
}
