<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Bases\BaseLifecycleService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * ADR-095 Фаза 2 — BaseLifecycleService: base-TTL + конфиг налог-каскада. DORMANT-дефолты.
 *
 * @internal
 */
final class BaseLifecycleServiceTest extends CIUnitTestCase
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
        // DORMANT-дефолты (как сидит миграция).
        $this->seedBool('buildings.lifecycle.ttl_enabled', 0);
        $this->seedInt('buildings.lifecycle.ttl_days_per_level', 1);
        $this->seedInt('buildings.lifecycle.ttl_min_days', 30);
        $this->seedBool('buildings.lifecycle.tax_cascade_enabled', 0);
        $this->seedInt('buildings.lifecycle.tax_cascade_grace_days', 3);
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
            'setting_key' => $key, 'category' => 'buildings', 'value_type' => 'bool', 'value_bool' => $bool,
        ]);
    }

    private function seedInt(string $key, int $int): void
    {
        Database::connect('tests')->table('game_settings')->insert([
            'setting_key' => $key, 'category' => 'buildings', 'value_type' => 'int', 'value_int' => $int,
        ]);
    }

    private function enableTtl(): void
    {
        Database::connect('tests')->table('game_settings')
            ->where('setting_key', 'buildings.lifecycle.ttl_enabled')->update(['value_bool' => 1]);
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

    public function testDormantDefaults(): void
    {
        $svc = new BaseLifecycleService();
        $this->assertFalse($svc->ttlEnabled());
        $this->assertFalse($svc->taxCascadeEnabled());
        $this->assertSame(1, $svc->ttlDaysPerLevel());
        $this->assertSame(30, $svc->ttlMinDays());
        $this->assertSame(3, $svc->taxCascadeGraceDays());
    }

    public function testTtlDaysForRespectsFloor(): void
    {
        $svc = new BaseLifecycleService();
        $this->assertSame(30, $svc->ttlDaysFor(5));    // max(30, 5) = 30 (пол)
        $this->assertSame(30, $svc->ttlDaysFor(30));   // max(30, 30) = 30
        $this->assertSame(50, $svc->ttlDaysFor(50));   // max(30, 50) = 50
        $this->assertSame(100, $svc->ttlDaysFor(100)); // L100 = 100 дней (ТЗ-анкер)
    }

    public function testDormantNeverExpires(): void
    {
        $svc = new BaseLifecycleService();
        $longAgo = date('Y-m-d H:i:s', time() - 999 * 86400);
        // killswitch OFF → база не просрочена даже спустя 999 дней.
        $this->assertFalse($svc->isExpired($longAgo, 50));
        $this->assertNull($svc->daysRemaining($longAgo, 50));
    }

    public function testIsExpiredWhenEnabled(): void
    {
        $this->enableTtl();
        $svc = new BaseLifecycleService();
        $now = mktime(12, 0, 0, 6, 1, 2026);
        // L10 → ttlDaysFor = 30. Визит 31 день назад → просрочена; 29 дней назад → нет.
        $visited31 = date('Y-m-d H:i:s', $now - 31 * 86400);
        $visited29 = date('Y-m-d H:i:s', $now - 29 * 86400);
        $this->assertTrue($svc->isExpired($visited31, 10, $now));
        $this->assertFalse($svc->isExpired($visited29, 10, $now));
        // пустой visit → не просрочена (безопасно).
        $this->assertFalse($svc->isExpired(null, 10, $now));
        $this->assertFalse($svc->isExpired('', 10, $now));
    }

    public function testDaysRemainingWhenEnabled(): void
    {
        $this->enableTtl();
        $svc = new BaseLifecycleService();
        $now = mktime(12, 0, 0, 6, 1, 2026);
        // L10 → 30 дней. Визит 10 дней назад → осталось 20.
        $visited10 = date('Y-m-d H:i:s', $now - 10 * 86400);
        $this->assertSame(20, $svc->daysRemaining($visited10, 10, $now));
        // просрочена → 0.
        $visited40 = date('Y-m-d H:i:s', $now - 40 * 86400);
        $this->assertSame(0, $svc->daysRemaining($visited40, 10, $now));
    }

    public function testTunableMinDaysAndPerLevel(): void
    {
        // Строгий «level дней»: min=0... но min зажат к 1; ставим min=1, perLevel=2.
        Database::connect('tests')->table('game_settings')
            ->where('setting_key', 'buildings.lifecycle.ttl_min_days')->update(['value_int' => 1]);
        Database::connect('tests')->table('game_settings')
            ->where('setting_key', 'buildings.lifecycle.ttl_days_per_level')->update(['value_int' => 2]);
        $this->cleanCache();
        $svc = new BaseLifecycleService();
        $this->assertSame(1, $svc->ttlMinDays());
        $this->assertSame(2, $svc->ttlDaysPerLevel());
        $this->assertSame(100, $svc->ttlDaysFor(50)); // max(1, 50×2) = 100
    }
}
