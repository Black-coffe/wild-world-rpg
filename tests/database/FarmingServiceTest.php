<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Farming\FarmingService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * V6 (ADR-033) — FarmingService: crop math + rotation + killswitch + knobs +
 * anti-drift (seed-рецепты резолвятся в CraftRecipes с output_type=resource).
 *
 * GameSettings читаются из game_settings (как SeasonalCraftServiceTest). Дефолты
 * проверяются БЕЗ строки в таблице (get → default), tuned — с seeded-строкой.
 *
 * @internal
 */
final class FarmingServiceTest extends CIUnitTestCase
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

        // Базовая активность слоя (как в проде после миграции).
        $this->seedBool('farming.enabled', 1);
        $this->seedBool('farming.rotation.enabled', 1);
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
    }

    private function seedInt(string $key, int $int): void
    {
        Database::connect('tests')->table('game_settings')->insert([
            'setting_key' => $key, 'category' => 'resources', 'value_type' => 'int', 'value_int' => $int,
        ]);
    }

    private function seedFloat(string $key, float $float): void
    {
        Database::connect('tests')->table('game_settings')->insert([
            'setting_key' => $key, 'category' => 'resources', 'value_type' => 'float', 'value_float' => $float,
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

    // ── crop map ─────────────────────────────────────────────────────────

    public function testCropMapIntegrity(): void
    {
        $svc   = new FarmingService();
        $crops = $svc->allCrops();
        $this->assertCount(4, $crops);
        $this->assertSame(['berries', 'mushrooms', 'fruit', 'crops'], $crops);

        foreach ($crops as $crop) {
            $meta = $svc->cropMeta($crop);
            $this->assertIsArray($meta, "meta for {$crop}");
            // seed ↔ crop round-trip.
            $this->assertSame($crop, $svc->cropForSeedNameEn($meta['seed_en']), "round-trip {$crop}");
        }
        $this->assertNull($svc->cropMeta('unknown'));
        $this->assertFalse($svc->isCrop('unknown'));
        $this->assertTrue($svc->isCrop('berries'));
    }

    public function testSeedCropMapping(): void
    {
        $svc = new FarmingService();
        $this->assertSame('berries', $svc->cropForSeedNameEn('BerrySeeds'));
        $this->assertSame('mushrooms', $svc->cropForSeedNameEn('MushroomSeeds'));
        $this->assertSame('fruit', $svc->cropForSeedNameEn('FruitSeeds'));
        $this->assertSame('crops', $svc->cropForSeedNameEn('CropSeeds'));
        $this->assertNull($svc->cropForSeedNameEn('NopeSeeds'));

        $this->assertSame('Berries', $svc->cropMeta('berries')['crop_en']);
        $this->assertSame('Mushrooms', $svc->cropMeta('mushrooms')['crop_en']);
        $this->assertSame('Fruit', $svc->cropMeta('fruit')['crop_en']);
        $this->assertSame('Crops', $svc->cropMeta('crops')['crop_en']);
    }

    // ── grow / yield ─────────────────────────────────────────────────────

    public function testGrowMinutesDefaults(): void
    {
        $svc = new FarmingService();
        $this->assertSame(30, $svc->growMinutes('berries'));
        $this->assertSame(45, $svc->growMinutes('mushrooms'));
        $this->assertSame(40, $svc->growMinutes('fruit'));
        $this->assertSame(60, $svc->growMinutes('crops'));
        $this->assertSame(0, $svc->growMinutes('unknown'));
    }

    public function testGrowMinutesTuned(): void
    {
        $this->seedInt('farming.grow.berries_minutes', 12);
        $svc = new FarmingService();
        $this->assertSame(12, $svc->growMinutes('berries'));
        // непереопределённые остаются дефолтными.
        $this->assertSame(45, $svc->growMinutes('mushrooms'));
    }

    public function testBaseYieldDefaults(): void
    {
        $svc = new FarmingService();
        $this->assertSame(8, $svc->baseYield('berries'));
        $this->assertSame(6, $svc->baseYield('mushrooms'));
        $this->assertSame(8, $svc->baseYield('fruit'));
        $this->assertSame(5, $svc->baseYield('crops'));
        $this->assertSame(0, $svc->baseYield('unknown'));
    }

    public function testHarvestYieldWithoutRotation(): void
    {
        $svc = new FarmingService();
        $this->assertSame(8, $svc->harvestYield('berries', false));
        $this->assertSame(5, $svc->harvestYield('crops', false));
    }

    public function testHarvestYieldWithRotation(): void
    {
        // default bonus 20%: 8*1.2=9.6→10, 6*1.2=7.2→7, 5*1.2=6.0→6.
        $svc = new FarmingService();
        $this->assertSame(10, $svc->harvestYield('berries', true));
        $this->assertSame(7, $svc->harvestYield('mushrooms', true));
        $this->assertSame(6, $svc->harvestYield('crops', true));
    }

    public function testHarvestYieldTunedBonus(): void
    {
        $this->seedInt('farming.rotation.bonus_percent', 50);
        $svc = new FarmingService();
        $this->assertSame(50, $svc->rotationBonusPercent());
        // 8*1.5=12.
        $this->assertSame(12, $svc->harvestYield('berries', true));
    }

    // ── rotation logic ───────────────────────────────────────────────────

    public function testShouldRotate(): void
    {
        $svc = new FarmingService();
        // первая посадка (нет истории) — baseline.
        $this->assertFalse($svc->shouldRotate(null, 'berries'));
        $this->assertFalse($svc->shouldRotate('', 'berries'));
        // та же культура — baseline.
        $this->assertFalse($svc->shouldRotate('berries', 'berries'));
        // смена культуры — севооборот.
        $this->assertTrue($svc->shouldRotate('fruit', 'berries'));
    }

    public function testRotationKillswitch(): void
    {
        Database::connect('tests')->table('game_settings')
            ->where('setting_key', 'farming.rotation.enabled')->update(['value_bool' => 0]);
        $this->cleanCache();

        $svc = new FarmingService();
        $this->assertFalse($svc->rotationEnabled());
        // смена культуры не даёт бонуса при выключенном севообороте.
        $this->assertFalse($svc->shouldRotate('fruit', 'berries'));
        $this->assertSame(8, $svc->harvestYield('berries', true));
    }

    // ── knobs ────────────────────────────────────────────────────────────

    public function testMaxConcurrentPlantings(): void
    {
        $svc = new FarmingService();
        $this->assertSame(2, $svc->maxConcurrentPlantings());

        $this->cleanCache();
        $this->seedInt('farming.max_concurrent_plantings', 5);
        $svc2 = new FarmingService();
        $this->assertSame(5, $svc2->maxConcurrentPlantings());
    }

    public function testSeedDropChanceDefaultAndClamp(): void
    {
        $svc = new FarmingService();
        $this->assertSame(0.05, $svc->seedDropChance());

        $this->cleanCache();
        $this->seedFloat('farming.seed_drop_chance', 1.5); // > 1 → clamp 1.0
        $this->assertSame(1.0, (new FarmingService())->seedDropChance());

        $this->cleanCache();
        Database::connect('tests')->table('game_settings')
            ->where('setting_key', 'farming.seed_drop_chance')->update(['value_float' => -0.3]);
        $this->assertSame(0.0, (new FarmingService())->seedDropChance());
    }

    public function testEnabledKillswitch(): void
    {
        $svc = new FarmingService();
        $this->assertTrue($svc->isEnabled());

        Database::connect('tests')->table('game_settings')
            ->where('setting_key', 'farming.enabled')->update(['value_bool' => 0]);
        $this->cleanCache();
        $this->assertFalse((new FarmingService())->isEnabled());
    }

    // ── anti-drift: seed-рецепты ↔ CraftRecipes ──────────────────────────

    public function testSeedRecipesResolveInConfig(): void
    {
        $svc = new FarmingService();
        $cfg = config('CraftRecipes');

        foreach ($svc->allCrops() as $crop) {
            $meta   = $svc->cropMeta($crop);
            $this->assertIsArray($meta);
            $key    = $meta['recipe'];
            $recipe = $cfg->get($key);
            $this->assertIsArray($recipe, "seed recipe {$key} отсутствует в CraftRecipes");
            $this->assertSame('resource', $recipe['output_type'] ?? null, "{$key}: output_type != resource");
            $this->assertSame($meta['seed_en'], $recipe['resource_name_en'] ?? null, "{$key}: resource_name_en mismatch");
            $this->assertSame($meta['seed_en'], $recipe['item_name_eng'] ?? null, "{$key}: item_name_eng mismatch");
            $this->assertSame('craft' . $meta['seed_en'], $recipe['task_name'] ?? null, "{$key}: task_name mismatch");
        }
    }
}
