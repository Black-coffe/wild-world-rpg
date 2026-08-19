<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Player\CraftInsuranceService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * V24 (ADR-056) — CraftInsuranceService: pre-paid вечный полис на крафт.
 *
 *   gold = max(min_cost, ceil((gold_required + Σ qty×price) × policy_fraction × log_qty))
 *
 * @internal
 */
final class CraftInsuranceServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    /** @var array<string,mixed> */
    private array $recipe = [
        'gold_required' => 40000,
        'resources'     => ['Янтарь' => 12, 'Смола деревьев' => 70, 'Солнечные камни' => 55],
    ];

    /** @var array<string,int> */
    private array $prices = [
        'Янтарь'          => 100,
        'Смола деревьев'  => 20,
        'Солнечные камни' => 50,
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

    private function seedString(string $key, string $str): void
    {
        Database::connect('tests')->table('game_settings')->insert([
            'setting_key' => $key, 'category' => 'resources', 'value_type' => 'string', 'value_string' => $str,
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
        $svc = new CraftInsuranceService();
        $this->assertTrue($svc->enabled());
        $this->assertEqualsWithDelta(0.2, $svc->policyFraction(), 1e-9);
        $this->assertSame(50, $svc->minCostGold());
        // ADR-172 — дроны входят в дефолтный список: дорогая техника, которой при
        // сборке списка ещё не существовало.
        $this->assertSame(['robots', 'workbench', 'transport', 'drones'], $svc->eligibleTypes());
    }

    public function testKillswitchOff(): void
    {
        $this->seedBool('craft_insurance.enabled', 0);
        $this->assertFalse((new CraftInsuranceService())->enabled());
    }

    public function testDefaultCostFormulaQty1(): void
    {
        // gold_required=40000 + (12×100 + 70×20 + 55×50)=1200+1400+2750=5350 = 45 350
        // × 0.2 × 1 (qty) = ceil(9070) = 9070
        $svc  = new CraftInsuranceService();
        $gold = $svc->computePolicyCost($this->recipe, $this->prices, 1);
        $this->assertSame(9070, $gold);
    }

    public function testCostScalesWithQty(): void
    {
        // qty=3 → ×3
        $gold = (new CraftInsuranceService())->computePolicyCost($this->recipe, $this->prices, 3);
        $this->assertSame(27210, $gold);
    }

    public function testMinCostEnforced(): void
    {
        $cheapRecipe = ['gold_required' => 0, 'resources' => ['Камень' => 1]];
        $cheapPrices = ['Камень' => 1];
        // 0 + 1 = 1, × 0.2 × 1 = 0.2 → ceil=1, < min 50 → 50
        $gold = (new CraftInsuranceService())->computePolicyCost($cheapRecipe, $cheapPrices, 1);
        $this->assertSame(50, $gold);
    }

    public function testCostFractionTunedHigh(): void
    {
        $this->seedFloat('craft_insurance.policy_fraction', 0.5);
        // 45350 × 0.5 × 1 = 22 675
        $gold = (new CraftInsuranceService())->computePolicyCost($this->recipe, $this->prices, 1);
        $this->assertSame(22675, $gold);
    }

    public function testEligibleTypesCustom(): void
    {
        $this->seedString('craft_insurance.eligible_types', 'robots,tool, weapon');
        $types = (new CraftInsuranceService())->eligibleTypes();
        $this->assertSame(['robots', 'tool', 'weapon'], $types);
    }

    public function testEligibleTypesFallbackOnEmpty(): void
    {
        $this->seedString('craft_insurance.eligible_types', '');
        $types = (new CraftInsuranceService())->eligibleTypes();
        $this->assertSame(['robots', 'workbench', 'transport', 'drones'], $types);
    }

    public function testZeroQtyReturnsMinCost(): void
    {
        $gold = (new CraftInsuranceService())->computePolicyCost($this->recipe, $this->prices, 0);
        $this->assertSame(50, $gold);
    }
}
