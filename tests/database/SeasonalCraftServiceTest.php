<?php

namespace Tests\Database;

use App\Services\World\SeasonalCraftService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * S28 (ADR-032) — SeasonalCraftService: season math + killswitch + recipe map.
 *
 * Сезон детерминирован от anchor+cycle (GameSettings). now инжектится →
 * тестируем границы без моков времени. anchor='2026-05-20' → день 0 = Зима.
 *
 * @internal
 */
final class SeasonalCraftServiceTest extends CIUnitTestCase
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

        $this->seed('seasonal.enabled', 'bool', null, 1, null);
        $this->seed('seasonal.season_count', 'int', 4, null, null);
        $this->seed('seasonal.season_duration_days', 'int', 21, null, null);
        $this->seed('seasonal.cycle_anchor_date', 'string', null, null, '2026-05-20');
        $this->seed('seasonal.recipe_unlock_count_per_season', 'int', 5, null, null);
    }

    protected function tearDown(): void
    {
        Database::connect('tests')->query('DROP TABLE IF EXISTS game_settings');
        $this->cleanCache();
        parent::tearDown();
    }

    private function seed(string $key, string $type, ?int $int, ?int $bool, ?string $str): void
    {
        Database::connect('tests')->table('game_settings')->insert([
            'setting_key'  => $key,
            'category'     => 'world',
            'value_type'   => $type,
            'value_int'    => $int,
            'value_bool'   => $bool,
            'value_string' => $str,
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

    /** anchor midnight + N дней + полдень → days=N. */
    private function dayTs(int $n): int
    {
        $anchor = strtotime('2026-05-20 00:00:00');
        return (int) $anchor + ($n * 86400) + 43200;
    }

    public function testSeasonOrderAcrossCycle(): void
    {
        $svc = new SeasonalCraftService();
        $this->assertSame('winter', $svc->getActiveSeasonKey($this->dayTs(0)));
        $this->assertSame('winter', $svc->getActiveSeasonKey($this->dayTs(20)));
        $this->assertSame('spring', $svc->getActiveSeasonKey($this->dayTs(21)));
        $this->assertSame('summer', $svc->getActiveSeasonKey($this->dayTs(42)));
        $this->assertSame('autumn', $svc->getActiveSeasonKey($this->dayTs(63)));
        $this->assertSame('winter', $svc->getActiveSeasonKey($this->dayTs(84))); // wrap
    }

    public function testBeforeAnchorIsCyclic(): void
    {
        // day -1 → pos = ((-1 % 84)+84)%84 = 83 → idx 3 = autumn.
        $svc = new SeasonalCraftService();
        $this->assertSame('autumn', $svc->getActiveSeasonKey($this->dayTs(-1)));
    }

    public function testIsSeasonActive(): void
    {
        $svc = new SeasonalCraftService();
        $this->assertTrue($svc->isSeasonActive('winter', $this->dayTs(0)));
        $this->assertFalse($svc->isSeasonActive('winter', $this->dayTs(21)));
        $this->assertFalse($svc->isSeasonActive('', $this->dayTs(0)));
    }

    public function testDaysRemaining(): void
    {
        $svc = new SeasonalCraftService();
        $this->assertSame(21, $svc->daysRemainingInSeason($this->dayTs(0)));
        $this->assertSame(1, $svc->daysRemainingInSeason($this->dayTs(20)));
        $this->assertSame(21, $svc->daysRemainingInSeason($this->dayTs(21))); // новый сезон
    }

    public function testKillswitchDisablesSeason(): void
    {
        Database::connect('tests')->table('game_settings')
            ->where('setting_key', 'seasonal.enabled')->update(['value_bool' => 0]);
        $this->cleanCache();

        $svc = new SeasonalCraftService();
        $this->assertSame('', $svc->getActiveSeasonKey($this->dayTs(0)));
        $this->assertFalse($svc->isSeasonActive('winter', $this->dayTs(0)));
        $this->assertFalse($svc->isEnabled());
        $this->assertSame([], $svc->getRecipeKeysForActiveSeason($this->dayTs(0)));
    }

    public function testRecipeKeysForActiveSeason(): void
    {
        $svc = new SeasonalCraftService();
        $winter = $svc->getRecipeKeysForActiveSeason($this->dayTs(0));
        $this->assertCount(5, $winter);
        $this->assertContains('WinterHerbalBrew', $winter);
        $this->assertContains('WinterPreserves', $winter);
        // spring (day 21) — V1: 5 рецептов «Весеннего пробуждения».
        $spring = $svc->getRecipeKeysForActiveSeason($this->dayTs(21));
        $this->assertCount(5, $spring);
        $this->assertContains('SpringFirstHerbTea', $spring);
        $this->assertContains('SpringShootsDecoction', $spring);
        // summer (day 42) — V2: 5 рецептов «Летней жары».
        $summer = $svc->getRecipeKeysForActiveSeason($this->dayTs(42));
        $this->assertCount(5, $summer);
        $this->assertContains('SummerColdKvass', $summer);
        $this->assertContains('SummerAloeBalm', $summer);
    }

    /**
     * V1/V2 anti-drift: каждый ключ непустого сезона резолвится в CraftRecipes
     * с required_season == ключ сезона. Future-proof: автоматом покрывает V3 autumn.
     */
    public function testSeasonRecipeKeysResolveInConfig(): void
    {
        $svc = new SeasonalCraftService();
        $cfg = config('CraftRecipes');

        foreach (['spring', 'summer'] as $season) {
            $keys = $svc->getRecipeKeysForSeason($season);
            $this->assertCount(5, $keys, "{$season}: ожидалось 5 рецептов");
            foreach ($keys as $key) {
                $recipe = $cfg->get($key);
                $this->assertIsArray($recipe, "{$season} recipe {$key} отсутствует в CraftRecipes");
                $this->assertSame($season, $recipe['required_season'] ?? null, "{$key}: required_season != {$season}");
                $this->assertSame($key, $recipe['item_name_eng'] ?? null, "{$key}: item_name_eng mismatch");
            }
        }
    }

    public function testActiveSeasonLabel(): void
    {
        $svc = new SeasonalCraftService();
        $this->assertSame('Зима выживания', $svc->getActiveSeasonLabel($this->dayTs(0)));
        $this->assertSame('Летняя жара', $svc->getActiveSeasonLabel($this->dayTs(42)));
    }
}
