<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Player\RobotRepairService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * V19 (ADR-050) — RobotRepairService: пропорциональная стоимость ремонта
 * (gold + resources × cost_fraction × restored/base), full-already, killswitch.
 *
 * @internal
 */
final class RobotRepairServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    /** @var array<string,mixed> */
    private array $recipe = [
        'gold_required' => 40000,
        'resources'     => ['Янтарь' => 12, 'Смола деревьев' => 70, 'Солнечные камни' => 55],
    ];

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
        $svc = new RobotRepairService();
        $this->assertTrue($svc->enabled());
        $this->assertEqualsWithDelta(0.6, $svc->costFraction(), 1e-9);
    }

    public function testKillswitchOff(): void
    {
        $this->seedBool('robots.repair.enabled', 0);
        $this->assertFalse((new RobotRepairService())->enabled());
    }

    public function testProportionalCostHalfDurabilityDefaultFraction(): void
    {
        // base 1200, current 600 → restored 600, durFrac 0.5, frac 0.6.
        $svc  = new RobotRepairService();
        $cost = $svc->computeCost($this->recipe, 600, 1200);
        $this->assertSame(600, $cost['restored']);
        // gold = ceil(40000 * 0.6 * 0.5) = 12000
        $this->assertSame(12000, $cost['gold']);
        // Янтарь = ceil(12*0.6*0.5)=ceil(3.6)=4; Смола=ceil(70*0.6*0.5)=21; Камни=ceil(55*0.6*0.5)=ceil(16.5)=17
        $this->assertSame(4, $cost['resources']['Янтарь']);
        $this->assertSame(21, $cost['resources']['Смола деревьев']);
        $this->assertSame(17, $cost['resources']['Солнечные камни']);
    }

    public function testFullRestoreTunedFractionOne(): void
    {
        $this->seedFloat('robots.repair.cost_fraction', 1.0);
        // base 1200, current 0 → restored 1200, durFrac 1.0, frac 1.0 → полная стоимость рецепта.
        $cost = (new RobotRepairService())->computeCost($this->recipe, 0, 1200);
        $this->assertSame(1200, $cost['restored']);
        $this->assertSame(40000, $cost['gold']);
        $this->assertSame(12, $cost['resources']['Янтарь']);
        $this->assertSame(70, $cost['resources']['Смола деревьев']);
        $this->assertSame(55, $cost['resources']['Солнечные камни']);
    }

    public function testAlreadyFullCostsNothing(): void
    {
        $cost = (new RobotRepairService())->computeCost($this->recipe, 1200, 1200);
        $this->assertSame(0, $cost['restored']);
        $this->assertSame(0, $cost['gold']);
        $this->assertSame([], $cost['resources']);
    }

    public function testCheaperThanRecraftWhenFractionBelowOne(): void
    {
        // frac 0.6, full restore (current 0) → стоимость < полного крафта (40000).
        $cost = (new RobotRepairService())->computeCost($this->recipe, 0, 1200);
        $this->assertLessThan(40000, $cost['gold'], 'ремонт при frac<1 дешевле перекрафта');
        $this->assertSame(24000, $cost['gold']); // ceil(40000*0.6*1.0)
    }
}
