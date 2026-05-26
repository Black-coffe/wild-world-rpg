<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Player\NpcRepairService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * V23 (ADR-055) — NpcRepairService: gold-only стоимость через market price.
 *
 *   gold = max(min_cost, ceil(Σ(qty[i] × cost_fraction × price[i]) × markup))
 *
 * @internal
 */
final class NpcRepairServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    /** @var array<string,mixed> */
    private array $recipe = [
        'resources' => ['Дерево' => 10, 'Камень' => 5, 'Железо' => 2],
    ];

    /** @var array<string,int> */
    private array $prices = [
        'Дерево' => 5,
        'Камень' => 10,
        'Железо' => 50,
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

    public function testDefaults(): void
    {
        $svc = new NpcRepairService();
        $this->assertTrue($svc->enabled());
        $this->assertEqualsWithDelta(1.5, $svc->markup(), 1e-9);
        $this->assertEqualsWithDelta(0.5, $svc->costFraction(), 1e-9);
        $this->assertSame(10, $svc->minCostGold());
    }

    public function testKillswitchOff(): void
    {
        $this->seedBool('npc.repair.enabled', 0);
        $this->assertFalse((new NpcRepairService())->enabled());
    }

    public function testDefaultCostFormula(): void
    {
        // template: 10×Дерево(5)=50 + 5×Камень(10)=50 + 2×Железо(50)=100 → sum=200
        // ×0.5 cost_fraction = 100, ×1.5 markup = 150
        $svc  = new NpcRepairService();
        $gold = $svc->computeGoldCost($this->recipe, $this->prices);
        $this->assertSame(150, $gold);
    }

    public function testMinCostGoldEnforced(): void
    {
        // Дешёвый рецепт (1×Дерево=5): 5×0.5×1.5=ceil(3.75)=4, < min 10 → берётся 10.
        $cheapRecipe = ['resources' => ['Дерево' => 1]];
        $svc         = new NpcRepairService();
        $gold        = $svc->computeGoldCost($cheapRecipe, $this->prices);
        $this->assertSame(10, $gold);
    }

    public function testMarkupTunedHigh(): void
    {
        $this->seedFloat('npc.repair.markup', 2.0);
        // sum=200, ×0.5=100, ×2.0=200
        $gold = (new NpcRepairService())->computeGoldCost($this->recipe, $this->prices);
        $this->assertSame(200, $gold);
    }

    public function testCostFractionTunedFull(): void
    {
        $this->seedFloat('npc.repair.cost_fraction', 1.0);
        // sum=200, ×1.0=200, ×1.5=300
        $gold = (new NpcRepairService())->computeGoldCost($this->recipe, $this->prices);
        $this->assertSame(300, $gold);
    }

    public function testUnknownResourceContributesZero(): void
    {
        // В $prices только Дерево/Камень/Железо; добавим Уран без цены → не учитывается.
        $recipeWithUnknown = ['resources' => ['Дерево' => 10, 'Уран' => 100]];
        // 10×5×0.5×1.5 = 37.5 → ceil 38
        $gold = (new NpcRepairService())->computeGoldCost($recipeWithUnknown, $this->prices);
        $this->assertSame(38, $gold);
    }

    public function testMinCostGoldTunedHigh(): void
    {
        $this->seedInt('npc.repair.min_cost_gold', 500);
        // computed 150 < min 500 → берётся 500
        $gold = (new NpcRepairService())->computeGoldCost($this->recipe, $this->prices);
        $this->assertSame(500, $gold);
    }

    public function testEmptyRecipeReturnsMinCost(): void
    {
        $svc = new NpcRepairService();
        $this->assertSame(10, $svc->computeGoldCost([], $this->prices));
        $this->assertSame(10, $svc->computeGoldCost(['resources' => []], $this->prices));
    }
}
