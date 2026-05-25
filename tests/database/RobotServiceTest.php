<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Player\RobotService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * V18 (ADR-049) — RobotService: tier-множители по name_eng (Scout/Industrial),
 * killswitch t2.enabled, мигрированные скаляры (cells_base/per_level/random_percent).
 *
 * GameSettings читаются из game_settings (паттерн SpecializationServiceTest): дефолты —
 * без строки (get → default), tuned — с seeded-строкой.
 *
 * @internal
 */
final class RobotServiceTest extends CIUnitTestCase
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
        // Дефолты не сидим — get() вернёт переданный default.
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

    private function seedFloat(string $key, float $float): void
    {
        Database::connect('tests')->table('game_settings')->insert([
            'setting_key' => $key, 'category' => 'resources', 'value_type' => 'float', 'value_float' => $float,
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

    private function cleanCache(): void
    {
        if (function_exists('cache')) {
            $c = cache();
            if (is_object($c) && method_exists($c, 'clean')) {
                $c->clean();
            }
        }
    }

    // ── классификация роботов ──────────────────────────────────────────────

    public function testIsKnownAndIsT2(): void
    {
        $svc = new RobotService();
        $this->assertTrue($svc->isKnownRobot('RobotExplorer'));
        $this->assertTrue($svc->isKnownRobot('RobotScout'));
        $this->assertTrue($svc->isKnownRobot('RobotIndustrial'));
        $this->assertFalse($svc->isKnownRobot('RobotMystery'));
        $this->assertFalse($svc->isKnownRobot(null));

        $this->assertTrue($svc->isT2('RobotScout'));
        $this->assertTrue($svc->isT2('RobotIndustrial'));
        $this->assertFalse($svc->isT2('RobotExplorer'));
        $this->assertFalse($svc->isT2('RobotGatherer'));
        $this->assertFalse($svc->isT2(null));
    }

    // ── exploration: scout cells_multiplier ────────────────────────────────

    public function testScoutCellsMultiplierDefaultAndTuned(): void
    {
        $svc = new RobotService();
        // Дефолт 1.6 для Scout; T1 и неизвестные — 1.0.
        $this->assertEqualsWithDelta(1.6, $svc->explorationCellsMultiplierFor('RobotScout'), 1e-9);
        $this->assertSame(1.0, $svc->explorationCellsMultiplierFor('RobotExplorer'));
        $this->assertSame(1.0, $svc->explorationCellsMultiplierFor(null));
        $this->assertSame(1.0, $svc->explorationCellsMultiplierFor('RobotIndustrial')); // gatherer-семья

        // Tuned-значение применяется.
        $this->seedFloat('robots.scout.cells_multiplier', 2.0);
        $svc2 = new RobotService();
        $this->assertEqualsWithDelta(2.0, $svc2->explorationCellsMultiplierFor('RobotScout'), 1e-9);
    }

    // ── gathering: industrial yield + extra cells ──────────────────────────

    public function testIndustrialYieldAndExtraCellsDefaults(): void
    {
        $svc = new RobotService();
        $this->assertEqualsWithDelta(1.5, $svc->gatheringYieldMultiplierFor('RobotIndustrial'), 1e-9);
        $this->assertSame(1.0, $svc->gatheringYieldMultiplierFor('RobotGatherer'));
        $this->assertSame(1.0, $svc->gatheringYieldMultiplierFor(null));
        $this->assertSame(1.0, $svc->gatheringYieldMultiplierFor('RobotScout')); // explorer-семья

        $this->assertSame(1, $svc->gatheringExtraCellsFor('RobotIndustrial'));
        $this->assertSame(0, $svc->gatheringExtraCellsFor('RobotGatherer'));
        $this->assertSame(0, $svc->gatheringExtraCellsFor(null));
    }

    public function testIndustrialTunedValues(): void
    {
        $this->seedFloat('robots.industrial.yield_multiplier', 1.8);
        $this->seedInt('robots.industrial.extra_cells', 3);
        $svc = new RobotService();
        $this->assertEqualsWithDelta(1.8, $svc->gatheringYieldMultiplierFor('RobotIndustrial'), 1e-9);
        $this->assertSame(3, $svc->gatheringExtraCellsFor('RobotIndustrial'));
    }

    // ── killswitch ─────────────────────────────────────────────────────────

    public function testKillswitchForcesNeutral(): void
    {
        $this->seedBool('robots.t2.enabled', 0);
        $svc = new RobotService();
        $this->assertFalse($svc->t2Enabled());
        // Даже для T2-роботов — нейтральные значения.
        $this->assertSame(1.0, $svc->explorationCellsMultiplierFor('RobotScout'));
        $this->assertSame(1.0, $svc->gatheringYieldMultiplierFor('RobotIndustrial'));
        $this->assertSame(0, $svc->gatheringExtraCellsFor('RobotIndustrial'));
    }

    public function testT2EnabledDefaultTrue(): void
    {
        $this->assertTrue((new RobotService())->t2Enabled());
    }

    // ── мигрированные скаляры ───────────────────────────────────────────────

    public function testMigratedScalarDefaults(): void
    {
        $svc = new RobotService();
        $this->assertSame(50, $svc->explorationCellsBase());
        $this->assertSame(10, $svc->explorationCellsPerLevel());
        $this->assertSame(20, $svc->gatheringRandomPercent());
    }

    public function testMigratedScalarsTuned(): void
    {
        $this->seedInt('robots.exploration.cells_base', 80);
        $this->seedInt('robots.exploration.cells_per_level', 15);
        $this->seedInt('robots.gathering.random_percent', 30);
        $svc = new RobotService();
        $this->assertSame(80, $svc->explorationCellsBase());
        $this->assertSame(15, $svc->explorationCellsPerLevel());
        $this->assertSame(30, $svc->gatheringRandomPercent());
    }
}
