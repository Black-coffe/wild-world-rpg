<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Craft\ConsumableExpiryService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * ADR-094 — ConsumableExpiryService: устаревание медикаментов (срок годности +
 * деградация heal-эффекта). GameSettings из game_settings (как FoodBuffServiceTest).
 *
 * @internal
 */
final class ConsumableExpiryServiceTest extends CIUnitTestCase
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
        // По умолчанию механика включена (как сидит миграция ADR-094).
        $this->seedBool('craft.expiry.enabled', 1);
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
        $svc = new ConsumableExpiryService();
        $this->assertTrue($svc->enabled());
        $this->assertSame(50, $svc->stalePercent());
        $this->assertSame(30, $svc->defaultShelfDays());
    }

    public function testShelfLifeDaysUsesTemplateOrFallback(): void
    {
        $svc = new ConsumableExpiryService();
        $this->assertSame(40, $svc->shelfLifeDays(40));   // template
        $this->assertSame(30, $svc->shelfLifeDays(0));    // 0 → fallback default
        $this->assertSame(30, $svc->shelfLifeDays(null)); // null → fallback default
    }

    public function testStalePercentClamped(): void
    {
        $this->seedInt('craft.expiry.stale_effect_percent', 250);
        $this->cleanCache();
        $this->assertSame(100, (new ConsumableExpiryService())->stalePercent());
    }

    public function testExpiryDateFor(): void
    {
        $svc = new ConsumableExpiryService();
        $now = mktime(12, 0, 0, 6, 1, 2026); // fixed
        // template 10 дней → +10
        $this->assertSame(date('Y-m-d', $now + 10 * 86400), $svc->expiryDateFor(10, $now));
        // нет template → fallback 30 дней
        $this->assertSame(date('Y-m-d', $now + 30 * 86400), $svc->expiryDateFor(null, $now));
    }

    public function testExpiryDateNullWhenDisabled(): void
    {
        Database::connect('tests')->table('game_settings')
            ->where('setting_key', 'craft.expiry.enabled')->update(['value_bool' => 0]);
        $this->cleanCache();
        $svc = new ConsumableExpiryService();
        $this->assertFalse($svc->enabled());
        $this->assertNull($svc->expiryDateFor(10));
    }

    public function testIsExpired(): void
    {
        $svc       = new ConsumableExpiryService();
        $today     = date('Y-m-d');
        $tomorrow  = date('Y-m-d', time() + 86400);
        $yesterday = date('Y-m-d', time() - 86400);
        $this->assertFalse($svc->isExpired(null));       // бессрочно
        $this->assertFalse($svc->isExpired(''));         // бессрочно
        $this->assertFalse($svc->isExpired($today));     // последний годный день включительно
        $this->assertFalse($svc->isExpired($tomorrow));  // ещё годен
        $this->assertTrue($svc->isExpired($yesterday));  // просрочен
    }

    public function testEffectMultiplier(): void
    {
        $svc       = new ConsumableExpiryService();
        $tomorrow  = date('Y-m-d', time() + 86400);
        $yesterday = date('Y-m-d', time() - 86400);
        $this->assertSame(1.0, $svc->effectMultiplier(null));      // бессрочно
        $this->assertSame(1.0, $svc->effectMultiplier($tomorrow)); // свежо
        $this->assertSame(0.5, $svc->effectMultiplier($yesterday)); // просрочено → 50%
    }

    public function testEffectMultiplierOneWhenDisabled(): void
    {
        Database::connect('tests')->table('game_settings')
            ->where('setting_key', 'craft.expiry.enabled')->update(['value_bool' => 0]);
        $this->cleanCache();
        $yesterday = date('Y-m-d', time() - 86400);
        $this->assertSame(1.0, (new ConsumableExpiryService())->effectMultiplier($yesterday));
    }

    public function testDegradeEffectsScalesAndPreservesSign(): void
    {
        $svc       = new ConsumableExpiryService();
        $yesterday = date('Y-m-d', time() - 86400);
        // 50% по умолчанию: 25→13 (round), 4→2, -4→-2, 0→0.
        $out = $svc->degradeEffects(['health' => 25, 'tired' => 4, 'side' => -4, 'noop' => 0], $yesterday);
        $this->assertSame(13, $out['health']);
        $this->assertSame(2, $out['tired']);
        $this->assertSame(-2, $out['side']);
        $this->assertSame(0, $out['noop']);
    }

    public function testDegradeEffectsMinMagnitudeOne(): void
    {
        $this->seedInt('craft.expiry.stale_effect_percent', 10);
        $this->cleanCache();
        $svc       = new ConsumableExpiryService();
        $yesterday = date('Y-m-d', time() - 86400);
        // 10%: 1 → round(0.1)=0 → подтягивается к 1 (предмет всё ещё что-то лечит).
        $out = $svc->degradeEffects(['health' => 1, 'tired' => -1], $yesterday);
        $this->assertSame(1, $out['health']);
        $this->assertSame(-1, $out['tired']);
    }

    public function testDegradeEffectsPassthroughWhenFresh(): void
    {
        $svc      = new ConsumableExpiryService();
        $tomorrow = date('Y-m-d', time() + 86400);
        $effects  = ['health' => 25, 'tired' => 16];
        $this->assertSame($effects, $svc->degradeEffects($effects, $tomorrow)); // свежо → без изменений
        $this->assertSame($effects, $svc->degradeEffects($effects, null));      // бессрочно → без изменений
    }
}
