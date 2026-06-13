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
        // DORMANT-дефолты (как сидит миграция; ttl_min_days=60 после E26 / ADR-125).
        $this->seedBool('buildings.lifecycle.ttl_enabled', 0);
        $this->seedInt('buildings.lifecycle.ttl_days_per_level', 1);
        $this->seedInt('buildings.lifecycle.ttl_min_days', 60);
        $this->seedBool('buildings.lifecycle.tax_cascade_enabled', 0);
        $this->seedInt('buildings.lifecycle.tax_cascade_grace_days', 3);
        // ADR-125 / E26 — warn-слой (dormant).
        $this->seedBool('buildings.lifecycle.warn_enabled', 0);
        $this->seedInt('buildings.lifecycle.warn_days', 7);
        $this->seedInt('buildings.lifecycle.warn_cooldown_days', 3);
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

    private function enableWarn(): void
    {
        Database::connect('tests')->table('game_settings')
            ->where('setting_key', 'buildings.lifecycle.warn_enabled')->update(['value_bool' => 1]);
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
        $this->assertFalse($svc->warnEnabled());
        $this->assertSame(1, $svc->ttlDaysPerLevel());
        $this->assertSame(60, $svc->ttlMinDays());
        $this->assertSame(3, $svc->taxCascadeGraceDays());
        $this->assertSame(7, $svc->warnDays());
        $this->assertSame(3, $svc->warnCooldownDays());
    }

    public function testTtlDaysForRespectsFloor(): void
    {
        $svc = new BaseLifecycleService();
        $this->assertSame(60, $svc->ttlDaysFor(5));    // max(60, 5) = 60 (пол)
        $this->assertSame(60, $svc->ttlDaysFor(60));   // max(60, 60) = 60
        $this->assertSame(70, $svc->ttlDaysFor(70));   // max(60, 70) = 70
        $this->assertSame(100, $svc->ttlDaysFor(100)); // L100 = 100 дней (ТЗ-анкер)
    }

    public function testDormantNeverExpires(): void
    {
        $svc = new BaseLifecycleService();
        $longAgo = date('Y-m-d H:i:s', time() - 999 * 86400);
        // оба killswitch'а OFF → база не просрочена даже спустя 999 дней, отсчёт не показываем.
        $this->assertFalse($svc->isExpired($longAgo, 50));
        $this->assertNull($svc->daysRemaining($longAgo, 50));
    }

    public function testIsExpiredWhenEnabled(): void
    {
        $this->enableTtl();
        $svc = new BaseLifecycleService();
        $now = mktime(12, 0, 0, 6, 1, 2026);
        // L10 → ttlDaysFor = 60 (пол). Визит 61 день назад → просрочена; 59 дней назад → нет.
        $visited61 = date('Y-m-d H:i:s', $now - 61 * 86400);
        $visited59 = date('Y-m-d H:i:s', $now - 59 * 86400);
        $this->assertTrue($svc->isExpired($visited61, 10, $now));
        $this->assertFalse($svc->isExpired($visited59, 10, $now));
        // пустой visit → не просрочена (безопасно).
        $this->assertFalse($svc->isExpired(null, 10, $now));
        $this->assertFalse($svc->isExpired('', 10, $now));
    }

    public function testDaysRemainingWhenEnabled(): void
    {
        $this->enableTtl();
        $svc = new BaseLifecycleService();
        $now = mktime(12, 0, 0, 6, 1, 2026);
        // L10 → 60 дней. Визит 10 дней назад → осталось 50.
        $visited10 = date('Y-m-d H:i:s', $now - 10 * 86400);
        $this->assertSame(50, $svc->daysRemaining($visited10, 10, $now));
        // просрочена → 0.
        $visited70 = date('Y-m-d H:i:s', $now - 70 * 86400);
        $this->assertSame(0, $svc->daysRemaining($visited70, 10, $now));
    }

    // ── ADR-125 / E26 — warn-слой ───────────────────────────────────────────

    public function testWarnDormant(): void
    {
        // warn_enabled и ttl_enabled OFF → не предупреждаем, отсчёт не показываем.
        $svc = new BaseLifecycleService();
        $now  = mktime(12, 0, 0, 6, 1, 2026);
        $near = date('Y-m-d H:i:s', $now - 56 * 86400); // L10: до сноса 4 дня — в окне
        $this->assertFalse($svc->shouldWarn($near, 10, null, $now));
        $this->assertNull($svc->daysRemaining($near, 10, $now));
    }

    public function testWarnWindowWhenWarnEnabledTtlOff(): void
    {
        // ВАЖНО: warn работает даже при ttl_enabled OFF (фаза A двухфазной активации).
        $this->enableWarn();
        $svc = new BaseLifecycleService();
        $now = mktime(12, 0, 0, 6, 1, 2026);
        // L10 → ttl=60, warn_days=7.
        $visited50 = date('Y-m-d H:i:s', $now - 50 * 86400); // до сноса 10 дн > 7 → не в окне
        $visited55 = date('Y-m-d H:i:s', $now - 55 * 86400); // до сноса 5 дн ≤ 7 → в окне
        $visited70 = date('Y-m-d H:i:s', $now - 70 * 86400); // просрочена → в окне
        $this->assertFalse($svc->shouldWarn($visited50, 10, null, $now));
        $this->assertTrue($svc->shouldWarn($visited55, 10, null, $now));
        $this->assertTrue($svc->shouldWarn($visited70, 10, null, $now));
        // отсчёт виден в warn-фазе (ttl OFF).
        $this->assertSame(5, $svc->daysRemaining($visited55, 10, $now));
        $this->assertSame(0, $svc->daysRemaining($visited70, 10, $now));
        // ttl_enabled при этом OFF → снос не происходит.
        $this->assertFalse($svc->isExpired($visited70, 10, $now));
    }

    public function testWarnThrottleByCooldown(): void
    {
        $this->enableWarn();
        $svc = new BaseLifecycleService();
        $now = mktime(12, 0, 0, 6, 1, 2026);
        $visited55 = date('Y-m-d H:i:s', $now - 55 * 86400); // в окне (до сноса 5 дн)
        // предупреждали 1 день назад → cooldown 3 дн ещё не прошёл → не шлём.
        $warned1 = date('Y-m-d H:i:s', $now - 1 * 86400);
        $this->assertFalse($svc->shouldWarn($visited55, 10, $warned1, $now));
        // предупреждали 4 дня назад → cooldown прошёл → шлём.
        $warned4 = date('Y-m-d H:i:s', $now - 4 * 86400);
        $this->assertTrue($svc->shouldWarn($visited55, 10, $warned4, $now));
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
