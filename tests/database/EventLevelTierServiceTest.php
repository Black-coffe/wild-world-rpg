<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Events\EventLevelTierService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * E17 (ADR-117) — EventLevelTierService: killswitch + harmFactor по бэндам (новичок/середина/ветеран).
 * Dormant → 1.0 (byte-identical).
 *
 * @internal
 */
final class EventLevelTierServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();
        $db = Database::connect('tests');
        $db->query('DROP TABLE IF EXISTS game_settings');
        $db->query('CREATE TABLE game_settings (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(191), value_type VARCHAR(16) NULL, value_int INT NULL, value_float DECIMAL(15,5) NULL, value_bool TINYINT NULL, value_string TEXT NULL)');
    }

    protected function tearDown(): void
    {
        Database::connect('tests')->query('DROP TABLE IF EXISTS game_settings');
        $this->cleanCache();
        parent::tearDown();
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

    private function enableDefaults(): void
    {
        $db = Database::connect('tests');
        $db->table('game_settings')->insert(['setting_key' => 'world.events.level_tier_enabled', 'value_type' => 'bool', 'value_bool' => 1]);
        $db->table('game_settings')->insert(['setting_key' => 'world.events.tier_newbie_max_level', 'value_type' => 'int', 'value_int' => 9]);
        $db->table('game_settings')->insert(['setting_key' => 'world.events.tier_newbie_factor', 'value_type' => 'float', 'value_float' => 0.5]);
        $db->table('game_settings')->insert(['setting_key' => 'world.events.tier_veteran_min_level', 'value_type' => 'int', 'value_int' => 25]);
        $db->table('game_settings')->insert(['setting_key' => 'world.events.tier_veteran_factor', 'value_type' => 'float', 'value_float' => 1.3]);
        $this->cleanCache();
    }

    public function testDormantAlwaysOne(): void
    {
        $svc = new EventLevelTierService();
        $this->assertFalse($svc->enabled());
        $this->assertSame(1.0, $svc->harmFactor(1));
        $this->assertSame(1.0, $svc->harmFactor(60));
    }

    public function testBandsWhenEnabled(): void
    {
        $this->enableDefaults();
        $svc = new EventLevelTierService();
        $this->assertTrue($svc->enabled());
        $this->assertEqualsWithDelta(0.5, $svc->harmFactor(5), 0.001);   // новичок
        $this->assertEqualsWithDelta(0.5, $svc->harmFactor(9), 0.001);   // граница новичка
        $this->assertSame(1.0, $svc->harmFactor(10));                    // середина
        $this->assertSame(1.0, $svc->harmFactor(24));                    // середина
        $this->assertEqualsWithDelta(1.3, $svc->harmFactor(25), 0.001);  // ветеран (граница)
        $this->assertEqualsWithDelta(1.3, $svc->harmFactor(60), 0.001);  // ветеран
    }

    public function testHarmFactorForCharacter(): void
    {
        $this->enableDefaults();
        $svc = new EventLevelTierService();
        $this->assertEqualsWithDelta(0.5, $svc->harmFactorFor(['level' => 3]), 0.001);
        $this->assertEqualsWithDelta(1.3, $svc->harmFactorFor(['level' => 40]), 0.001);
        $this->assertSame(1.0, $svc->harmFactorFor(['level' => 15]));
        // Без уровня → 1 (=новичок-фактор при enabled).
        $this->assertEqualsWithDelta(0.5, $svc->harmFactorFor([]), 0.001);
    }

    // ── E17 Ф2: boonFactor (tier пользы) ───────────────────────────────────

    private function enableBoonDefaults(): void
    {
        $db = Database::connect('tests');
        $db->table('game_settings')->insert(['setting_key' => 'world.events.boon_tier_enabled', 'value_type' => 'bool', 'value_bool' => 1]);
        $db->table('game_settings')->insert(['setting_key' => 'world.events.tier_newbie_max_level', 'value_type' => 'int', 'value_int' => 9]);
        $db->table('game_settings')->insert(['setting_key' => 'world.events.tier_veteran_min_level', 'value_type' => 'int', 'value_int' => 25]);
        $db->table('game_settings')->insert(['setting_key' => 'world.events.tier_boon_newbie_factor', 'value_type' => 'float', 'value_float' => 1.3]);
        $db->table('game_settings')->insert(['setting_key' => 'world.events.tier_boon_veteran_factor', 'value_type' => 'float', 'value_float' => 1.0]);
        $this->cleanCache();
    }

    public function testBoonDormantAlwaysOne(): void
    {
        // boon_tier_enabled не сидится → default false → boonFactor=1.0 (byte-identical),
        // даже если harm-tier включён.
        $this->enableDefaults();
        $svc = new EventLevelTierService();
        $this->assertFalse($svc->boonEnabled());
        $this->assertSame(1.0, $svc->boonFactor(3));
        $this->assertSame(1.0, $svc->boonFactor(40));
    }

    public function testBoonBandsWhenEnabled(): void
    {
        $this->enableBoonDefaults();
        $svc = new EventLevelTierService();
        $this->assertTrue($svc->boonEnabled());
        $this->assertEqualsWithDelta(1.3, $svc->boonFactor(5), 0.001);   // новичок — щедрее
        $this->assertEqualsWithDelta(1.3, $svc->boonFactor(9), 0.001);   // граница новичка
        $this->assertSame(1.0, $svc->boonFactor(10));                    // середина
        $this->assertSame(1.0, $svc->boonFactor(24));                    // середина
        $this->assertEqualsWithDelta(1.0, $svc->boonFactor(25), 0.001);  // ветеран — без faucet
        $this->assertEqualsWithDelta(1.0, $svc->boonFactor(60), 0.001);  // ветеран
    }

    public function testBoonFactorForCharacter(): void
    {
        $this->enableBoonDefaults();
        $svc = new EventLevelTierService();
        $this->assertEqualsWithDelta(1.3, $svc->boonFactorFor(['level' => 3]), 0.001);
        $this->assertSame(1.0, $svc->boonFactorFor(['level' => 40]));
        $this->assertSame(1.0, $svc->boonFactorFor(['level' => 15]));
    }

    public function testBoonIndependentOfHarmKillswitch(): void
    {
        // Только boon включён (harm off) — boonFactor работает, harmFactor=1.0.
        $this->enableBoonDefaults();
        $svc = new EventLevelTierService();
        $this->assertTrue($svc->boonEnabled());
        $this->assertFalse($svc->enabled()); // harm killswitch не сидился
        $this->assertEqualsWithDelta(1.3, $svc->boonFactor(5), 0.001);
        $this->assertSame(1.0, $svc->harmFactor(5));
    }
}
