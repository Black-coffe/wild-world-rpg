<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Buildings\BuildOrderService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * w2-n4-base-02 — ядро стройки {@see BuildOrderService}: атомарный старт (ADR-181), база из явного id,
 * `task_settings.base_cell` (ADR-102), каталог и карточка как модель.
 *
 * Гонка «второй клиент успел между проверкой и записью» воспроизводится детерминированно: слушатель
 * `DBQuery` ловит блокировку строки персонажа (`FOR UPDATE`) и в этот момент второе соединение делает
 * то, что сделал бы соседний запрос, — забирает остаток или ставит свою стройку.
 *
 * @internal
 */
final class BuildOrderServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const MIGRATIONS = [
        '2024-03-17-222643_CreateBiomesTable',
        '2024-03-18-105708_CreateMapTable',
        '2024-03-20-153728_CreateTelegramUsersTable',
        '2024-03-20-154155_CreateCharactersTable',
        '2026-05-08-220000_AddDisableMediaFlag',
        '2024-03-18-134951_CreateActionLogTable',
        '2024-03-22-111828_CreateTasksTable',
        '2024-03-22-132411_CreateCharacterTasksTable',
        '2026-05-10-190000_AddPausedStatusToCharacterTasks',
        '2024-04-16-100640_CreateCraftedItemsTable',
        '2024-04-16-122053_CreateCraftedItemsLogTable',
        '2024-05-23-061031_CreateClaimedCellsTable',
        '2024-05-23-090819_CreateBuildingsTable',
        '2024-05-27-105534_CreateCharacterBuildingsTable',
        '2026-05-19-100000_CreateGameSettingsTable',
    ];

    private const TABLES = [
        'biomes', 'map', 'telegram_users', 'characters', 'action_log', 'resources', 'character_resources', 'tasks',
        'character_tasks', 'crafted_items', 'crafted_items_log', 'claimed_cells', 'buildings', 'character_buildings',
        'game_settings',
    ];

    private BaseConnection $conn;

    /** @var (callable(string): void)|null */
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
            $this->conn->query('ALTER TABLE character_tasks ADD task_settings TEXT NULL');
            $this->conn->query('ALTER TABLE tasks ADD handler_key VARCHAR(64) NULL');
            $this->conn->query(
                'CREATE TABLE resources (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, name_en VARCHAR(255) NULL,'
                . ' type VARCHAR(255) NULL, rarity INT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)'
            );
            $this->conn->query(
                'CREATE TABLE character_resources (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, id_characters INT UNSIGNED NOT NULL,'
                . ' id_resources INT UNSIGNED NOT NULL, quantity INT NOT NULL DEFAULT 0, custom_data TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)'
            );
            $this->seed();
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

    // ── атомарность старта ───────────────────────────────────────────────────

    public function testStartWritesTaskWithBaseCellAndConsumesExactlyOnce(): void
    {
        $r = (new BuildOrderService())->start(1, null, 'Workshop');

        $this->assertTrue($r['ok'], (string) $r['message']);
        $this->assertSame(BuildOrderService::STARTED, $r['code']);
        $this->assertSame(['building' => 'Workshop', 'base_cell' => 5], $this->taskSettings(), 'ADR-102: база-цель стройки');
        $this->assertSame([0, 100, 0], $this->resources());
        $this->assertSame([[2, 6]], $this->items(), 'предмет, ушедший в ноль, удаляется строкой; остаток — остаётся');
    }

    public function testSecondStartOnStockForOneIsRefusedWithoutPartialDeduction(): void
    {
        $service = new BuildOrderService();
        $first   = $service->start(1, null, 'Workshop');
        $this->assertTrue($first['ok']);
        $afterFirst = [$this->resources(), $this->items()];

        $second = $service->start(1, null, 'Workshop');

        $this->assertFalse($second['ok']);
        $this->assertSame(BuildOrderService::MISSING_MATERIALS, $second['code']);
        $this->assertSame(1, $this->taskCount(), 'одна задача на одну оплату');
        $this->assertSame($afterFirst, [$this->resources(), $this->items()]);
    }

    public function testDoubleTapWithStockForTwoIsRefusedAsAlreadyBuilding(): void
    {
        // Запас на две Мастерские: без гейта «уже строится» второе нажатие ставило вторую стройку.
        $this->conn->query('UPDATE character_resources SET quantity = quantity * 2');
        $this->conn->query('UPDATE crafted_items_log SET quantity = quantity * 2');
        $service = new BuildOrderService();
        $this->assertTrue($service->start(1, null, 'Workshop')['ok']);
        $afterFirst = [$this->resources(), $this->items()];

        $second = $service->start(1, null, 'Workshop');

        $this->assertFalse($second['ok']);
        $this->assertSame(BuildOrderService::ALREADY_BUILDING, $second['code']);
        $this->assertSame('already_building', $second['log']['reason'] ?? null);
        $this->assertSame(1, $this->taskCount(), 'одна стройка на двойное нажатие');
        $this->assertSame($afterFirst, [$this->resources(), $this->items()], 'вторая попытка ничего не списала');
    }

    public function testSecondClientStartingTheSameBuildIsSeenUnderTheLock(): void
    {
        $this->conn->query('UPDATE character_resources SET quantity = quantity * 2');
        $this->conn->query('UPDATE crafted_items_log SET quantity = quantity * 2');
        // Второй клиент закоммитил ту же стройку на этой базе, пока этот ждал блокировку.
        $this->onQuery('FOR UPDATE', function (): void {
            $other = $this->other();
            $other->query('SET FOREIGN_KEY_CHECKS = 0');
            $other->query("INSERT INTO character_tasks (character_id, telegram_user_id, task_id, status, start_time, task_settings) VALUES (1, 7, 1, 'in_work', NOW(), '{\"building\":\"Workshop\",\"base_cell\":5}')");
            $other->query('SET FOREIGN_KEY_CHECKS = 1');
        });

        $r = (new BuildOrderService())->start(1, null, 'Workshop');

        $this->assertSame(BuildOrderService::ALREADY_BUILDING, $r['code']);
        $this->assertSame(1, $this->taskCount(), 'только стройка второго клиента');
        $this->assertSame([3000, 1800, 800], $this->resources(), 'ничего не списано');
    }

    public function testStockTakenBetweenCheckAndWriteRollsBackEverything(): void
    {
        // Соседний клиент забирает последний предмет рецепта в момент, когда старт уже прошёл проверку
        // и взял блокировку: ресурсы и первые предметы к этому шагу уже списаны — обязаны вернуться.
        $this->onQuery('FOR UPDATE', function (): void {
            $this->other()->query('UPDATE crafted_items_log SET quantity = 0 WHERE crafted_item_id = 3');
        });

        $r = (new BuildOrderService())->start(1, null, 'Workshop');

        $this->assertFalse($r['ok']);
        $this->assertSame(BuildOrderService::RACE, $r['code']);
        $this->assertSame(0, $this->taskCount());
        $this->assertSame([1500, 900, 400], $this->resources(), 'ресурсы не списаны — откат целиком');
        $this->assertSame([[1, 15], [2, 20], [3, 0]], $this->items());
    }

    public function testParallelBuildTakingTheLastSlotIsSeenUnderTheLock(): void
    {
        $this->conn->query("INSERT INTO game_settings (setting_key, value_type, value_int, category) VALUES ('buildings.cells.max_buildings_per_cell', 'int', 1, 'buildings')");
        // Соседний старт вставил свою стройку, пока этот ждал блокировку: слот базы занят.
        $this->onQuery('FOR UPDATE', function (): void {
            // Сосед закоммитил до нашей блокировки; без FK-проверки вставка не ждёт заблокированную строку персонажа.
            $other = $this->other();
            $other->query('SET FOREIGN_KEY_CHECKS = 0');
            $other->query("INSERT INTO character_tasks (character_id, telegram_user_id, task_id, status, start_time) VALUES (1, 7, 1, 'in_work', NOW())");
            $other->query('SET FOREIGN_KEY_CHECKS = 1');
        });

        $r = (new BuildOrderService())->start(1, null, 'Workshop');

        $this->assertFalse($r['ok']);
        $this->assertSame(BuildOrderService::CELL_FULL, $r['code']);
        $this->assertSame(1, $this->taskCount(), 'только стройка соседа');
        $this->assertSame([1500, 900, 400], $this->resources());
    }

    // ── база из явного id ────────────────────────────────────────────────────

    public function testStartAndPreviewRefuseABaseThatResolveForBaseDoesNotConfirm(): void
    {
        $this->conn->query("INSERT INTO claimed_cells (id, character_id, map_cell_id, status) VALUES (2, 1, 6, 'active'), (3, 2, 5, 'active')");
        $service = new BuildOrderService();

        foreach ([2, 3, 999] as $baseId) { // своя, но не под ногами; чужая; несуществующая
            $start = $service->start(1, $baseId, 'Workshop');
            $this->assertSame(BuildOrderService::NOT_ON_BASE, $start['code'], "база {$baseId}");
            $this->assertSame(BuildOrderService::NOT_ON_BASE, $service->preview(1, $baseId, 'Workshop')['code'], "база {$baseId}");
        }
        $this->assertSame(0, $this->taskCount());
        $this->assertSame([1500, 900, 400], $this->resources());

        $this->assertTrue($service->start(1, 1, 'Workshop')['ok'], 'своя база под ногами — стройка идёт');
    }

    // ── модель ───────────────────────────────────────────────────────────────

    public function testPreviewAndCatalogAreModelsWithoutTransport(): void
    {
        $this->conn->query('INSERT INTO character_buildings (character_id, building_id, map_cell_id, level, amount) VALUES (1, 2, 5, 1, 3)');
        $service = new BuildOrderService();

        $p = $service->preview(1, 1, 'Workshop');
        $this->assertSame(BuildOrderService::PREVIEW, $p['code']);
        $this->assertTrue($p['can_start']);
        $this->assertSame(['owned' => true, 'stacks_defense' => false], $p['duplicate']);
        $this->assertSame(['Wood', 'Древесина', 1500, 1500], [$p['resources'][0]['key'], $p['resources'][0]['name'], $p['resources'][0]['need'], $p['resources'][0]['have']]);

        $catalog  = $service->catalog(1, 1);
        $workshop = array_values(array_filter($catalog['items'], static fn (array $i): bool => $i['key'] === 'Workshop'))[0];
        $this->assertSame(3, $workshop['built_count'], 'стопка на базе — из `amount`');

        $json = (string) json_encode([$p, $catalog], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('chat_id', $json);
        $this->assertStringNotContainsString('callback_data', $json);
        $this->assertStringNotContainsString('reply_markup', $json);
    }

    // ── помощники ────────────────────────────────────────────────────────────

    private function onQuery(string $needle, callable $action): void
    {
        $fired          = false;
        $this->listener = static function ($query) use ($needle, $action, &$fired): void {
            if (! $fired && str_contains((string) $query->getQuery(), $needle)) {
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

    private function taskCount(): int
    {
        return (int) $this->other()->query('SELECT COUNT(*) AS n FROM character_tasks')->getRowArray()['n'];
    }

    /** @return array<string, mixed>|null */
    private function taskSettings(): ?array
    {
        $raw = $this->other()->query('SELECT task_settings FROM character_tasks ORDER BY id LIMIT 1')->getRowArray()['task_settings'] ?? null;

        return is_string($raw) ? json_decode($raw, true) : null;
    }

    /** @return list<int> */
    private function resources(): array
    {
        return array_map('intval', array_column($this->other()->query('SELECT quantity FROM character_resources ORDER BY id')->getResultArray(), 'quantity'));
    }

    /** @return list<array{0:int,1:int}> */
    private function items(): array
    {
        return array_map(
            static fn (array $r): array => [(int) $r['crafted_item_id'], (int) $r['quantity']],
            $this->other()->query('SELECT crafted_item_id, quantity FROM crafted_items_log ORDER BY id')->getResultArray()
        );
    }

    private function seed(): void
    {
        $this->conn->query("INSERT INTO biomes (id, name, danger_level) VALUES (1, 'Лес', 1)");
        $this->conn->query('INSERT INTO map (id, cell_number, coordinate_x, coordinate_y, biome_id) VALUES (5, 5, 4, 0, 1), (6, 6, 5, 0, 1)');
        $this->conn->query("INSERT INTO telegram_users (id, telegram_id, first_name) VALUES (7, 771000003, 'Тест'), (8, 771000004, 'Сосед')");
        $this->conn->query(
            'INSERT INTO characters (id, telegram_user_id, name, level, experience, health, tired, strength, agility, intellect, gold, cell_number, disable_media)'
            . " VALUES (1, 7, 'Тест', 20, 1.5, 90, 10, 0.5, 0.01, 0.01, 60000, 5, 0), (2, 8, 'Сосед', 20, 1.5, 90, 10, 0.5, 0.01, 0.01, 0, 5, 0)"
        );
        $this->conn->query("INSERT INTO claimed_cells (id, character_id, map_cell_id, status) VALUES (1, 1, 5, 'active')");
        foreach ([1 => ['Древесина', 'Wood'], 2 => ['Вода', 'Water'], 3 => ['Глина', 'Clay']] as $id => [$name, $en]) {
            $this->conn->query("INSERT INTO resources (id, name, name_en, type, rarity) VALUES (?, ?, ?, 'plant', 1)", [$id, $name, $en]);
        }
        foreach ([1 => ['Металлические фрагменты', 'metalFragments'], 2 => ['Деревянные материалы', 'WoodMaterials'], 3 => ['Каменные блоки', 'stoneBlocks']] as $id => [$rus, $eng]) {
            $this->conn->query("INSERT INTO crafted_items (id, name_rus, name_eng, type) VALUES (?, ?, ?, 'component')", [$id, $rus, $eng]);
        }
        foreach ([1 => ['Вышка связи', 'CommunicationTower'], 2 => ['Мастерская', 'Workshop']] as $id => [$ru, $en]) {
            $this->conn->query("INSERT INTO buildings (id, name_ru, name_en, building_type) VALUES (?, ?, ?, 'production')", [$id, $ru, $en]);
        }
        $this->conn->query("INSERT INTO tasks (id, name, name_rus, min_duration, max_duration, type, parallel_execution_allowed, handler_key) VALUES (1, 'buildWorkshop', 'Стройка', 30, 90, 'build', 1, 'generic_building')");
        // Workshop: Wood 1500, Water 800, Clay 400; metalFragments 15, WoodMaterials 14, stoneBlocks 10.
        foreach ([1 => 1500, 2 => 900, 3 => 400] as $id => $qty) {
            $this->conn->query('INSERT INTO character_resources (id_characters, id_resources, quantity) VALUES (1, ?, ?)', [$id, $qty]);
        }
        foreach ([1 => 15, 2 => 20, 3 => 10] as $id => $qty) {
            $this->conn->query("INSERT INTO crafted_items_log (character_id, crafted_item_id, type, quantity) VALUES (1, ?, 'component', ?)", [$id, $qty]);
        }
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
