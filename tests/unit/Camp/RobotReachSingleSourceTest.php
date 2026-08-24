<?php

declare(strict_types=1);

namespace Tests\Unit\Camp;

use App\Controllers\Telegram\Commands\Actions\Camp\Buildings\Robots\RobotGathererActivator;
use App\Services\Player\RobotService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use ReflectionMethod;

/**
 * Story chat-requests-batch-09 — единственный источник охвата клеток
 * gatherer-робота. До этой story расчёт `max(1, workshopLevel + extraCells)`
 * жил В ЧЕТЫРЁХ местах: `CompleteRobotGatheringHandler` (не трогаем — story 01
 * уже правила), плюс приватные копии `reachCellsText()` в `AllRobotsHandler` и
 * `StartRobotGatheringAction` (заведены story 02), плюс экран активации
 * `RobotGathererActivator`, который вообще игнорировал `extraCells`
 * (`$cellsCount = $workshopLevel`) — Max Syskov увидел там расхождение
 * (19.08.2026): «мой после запуска обработал 8».
 *
 * Ревью вернуло BLOCK на первую версию: тест дёргал приватный делегат формулы
 * рефлексией, а сам экран мог его игнорировать (старое `$cellsCount =
 * $workshopLevel` + неиспользуемый делегат — тест был бы зелёным). Занижение
 * теперь ловится проверкой ОТРЕНДЕРЕННОГО caption'а через `buildCaption()`
 * (тоже приватный, тоже reflection, но это уже сам текст экрана, не
 * промежуточная формула в отрыве от него) — сети Telegram здесь по-прежнему нет.
 *
 * @internal
 */
