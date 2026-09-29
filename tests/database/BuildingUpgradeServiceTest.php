<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Buildings\BuildingUpgradeService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * w2-n4-base-02 — ядро апгрейда {@see BuildingUpgradeService}: апгрейд не проходит без золота, двойное
 * подтверждение даёт +1 уровень и одну оплату, база из явного id перепроверяется.
 *
 * «Соседний запрос между проверкой и оплатой» — детерминированно: слушатель `DBQuery` ловит чтение
 * справочника зданий (последний шаг проверки перед оплатой) и второе соединение в этот момент меняет
 * золото или уровень так, как это сделал бы параллельный клиент.
 *
 * w2-n4-tails-01: подтверждение несёт уровень «с N» — с другого уровня или без него `stale`, ничего не списано;
 * во время переезда базы превью и применение отказывают (`relocating`).
 *
 * @internal
 */
final class BuildingUpgradeServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const MIGRATIONS = [
        '2024-03-17-222643_CreateBiomesTable',
        '2024-03-18-105708_CreateMapTable',
        '2024-03-20-153728_CreateTelegramUsersTable',
        '2024-03-20-154155_CreateCharactersTable',
        '2026-05-08-220000_AddDisableMediaFlag',
        '2024-03-22-111828_CreateTasksTable',
        '2024-03-22-132411_CreateCharacterTasksTable',
        '2026-05-10-190000_AddPausedStatusToCharacterTasks',
        '2024-05-23-061031_CreateClaimedCellsTable',
        '2024-05-23-090819_CreateBuildingsTable',
        '2024-05-27-105534_CreateCharacterBuildingsTable',
        '2026-05-29-500000_W3aCreateBaseStorage',
        '2026-05-19-100000_CreateGameSettingsTable',
        '2026-05-08-190000_AddEndgameSystem',
    ];

    private const TABLES = [
        'biomes', 'map', 'telegram_users', 'characters', 'tasks', 'character_tasks', 'resources', 'character_resources', 'claimed_cells', 'buildings',
        'character_buildings', 'base_storage', 'game_settings', 'faction_endgame_scores',
    ];

    private const COST = 50000; // Config\BuildingUpgrades: уровень 2

    private BaseConnection $conn;

    /** @var (callable(mixed): void)|null */
    private $listener = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = Database::connect();
        $this->dropTables();
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $forge = Database::forge();
            foreach (self::MIGRATIONS as $file) {
                require_once APPPATH . 'Database/Migrations/' . $file . '.php';
                $class = 'App\\Database\\Migrations\\' . substr($file, 18);
                $m     = new $class($forge instanceof Forge ? $forge : null);
                $this->assertInstanceOf(Migration::class, $m);
                $m->up();
            }
            $this->conn->query(
                'CREATE TABLE resources (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, name_en VARCHAR(255) NULL,'
                . ' type VARCHAR(255) NULL, rarity INT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)'
            );
            $this->conn->query(
                'CREATE TABLE character_resources (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, id_characters INT UNSIGNED NOT NULL,'
                . ' id_resources INT UNSIGNED NOT NULL, quantity INT NOT NULL DEFAULT 0, custom_data TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)'
            );
            $this->conn->query("INSERT INTO biomes (id, name, danger_level) VALUES (1, 'Лес', 1)");
            $this->conn->query('INSERT INTO map (id, cell_number, coordinate_x, coordinate_y, biome_id) VALUES (5, 5, 4, 0, 1), (6, 6, 5, 0, 1)');
            $this->conn->query("INSERT INTO telegram_users (id, telegram_id, first_name) VALUES (7, 771000005, 'Тест'), (8, 771000006, 'Сосед')");
            $this->conn->query(
                'INSERT INTO characters (id, telegram_user_id, name, level, experience, health, tired, strength, agility, intellect, gold, cell_number, disable_media)'
                . " VALUES (1, 7, 'Тест', 20, 1.5, 90, 10, 0.5, 0.01, 0.01, 200000, 5, 0), (2, 8, 'Сосед', 20, 1.5, 90, 10, 0.5, 0.01, 0.01, 0, 6, 0)"
            );
            $this->conn->query("INSERT INTO claimed_cells (id, character_id, map_cell_id, status) VALUES (1, 1, 5, 'active'), (2, 1, 6, 'active'), (3, 2, 6, 'active')");
            $this->conn->query("INSERT INTO buildings (id, name_ru, name_en, building_type) VALUES (2, 'Мастерская', 'Workshop', 'production')");
            $this->conn->query('INSERT INTO character_buildings (id, character_id, building_id, map_cell_id, level, amount) VALUES (10, 1, 2, 5, 1, 1), (11, 1, 2, 6, 1, 1)');
        } catch (\Throwable $e) {
            $this->dropTables();

            throw $e;
        } finally {
            $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
        }
        service('cache')->clean();
    }

    protected function tearDown(): void
    {
        if ($this->listener !== null) {
            Events::removeListener('DBQuery', $this->listener);
        }
        service('cache')->clean();
        $this->dropTables();
        $this->conn->resetDataCache();
        parent::tearDown();
    }

    public function testApplyRaisesLevelOnceAndPaysOnce(): void
    {
        $r = (new BuildingUpgradeService())->apply(1, null, 2, 1);

        $this->assertTrue($r['ok'], (string) $r['message']);
        $this->assertSame([BuildingUpgradeService::APPLIED, 1, 2], [$r['code'], $r['current_level'], $r['level']]);
        $this->assertSame([2, 1], $this->levels(), 'поднята строка базы под ногами, не вторая');
        $this->assertSame(200000 - self::COST, $this->gold());
    }

    public function testApplyWithoutGoldIsRefusedAndTouchesNothing(): void
    {
        $this->conn->query('UPDATE characters SET gold = 100 WHERE id = 1');

        $r = (new BuildingUpgradeService())->apply(1, null, 2, 1);

        $this->assertFalse($r['ok']);
        $this->assertSame(BuildingUpgradeService::REFUSED, $r['code']);
        $this->assertSame([1, 1], $this->levels());
        $this->assertSame(100, $this->gold());
    }

    public function testGoldSpentBetweenCheckAndPaymentFailsTheUpgradeInsteadOfMakingItFree(): void
    {
        // Раньше `CharacterStatsService::adjust()` с полом 0: золото ушло — апгрейд всё равно применялся.
        $this->onBuildingsLookup(function (): void {
            $this->other()->query('UPDATE characters SET gold = 10 WHERE id = 1');
        });

        $r = (new BuildingUpgradeService())->apply(1, null, 2, 1);

        $this->assertFalse($r['ok']);
        $this->assertSame(BuildingUpgradeService::RACE, $r['code']);
        $this->assertSame([1, 1], $this->levels(), 'уровень не поднят без оплаты');
        $this->assertSame(10, $this->gold());
    }

    public function testSecondConfirmAfterAParallelUpgradeDoesNotPayAgain(): void
    {
        // Соседнее подтверждение того же апгрейда успело закоммититься между проверкой и оплатой этого.
        $this->onBuildingsLookup(function (): void {
            $other = $this->other();
            $other->query('UPDATE character_buildings SET level = 2 WHERE id = 10');
            $other->query('UPDATE characters SET gold = gold - ? WHERE id = 1', [self::COST]);
        });

        $r = (new BuildingUpgradeService())->apply(1, null, 2, 1);

        $this->assertFalse($r['ok']);
        $this->assertSame(BuildingUpgradeService::RACE, $r['code']);
        $this->assertSame([2, 1], $this->levels(), '+1 уровень, не +2');
        $this->assertSame(200000 - self::COST, $this->gold(), 'одна оплата — вторая откачена');
    }

    public function testBaseIdThatResolveForBaseDoesNotConfirmIsRefused(): void
    {
        $service = new BuildingUpgradeService();
        foreach ([2, 3, 999] as $baseId) { // своя, но не под ногами; чужая; несуществующая
            $this->assertSame(BuildingUpgradeService::REFUSED, $service->preview(1, $baseId, 2)['code'], "база {$baseId}");
            $this->assertSame(BuildingUpgradeService::REFUSED, $service->apply(1, $baseId, 2, 1)['code'], "база {$baseId}");
        }
        $this->assertSame([1, 1], $this->levels());
        $this->assertSame(200000, $this->gold());

        $this->assertTrue($service->apply(1, 1, 2, 1)['ok'], 'своя база под ногами — апгрейд идёт');
    }

    public function testRepeatedConfirmFromTheSameLevelIsStaleAndPaysOnce(): void
    {
        // Раньше повторный тап после коммита первого перепроверялся заново и оплачивал уровень 3.
        $service = new BuildingUpgradeService();

        $first  = $service->apply(1, null, 2, 1);
        $second = $service->apply(1, null, 2, 1);

        $this->assertTrue($first['ok'], (string) $first['message']);
        $this->assertFalse($second['ok']);
        $this->assertSame(BuildingUpgradeService::STALE, $second['code']);
        $this->assertSame(BuildingUpgradeService::TEXT_STALE, $second['message']);
        $this->assertSame([2, 1], $this->levels(), 'один уровень за два тапа');
        $this->assertSame(200000 - self::COST, $this->gold(), 'одно списание');
    }

    public function testConfirmWithoutLevelIsStaleAndTouchesNothing(): void
    {
        $r = (new BuildingUpgradeService())->apply(1, null, 2, null);

        $this->assertSame(BuildingUpgradeService::STALE, $r['code']);
        $this->assertSame([1, 1], $this->levels());
        $this->assertSame(200000, $this->gold());
    }

    public function testRelocationRefusesPreviewAndApplyWithBotText(): void
    {
        $this->conn->query("INSERT INTO tasks (id, name, name_rus) VALUES (99, 'BaseRelocation', 'Переезд базы')");
        $this->conn->query("INSERT INTO character_tasks (character_id, telegram_user_id, task_id, status, start_time, end_time) VALUES (1, 7, 99, 'in_work', NOW(), NOW() + INTERVAL 1 HOUR)");
        $service = new BuildingUpgradeService();

        foreach (['preview' => $service->preview(1, null, 2), 'apply' => $service->apply(1, null, 2, 1)] as $path => $r) {
            $this->assertFalse($r['ok'], $path);
            $this->assertSame(BuildingUpgradeService::RELOCATING, $r['code'], $path);
            $this->assertSame(\App\Services\Tasks\ActiveTasksService::TEXT_RELOCATION, $r['message'], $path);
        }
        $this->assertSame([1, 1], $this->levels());
        $this->assertSame(200000, $this->gold());

        // Переезд закончился — апгрейд снова идёт.
        $this->conn->query("UPDATE character_tasks SET status = 'completed'");
        $this->assertTrue($service->apply(1, null, 2, 1)['ok']);
    }

    // ── помощники ────────────────────────────────────────────────────────────

    private function onBuildingsLookup(callable $action): void
    {
        $fired          = false;
        $this->listener = static function ($query) use ($action, &$fired): void {
            if (! $fired && str_contains((string) $query->getQuery(), 'FROM `buildings`')) {
                $fired = true;
                $action();
            }
        };
        Events::on('DBQuery', $this->listener);
    }

    private function other(): BaseConnection
    {
        return Database::connect(null, false);
    }

    /** @return list<int> */
    private function levels(): array
    {
        return array_map('intval', array_column($this->other()->query('SELECT level FROM character_buildings ORDER BY id')->getResultArray(), 'level'));
    }

    private function gold(): int
    {
        return (int) $this->other()->query('SELECT gold FROM characters WHERE id = 1')->getRowArray()['gold'];
    }

    private function dropTables(): void
    {
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach (array_reverse(self::TABLES) as $t) {
            $this->conn->query("DROP TABLE IF EXISTS `{$t}`");
        }
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
    }
}
