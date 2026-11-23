<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Craft\CraftOrderService;
use App\Services\Craft\CraftQueueService;
use App\Services\Tasks\ActiveTasksService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * s1-atomicity-03 — крафт пишет строку `character_tasks` через ядро слота (C2) и переводит очередь
 * через `moveToInWork()` (C4). Гонка: старт 🔒-крафта прошёл гейт ADR-167 по снимку, где слот пуст,
 * а до вставки слот занимает чужая 🔒-задача. БД (`UNIQUE uq_ct_exclusive_slot`) не пускает вторую
 * строку; старт отдаёт текст ADR-167 (`slotTakenText()`, C5), рюкзак, компоненты и золото целы (C6).
 *
 * Колонки и индекс S1 — вызовом `up()` миграции story 01; `character_tasks` до неё — рукописная
 * (без FK), остальные таблицы — миграциями, как в {@see CraftOrderServiceTest}.
 *
 * @internal
 */
final class CraftExclusiveSlotRefusalTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const RED_REASON = 'craft start does not refuse on a taken exclusive slot';

    private const S1_MIGRATION = '2026-12-25-100000_S1CharacterTasksExclusiveSlot';

    private const MIGRATIONS = [
        '2024-03-17-222643_CreateBiomesTable',
        '2024-03-18-105708_CreateMapTable',
        '2024-03-20-153728_CreateTelegramUsersTable',
        '2024-03-20-154155_CreateCharactersTable',
        '2026-05-08-220000_AddDisableMediaFlag',
        '2024-03-18-134951_CreateActionLogTable',
        '2024-03-22-111828_CreateTasksTable',
        '2024-04-16-100640_CreateCraftedItemsTable',
        '2024-04-16-122053_CreateCraftedItemsLogTable',
        '2024-05-23-061031_CreateClaimedCellsTable',
        '2024-05-23-090819_CreateBuildingsTable',
        '2024-05-27-105534_CreateCharacterBuildingsTable',
        '2026-05-29-500000_W3aCreateBaseStorage',
        '2026-05-19-100000_CreateGameSettingsTable',
        '2024-05-15-131853_CreateFactionsTable',
        '2024-05-15-132233_CreateCharacterFactionsTable',
        '2026-05-25-170000_V20CreateFactionProjects',
        '2026-11-24-100000_CreateCharacterDebuffs',
    ];

    private const TABLES = [
        'biomes', 'map', 'telegram_users', 'characters', 'action_log', 'resources', 'character_resources', 'tasks',
        'character_tasks', 'crafted_items', 'crafted_items_log', 'claimed_cells', 'buildings', 'character_buildings',
        'base_storage', 'game_settings', 'factions', 'character_factions', 'faction_projects', 'character_debuffs',
    ];

    private const CHAR = 1;

    private BaseConnection $conn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = Database::connect();
        $this->dropTables();
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $forge = Database::forge();
            foreach (self::MIGRATIONS as $file) {
                $this->migration($file, $forge instanceof Forge ? $forge : null)->up();
            }
            $this->conn->query(
                "CREATE TABLE character_tasks (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, character_id INT UNSIGNED NOT NULL,"
                . " telegram_user_id INT UNSIGNED NOT NULL, task_id INT UNSIGNED NOT NULL, start_time DATETIME NOT NULL, end_time DATETIME NULL,"
                . " status ENUM('in_work','completed','interrupted','queued','paused') NOT NULL DEFAULT 'in_work', task_settings TEXT NULL,"
                . ' created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)'
            );
            $this->conn->query(
                'CREATE TABLE resources (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, name_en VARCHAR(255) NULL,'
                . ' biome_id TEXT NULL, is_tradeable TINYINT(1) NOT NULL DEFAULT 1, type VARCHAR(255) NULL, price INT NULL, buy_price INT NULL,'
                . ' sell_price INT NULL, rarity INT NULL, level_required INT NULL, icon_text VARCHAR(255) NULL, created_at DATETIME NULL, updated_at DATETIME NULL)'
            );
            $this->conn->query(
                'CREATE TABLE character_resources (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, id_characters INT UNSIGNED NOT NULL,'
                . ' id_resources INT UNSIGNED NOT NULL, quantity INT NOT NULL DEFAULT 0, custom_data TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL,'
                . ' UNIQUE KEY uq_cr_char_resource (id_characters, id_resources))'
            );
            $this->seed();
            $this->migration(self::S1_MIGRATION, $forge instanceof Forge ? $forge : null)->up();
        } catch (\Throwable $e) {
            $this->dropTables();

            throw $e;
        } finally {
            $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
        }
        $this->conn->resetDataCache();
        $this->mockCache();
    }

    protected function tearDown(): void
    {
        $this->dropTables();
        parent::tearDown();
    }

    /**
     * Гонка: гейт старта видел пустой слот, а до вставки слот заняла чужая 🔒-задача. Старт
     * отказывает текстом ADR-167, а не ошибкой транзакции; новой строки нет, ничего не списано.
     */
    public function testStartRefusesWithSlotTakenTextWhenExclusiveSlotIsTakenAfterTheGate(): void
    {
        $this->exclusiveRow($this->taskId('gatherBlocking'), 'in_work', null);
        $before = $this->state();

        $out = $this->serviceWithStaleGate()->start(self::CHAR, 'BasicMedKit', 1);

        $this->assertSame([false, CraftOrderService::BUSY], [$out['ok'], $out['code']], self::RED_REASON);
        $this->assertSame((new ActiveTasksService())->slotTakenText(self::CHAR, 'Изготовление аптечки'), $out['message'], self::RED_REASON);
        $this->assertStringContainsString('Добыча (блокирующая)', $out['message']);
        $this->assertSame($before, $this->state(), 'рюкзак, компоненты, золото и строки задач не изменились');
    }

    /** Параллельный (⏳) крафт рядом с 🔒-задачей в работе стартует как раньше. */
    public function testParallelCraftStartsNextToAnExclusiveTaskInWork(): void
    {
        $this->exclusiveRow($this->taskId('gatherBlocking'), 'in_work', null);

        $out = (new CraftOrderService())->start(self::CHAR, 'Bandage', 1);

        $this->assertTrue($out['ok'], $out['message']);
        $this->assertSame(CraftOrderService::STARTED, $out['code']);
        $this->assertSame(
            '2',
            (string) $this->conn->query("SELECT COUNT(*) AS n FROM character_tasks WHERE status = 'in_work'")->getRow('n')
        );
    }

    /**
     * Продвижение очереди: 🔒-крафт, которому `moveToInWork()` не дал слот, остаётся `queued`,
     * а продвижение отдаёт `null` без исключения (A4).
     */
    public function testQueuedExclusiveCraftStaysQueuedWhenTheSlotIsTaken(): void
    {
        $this->exclusiveRow($this->taskId('gatherBlocking'), 'in_work', null);
        $medKit   = $this->taskId('craftBasicMedKit');
        $queuedId = $this->exclusiveRow($medKit, 'queued', 'BasicMedKit');

        $promoted = (new CraftQueueService())->promoteNext(self::CHAR, $medKit);

        $this->assertNull($promoted, self::RED_REASON);
        $this->assertSame(
            'queued',
            $this->conn->query('SELECT status FROM character_tasks WHERE id = ?', [$queuedId])->getRow('status')
        );
    }

    /** Ядро, у которого гейт прошёл по снимку до того, как слот занял параллельный запрос. */
    private function serviceWithStaleGate(): CraftOrderService
    {
        return new class () extends CraftOrderService {
            public function gateError(string $recipeKey, array $recipe, array|\App\Entities\CharacterEntity $character, array $taskRow, int $quantity): ?array
            {
                return null;
            }
        };
    }

    /** 🔒-строка задачи с флагом S1 (`is_exclusive = 1`), как её пишет `insertExclusiveTaskRow()`. */
    private function exclusiveRow(int $taskId, string $status, ?string $recipe): int
    {
        $this->conn->query(
            'INSERT INTO character_tasks (character_id, telegram_user_id, task_id, start_time, end_time, status, task_settings, is_exclusive, created_at, updated_at)'
            . ' VALUES (1, 7, ?, ?, ?, ?, ?, 1, NOW(), NOW())',
            [
                $taskId,
                date('Y-m-d H:i:s', time() - 30),
                $status === 'in_work' ? date('Y-m-d H:i:s', time() + 3570) : null,
                $status,
                $recipe === null ? null : json_encode(['recipe' => $recipe, 'quantity' => 1]),
            ]
        );

        return (int) $this->conn->insertID();
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        return [
            'rows'      => $this->conn->query('SELECT id, task_id, status FROM character_tasks ORDER BY id')->getResultArray(),
            'resources' => $this->conn->query('SELECT id_resources, quantity FROM character_resources ORDER BY id_resources')->getResultArray(),
            'items'     => $this->conn->query('SELECT crafted_item_id, quantity FROM crafted_items_log ORDER BY id')->getResultArray(),
            'gold'      => $this->conn->query('SELECT gold FROM characters WHERE id = 1')->getRow('gold'),
        ];
    }

    private function taskId(string $name): int
    {
        $id = $this->conn->query('SELECT id FROM tasks WHERE name = ?', [$name])->getRow('id');

        return is_numeric($id) ? (int) $id : 0;
    }

    private function seed(): void
    {
        $this->conn->query("INSERT INTO biomes (id, name, danger_level) VALUES (1, 'b1', 1)");
        $this->conn->query('INSERT INTO map (id, cell_number, coordinate_x, coordinate_y, biome_id) VALUES (5, 5, 4, 0, 1), (6, 6, 5, 0, 1)');
        $this->conn->query("INSERT INTO telegram_users (id, telegram_id, first_name) VALUES (7, 555003, 'Тест')");
        $this->conn->query(
            'INSERT INTO characters (id, telegram_user_id, name, level, experience, health, tired, strength, agility, intellect, gold, cell_number, disable_media)'
            . " VALUES (1, 7, 'Тест', 5, 1.5, 90, 10, 0.5, 0.01, 0.01, 150, 6, 1)"
        );
        foreach ([1 => 'Травы', 2 => 'Кора деревьев', 3 => 'Водоросли', 4 => 'Грибы', 5 => 'Мед', 6 => 'Алоэ', 7 => 'Вода'] as $id => $name) {
            $this->conn->query("INSERT INTO resources (id, name, name_en, type, rarity) VALUES (?, ?, ?, 'plant', 1)", [$id, $name, 'r' . $id]);
        }
        foreach ([1 => 20, 2 => 20, 3 => 30, 4 => 8, 5 => 4, 6 => 8, 7 => 22] as $id => $qty) {
            $this->conn->query('INSERT INTO character_resources (id_characters, id_resources, quantity) VALUES (1, ?, ?)', [$id, $qty]);
        }
        $this->conn->query("INSERT INTO crafted_items (id, name_rus, name_eng, type) VALUES (1, 'Повязка', 'Bandage', 'drug')");
        $this->conn->query("INSERT INTO crafted_items_log (character_id, crafted_item_id, type, quantity) VALUES (1, 1, 'drug', 7)");
        foreach ([
            ['craftBandage', 'Изготовление повязки', 1, 'craft'],
            ['craftBasicMedKit', 'Изготовление аптечки', 0, 'craft'],
            ['gatherBlocking', 'Добыча (блокирующая)', 0, 'gather'],
        ] as [$name, $rus, $parallel, $type]) {
            $this->conn->query(
                'INSERT INTO tasks (name, name_rus, min_duration, max_duration, type, parallel_execution_allowed) VALUES (?, ?, 10, 30, ?, ?)',
                [$name, $rus, $type, $parallel]
            );
        }
    }

    private function migration(string $file, ?Forge $forge): Migration
    {
        require_once APPPATH . 'Database/Migrations/' . $file . '.php';
        $class = 'App\\Database\\Migrations\\' . substr($file, 18);
        $m     = new $class($forge);
        $this->assertInstanceOf(Migration::class, $m);

        return $m;
    }

    private function dropTables(): void
    {
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach (array_reverse(self::TABLES) as $t) {
            $this->conn->query("DROP TABLE IF EXISTS `{$t}`");
        }
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
        $this->conn->resetDataCache();
    }
}
