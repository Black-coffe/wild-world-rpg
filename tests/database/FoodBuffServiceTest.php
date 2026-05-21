<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Food\FoodBuffService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * V9 (ADR-034) — FoodBuffService: «Сытость» (multipliers + длительность + isWellFed
 * + killswitch). GameSettings из game_settings (как FarmingServiceTest).
 *
 * @internal
 */
final class FoodBuffServiceTest extends CIUnitTestCase
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

        $this->seedBool('food.buffs.enabled', 1);
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
            'setting_key' => $key, 'category' => 'craft', 'value_type' => 'bool', 'value_bool' => $bool,
        ]);
    }

    private function seedInt(string $key, int $int): void
    {
        Database::connect('tests')->table('game_settings')->insert([
            'setting_key' => $key, 'category' => 'craft', 'value_type' => 'int', 'value_int' => $int,
        ]);
    }

    private function seedFloat(string $key, float $float): void
    {
        Database::connect('tests')->table('game_settings')->insert([
            'setting_key' => $key, 'category' => 'craft', 'value_type' => 'float', 'value_float' => $float,
        ]);
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

    private function future(int $minutes): string
    {
        return (new \DateTime())->modify("+{$minutes} minutes")->format('Y-m-d H:i:s');
    }

    private function past(int $minutes): string
    {
        return (new \DateTime())->modify("-{$minutes} minutes")->format('Y-m-d H:i:s');
    }

    public function testDefaults(): void
    {
        $svc = new FoodBuffService();
        $this->assertTrue($svc->isEnabled());
        $this->assertSame(0.90, $svc->craftTimeMultiplier());
        $this->assertSame(1.15, $svc->gatherYieldMultiplier());
        $this->assertSame(0, $svc->mealWellFedMinutes('unknown_meal'));
        $this->assertSame(0, $svc->mealWellFedMinutes(''));
    }

    public function testMealWellFedMinutes(): void
    {
        $this->seedInt('food.hearty_stew.well_fed_minutes', 60);
        $this->seedInt('food.berry_brew.well_fed_minutes', 25);
        $svc = new FoodBuffService();
        $this->assertSame(60, $svc->mealWellFedMinutes('hearty_stew'));
        $this->assertSame(25, $svc->mealWellFedMinutes('berry_brew'));
        $this->assertSame(0, $svc->mealWellFedMinutes('mushroom_soup')); // не seeded
    }

    public function testTunedMultipliers(): void
    {
        $this->seedFloat('food.well_fed.craft_time_multiplier', 0.80);
        $this->seedFloat('food.well_fed.gather_yield_multiplier', 1.30);
        $svc = new FoodBuffService();
        $this->assertSame(0.80, $svc->craftTimeMultiplier());
        $this->assertSame(1.30, $svc->gatherYieldMultiplier());
    }

    public function testIsWellFed(): void
    {
        $svc = new FoodBuffService();
        $this->assertTrue($svc->isWellFed($this->future(10)));
        $this->assertFalse($svc->isWellFed($this->past(10)));
        $this->assertFalse($svc->isWellFed(null));
        $this->assertFalse($svc->isWellFed(''));
        $this->assertFalse($svc->isWellFed('not-a-date'));
    }

    public function testKillswitchDisablesWellFed(): void
    {
        Database::connect('tests')->table('game_settings')
            ->where('setting_key', 'food.buffs.enabled')->update(['value_bool' => 0]);
        $this->cleanCache();

        $svc = new FoodBuffService();
        $this->assertFalse($svc->isEnabled());
        // даже с будущим well_fed_until — выключено → не сыт.
        $this->assertFalse($svc->isWellFed($this->future(10)));
        $this->assertSame(1.0, $svc->craftTimeMultiplierFor($this->future(10)));
        $this->assertSame(1.0, $svc->gatherYieldMultiplierFor($this->future(10)));
    }

    public function testMultiplierForRespectsWellFed(): void
    {
        $this->seedFloat('food.well_fed.craft_time_multiplier', 0.90);
        $this->seedFloat('food.well_fed.gather_yield_multiplier', 1.15);
        $svc = new FoodBuffService();
        // сыт → множители активны.
        $this->assertSame(0.90, $svc->craftTimeMultiplierFor($this->future(5)));
        $this->assertSame(1.15, $svc->gatherYieldMultiplierFor($this->future(5)));
        // не сыт → ×1.0 (no-op).
        $this->assertSame(1.0, $svc->craftTimeMultiplierFor($this->past(5)));
        $this->assertSame(1.0, $svc->gatherYieldMultiplierFor(null));
    }

    // ── V10: свежесть (ADR-035) ───────────────────────────────────────────

    public function testFreshnessDefaults(): void
    {
        $svc = new FoodBuffService();
        $this->assertTrue($svc->freshnessEnabled());
        $this->assertSame(2, $svc->freshDays());
        $this->assertSame(50, $svc->staleSatietyPercent());
    }

    public function testIsFresh(): void
    {
        $svc      = new FoodBuffService();
        $today    = date('Y-m-d');
        $tomorrow = date('Y-m-d', time() + 86400);
        $yesterday = date('Y-m-d', time() - 86400);
        $this->assertTrue($svc->isFresh(null));          // консерва / не-еда — всегда свежо
        $this->assertTrue($svc->isFresh(''));            // пусто — свежо
        $this->assertTrue($svc->isFresh($today));        // последний свежий день включительно
        $this->assertTrue($svc->isFresh($tomorrow));     // ещё свежо
        $this->assertFalse($svc->isFresh($yesterday));   // зачерствело
    }

    public function testEffectiveWellFedMinutes(): void
    {
        $svc       = new FoodBuffService();
        $tomorrow  = date('Y-m-d', time() + 86400);
        $yesterday = date('Y-m-d', time() - 86400);
        // свежо (или консерва null) → полная длительность.
        $this->assertSame(60, $svc->effectiveWellFedMinutes(60, null));
        $this->assertSame(60, $svc->effectiveWellFedMinutes(60, $tomorrow));
        // зачерствело → ×50% (default).
        $this->assertSame(30, $svc->effectiveWellFedMinutes(60, $yesterday));
        // base 0 → 0.
        $this->assertSame(0, $svc->effectiveWellFedMinutes(0, $yesterday));
    }

    public function testFreshnessKillswitch(): void
    {
        $this->seedBool('food.freshness.enabled', 0);
        $this->cleanCache();
        $svc       = new FoodBuffService();
        $yesterday = date('Y-m-d', time() - 86400);
        $this->assertFalse($svc->freshnessEnabled());
        // свежесть выкл → даже зачерствевшее даёт полную длительность.
        $this->assertSame(60, $svc->effectiveWellFedMinutes(60, $yesterday));
    }
}
