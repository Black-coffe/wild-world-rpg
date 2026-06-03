<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Bases\BaseLimitService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * ADR-095 Фаза 1a — BaseLimitService: лимит баз по уровню (tunable). GameSettings
 * из game_settings (как FoodBuffServiceTest / ConsumableExpiryServiceTest).
 *
 * @internal
 */
final class BaseLimitServiceTest extends CIUnitTestCase
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
        // дефолты ADR-095 (как сидит миграция)
        $this->seedInt('buildings.bases.levels_per_base', 50);
        $this->seedInt('buildings.bases.hard_cap', 19);
    }

    protected function tearDown(): void
    {
        Database::connect('tests')->query('DROP TABLE IF EXISTS game_settings');
        $this->cleanCache();
        parent::tearDown();
    }

    private function seedInt(string $key, int $int): void
    {
        Database::connect('tests')->table('game_settings')->insert([
            'setting_key' => $key, 'category' => 'buildings', 'value_type' => 'int', 'value_int' => $int,
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
        $svc = new BaseLimitService();
        $this->assertSame(50, $svc->levelsPerBase());
        $this->assertSame(19, $svc->hardCap());
    }

    public function testMaxBasesForLevelMatchesSpecAnchors(): void
    {
        $svc = new BaseLimitService();
        $this->assertSame(1, $svc->maxBasesForLevel(1));    // стартовая база бесплатна
        $this->assertSame(1, $svc->maxBasesForLevel(49));
        $this->assertSame(1, $svc->maxBasesForLevel(99));
        $this->assertSame(2, $svc->maxBasesForLevel(100));  // 2-я база на L100
        $this->assertSame(2, $svc->maxBasesForLevel(101));  // анкер ТЗ «на 101 = 2 базы»
        $this->assertSame(3, $svc->maxBasesForLevel(150));
        $this->assertSame(19, $svc->maxBasesForLevel(999));  // анкер ТЗ «19 на L999»
    }

    public function testHardCapClamps(): void
    {
        // запредельный уровень не превышает hard_cap.
        $svc = new BaseLimitService();
        $this->assertSame(19, $svc->maxBasesForLevel(5000));
    }

    public function testTunableLevelsPerBase(): void
    {
        Database::connect('tests')->table('game_settings')
            ->where('setting_key', 'buildings.bases.levels_per_base')->update(['value_int' => 25]);
        $this->cleanCache();
        $svc = new BaseLimitService();
        $this->assertSame(25, $svc->levelsPerBase());
        $this->assertSame(2, $svc->maxBasesForLevel(50));  // floor(50/25)=2
        $this->assertSame(4, $svc->maxBasesForLevel(100)); // floor(100/25)=4
    }

    public function testTunableHardCap(): void
    {
        Database::connect('tests')->table('game_settings')
            ->where('setting_key', 'buildings.bases.hard_cap')->update(['value_int' => 2]);
        $this->cleanCache();
        $svc = new BaseLimitService();
        $this->assertSame(2, $svc->hardCap());
        $this->assertSame(2, $svc->maxBasesForLevel(999)); // зажато до 2 (поведение «как раньше»)
    }

    public function testCanBuildMore(): void
    {
        $svc = new BaseLimitService();
        $this->assertTrue($svc->canBuildMore(100, 1));   // L100 даёт 2, есть 1 → можно
        $this->assertFalse($svc->canBuildMore(100, 2));  // есть 2 → лимит
        $this->assertTrue($svc->canBuildMore(1, 0));     // новичок без базы → можно
        $this->assertFalse($svc->canBuildMore(50, 1));   // L50 даёт 1, есть 1 → нельзя
    }

    public function testNextBaseLevel(): void
    {
        $svc = new BaseLimitService();
        $this->assertSame(1, $svc->nextBaseLevel(0));     // первая база — сразу
        $this->assertSame(100, $svc->nextBaseLevel(1));   // 2-я база — с L100
        $this->assertSame(150, $svc->nextBaseLevel(2));   // 3-я — с L150
        $this->assertNull($svc->nextBaseLevel(19));       // достигнут hard_cap → null
    }
}
