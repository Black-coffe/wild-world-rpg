<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Player\CaravanService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * V25 (ADR-057) — CaravanService: GameSettings reader + computePricePerUnit.
 *
 * @internal
 */
final class CaravanServiceTest extends CIUnitTestCase
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
            'setting_key' => $key, 'category' => 'world', 'value_type' => 'bool', 'value_bool' => $bool,
        ]);
        $this->cleanCache();
    }

    private function seedFloat(string $key, float $float): void
    {
        Database::connect('tests')->table('game_settings')->insert([
            'setting_key' => $key, 'category' => 'world', 'value_type' => 'float', 'value_float' => $float,
        ]);
        $this->cleanCache();
    }

    private function seedInt(string $key, int $int): void
    {
        Database::connect('tests')->table('game_settings')->insert([
            'setting_key' => $key, 'category' => 'world', 'value_type' => 'int', 'value_int' => $int,
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
        $svc = new CaravanService();
        $this->assertTrue($svc->enabled());
        $this->assertSame(5, $svc->maxActive());
        $this->assertSame(120, $svc->lifetimeMinutes());
        $this->assertEqualsWithDelta(0.3, $svc->discountPercent(), 1e-9);
        $this->assertSame(50, $svc->qtyMin());
        $this->assertSame(200, $svc->qtyMax());
    }

    public function testKillswitchOff(): void
    {
        $this->seedBool('caravan.enabled', 0);
        $this->assertFalse((new CaravanService())->enabled());
    }

    public function testDiscountPriceFormula(): void
    {
        // market=100, discount=0.3 → ceil(100*0.7) = 70
        $svc = new CaravanService();
        $this->assertSame(70, $svc->computePricePerUnit(100));
    }

    public function testDiscountTunedHigh(): void
    {
        $this->seedFloat('caravan.discount_percent', 0.5);
        // ceil(100*0.5)=50
        $this->assertSame(50, (new CaravanService())->computePricePerUnit(100));
    }

    public function testMinPriceOne(): void
    {
        // Очень дешёвый ресурс — нижняя граница 1.
        $svc = new CaravanService();
        $this->assertSame(1, $svc->computePricePerUnit(1));
        $this->assertSame(1, $svc->computePricePerUnit(0));
    }

    public function testQtyMaxAlwaysAtLeastMin(): void
    {
        // Если сеттинг qty_max < qty_min, сервис выдаёт max(qtyMin, configured).
        $this->seedInt('caravan.qty_min', 100);
        $this->seedInt('caravan.qty_max', 30);
        $svc = new CaravanService();
        $this->assertSame(100, $svc->qtyMin());
        $this->assertSame(100, $svc->qtyMax());
    }

    public function testMaxActiveTuned(): void
    {
        $this->seedInt('caravan.max_active', 12);
        $this->assertSame(12, (new CaravanService())->maxActive());
    }

    public function testLifetimeMinutesTuned(): void
    {
        $this->seedInt('caravan.lifetime_minutes', 60);
        $this->assertSame(60, (new CaravanService())->lifetimeMinutes());
    }

    // ── W14a (ADR-068): multi-resource bundle config ────────────────────

    public function testBundleDefaultsDormant(): void
    {
        $svc = new CaravanService();
        $this->assertEqualsWithDelta(0.0, $svc->bundleChance(), 1e-9); // default 0 = dormant
        $this->assertSame(2, $svc->bundleMin());
        $this->assertSame(4, $svc->bundleMax());
    }

    public function testBundleChanceTuned(): void
    {
        $this->seedFloat('caravan.bundle_chance', 0.3);
        $this->assertEqualsWithDelta(0.3, (new CaravanService())->bundleChance(), 1e-9);
    }

    public function testBundleMaxAlwaysAtLeastMin(): void
    {
        $this->seedInt('caravan.bundle_min', 4);
        $this->seedInt('caravan.bundle_max', 2);
        $svc = new CaravanService();
        $this->assertSame(4, $svc->bundleMin());
        $this->assertSame(4, $svc->bundleMax());
    }

    public function testBundleChanceClampedToRange(): void
    {
        $this->seedFloat('caravan.bundle_chance', 5.0); // вне [0,1] → fallback 0
        $this->assertEqualsWithDelta(0.0, (new CaravanService())->bundleChance(), 1e-9);
    }
}