final class RobotReachSingleSourceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private bool $createdGameSettings = false;
    private bool $createdBuildings = false;
    private bool $createdCraftedItems = false;
    private bool $createdCharacterBuildings = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();

        $db = Database::connect('tests');

        if (! $db->tableExists('game_settings')) {
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
            $this->createdGameSettings = true;
        }

        if (! $db->tableExists('buildings')) {
            $db->query('CREATE TABLE buildings (id INT AUTO_INCREMENT PRIMARY KEY, name_en VARCHAR(191))');
            $this->createdBuildings = true;
        }

        if (! $db->tableExists('crafted_items')) {
            $db->query('CREATE TABLE crafted_items (id INT AUTO_INCREMENT PRIMARY KEY, name_rus VARCHAR(255), name_eng VARCHAR(255), durability_count INT DEFAULT 0)');
            $this->createdCraftedItems = true;
        }

        if (! $db->tableExists('character_buildings')) {
            $db->query('CREATE TABLE character_buildings (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT, building_id INT, level INT DEFAULT 1)');
            $this->createdCharacterBuildings = true;
        }
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        if ($this->createdCharacterBuildings) {
            $db->query('DROP TABLE IF EXISTS character_buildings');
        }
        if ($this->createdCraftedItems) {
            $db->query('DROP TABLE IF EXISTS crafted_items');
        }
        if ($this->createdBuildings) {
            $db->query('DROP TABLE IF EXISTS buildings');
        }
        if ($this->createdGameSettings) {
            $db->query('DROP TABLE IF EXISTS game_settings');
        }
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

    /** @return array{robotId:int,characterId:int} */
    private function seedActivationFixture(string $nameRus, string $nameEng, int $workshopLevel): array
    {
        $db = Database::connect('tests');

        $db->table('crafted_items')->insert([
            'name_rus'         => $nameRus,
            'name_eng'         => $nameEng,
            'durability_count' => 20,
        ]);
        $robotId = (int) $db->insertID();

        $roboticsRow = $db->table('buildings')->where('name_en', 'RoboticsWorkshop')->get()->getRowArray();
        if ($roboticsRow === null) {
            $db->table('buildings')->insert(['name_en' => 'RoboticsWorkshop']);
            $roboticsId = (int) $db->insertID();
        } else {
            $roboticsId = (int) $roboticsRow['id'];
        }

        $characterId = random_int(100000, 999999);
        $db->table('character_buildings')->insert([
            'character_id' => $characterId,
            'building_id'  => $roboticsId,
            'level'        => $workshopLevel,
        ]);

        return ['robotId' => $robotId, 'characterId' => $characterId];
    }

    private function captionFor(int $robotId, int $characterId, array $logRows = []): string
    {
        $activator = new RobotGathererActivator($robotId);
        $m = new ReflectionMethod($activator, 'buildCaption');
        $m->setAccessible(true);

        return $m->invoke($activator, $characterId, $logRows);
    }

    // ── RobotService — единственный источник формулы ────────────────────────

    public function testGatheringReachCellsIsLinearNotSquared(): void
    {
        $svc = new RobotService();

        // T1/неизвестный робот: extraCells=0 → reach = workshopLevel.
        $this->assertSame(7, $svc->gatheringReachCells(7, 'RobotGatherer'));
        $this->assertSame(7, $svc->gatheringReachCells(7, null));
        // Квадрат уровня (7^2=49) — это и есть заблуждение игроков, не должен появляться.
        $this->assertNotSame(49, $svc->gatheringReachCells(7, 'RobotGatherer'));
    }

    public function testGatheringReachCellsAddsIndustrialExtraCells(): void
    {
        $svc = new RobotService();

        // Max Syskov: уровень 7 + T2-бонус (default extra_cells=1) = 8.
        $this->assertSame(8, $svc->gatheringReachCells(7, 'RobotIndustrial'));
    }

    public function testGatheringReachCellsClampsToOneAtMinimum(): void
    {
        $svc = new RobotService();
        $this->assertSame(1, $svc->gatheringReachCells(1, 'RobotGatherer'));
    }

    public function testGatheringReachCellsGrowsOnNextWorkshopLevel(): void
    {
        $svc = new RobotService();
        $current = $svc->gatheringReachCells(3, 'RobotGatherer');
        $next    = $svc->gatheringReachCells(4, 'RobotGatherer');

        $this->assertSame(3, $current);
        $this->assertSame(4, $next);
    }

    public function testFamilyOfClassifiesGathererVsExplorer(): void
    {
        $svc = new RobotService();
        $this->assertSame('gatherer', $svc->familyOf('RobotGatherer'));
        $this->assertSame('gatherer', $svc->familyOf('RobotIndustrial'));
        $this->assertSame('explorer', $svc->familyOf('RobotExplorer'));
        $this->assertSame('explorer', $svc->familyOf('RobotScout'));
        $this->assertNull($svc->familyOf('SomethingUnknown'));
        $this->assertNull($svc->familyOf(null));
    }

    // ── Экран активации: рендер caption'а, не изолированная формула ─────────

    public function testActivationScreenNoLongerUndercountsIndustrialReach(): void
    {
        // До story 09 здесь стоял голый $workshopLevel: T2-робот
        // (Промышленник) показывал 7 вместо реальных 8 в самом тексте экрана.
        $fixture = $this->seedActivationFixture('Робот-промышленник', 'RobotIndustrial', 7);

        $caption = $this->captionFor($fixture['robotId'], $fixture['characterId']);

        $this->assertStringContainsString('обходит *8* яч.', $caption, 'T2-бонус обязан входить в охват РЕНДЕРА, не только в изолированную формулу');
        $this->assertStringContainsString('след. уровне мастерской — *9*', $caption);
        $this->assertStringNotContainsString('обходит *7* яч.', $caption, 'старое занижение (голый workshopLevel) не должно вернуться');
    }

    public function testActivationScreenMatchesRobotServiceDirectlyForT1(): void
    {
        $fixture = $this->seedActivationFixture('Робот-добытчик', 'RobotGatherer', 5);
        $expected = (new RobotService())->gatheringReachCells(5, 'RobotGatherer');

        $caption = $this->captionFor($fixture['robotId'], $fixture['characterId']);

        $this->assertStringContainsString("обходит *{$expected}* яч.", $caption);
    }

    public function testActivationScreenShowsRealRobotNameNotHardcodedLabel(): void
    {
        // Max Syskov, 19.08.2026: «у меня промышленник, но в сообщении добытчик».
        $fixture = $this->seedActivationFixture('Робот-промышленник', 'RobotIndustrial', 3);

        $caption = $this->captionFor($fixture['robotId'], $fixture['characterId']);

        $this->assertStringContainsString('Робот-промышленник', $caption, 'экран обязан называть реально запущенного робота');
    }

    public function testActivationScreenFallsBackToNeutralLabelWhenNameMissing(): void
    {
        $db = Database::connect('tests');
        $db->table('crafted_items')->insert(['name_rus' => '', 'name_eng' => 'RobotGatherer', 'durability_count' => 10]);
        $robotId = (int) $db->insertID();

        $activator = new RobotGathererActivator($robotId);
        $m = new ReflectionMethod($activator, 'buildCaption');
        $m->setAccessible(true);
        $caption = $m->invoke($activator, random_int(100000, 999999), []);

        $this->assertStringContainsString('Робот-добытчик', $caption);
    }

    /**
     * Чат сообщества 2026-08-24 (Torch0010): робота отправили без Мастерской, он потратил
     * прочность и вернулся с «Мастерская отсутствует». Экран без мастерской обязан показывать
     * lock-текст с путём стройки, а не «Уровень мастерской: 1».
     */
    public function testActivationScreenLocksWhenWorkshopMissing(): void
    {
        $fixture = $this->seedActivationFixture('Робот-добытчик', 'RobotGatherer', 3);
        $characterWithoutWorkshop = $fixture['characterId'] + 1;

        $caption = $this->captionFor($fixture['robotId'], $characterWithoutWorkshop);

        $this->assertStringContainsString('🔒', $caption);
        $this->assertStringContainsString('🏗 Строить', $caption);
        $this->assertStringNotContainsString('уровень: *1*', $caption);
        $this->assertLessThanOrEqual(1024, mb_strlen($caption));
    }
}
