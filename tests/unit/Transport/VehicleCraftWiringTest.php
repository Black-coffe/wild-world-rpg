<?php

declare(strict_types=1);

namespace Tests\Unit\Transport;

use App\Controllers\Worker;
use App\TaskHandlers\Craft\GenericCraftCompletionHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Config\Services;
use ReflectionClass;

/**
 * transport-07 (ADR-174, docs/specs/transport-system/) — проводка крафта пяти
 * машин: слот (`tasks.type='craft'`), роутер (`Worker::$taskHandlerKeyMap`),
 * и полный путь «старт → задача → completion» доводит крафт до конца без
 * молчаливого зависания.
 *
 * Изолированная схема (как `VehicleActivationServiceTest`/`VehicleRecipesTest`) —
 * своя `tasks`/`character_tasks`/`crafted_items`/`crafted_items_log`/`characters`/
 * `telegram_users` в `wildworld_tests`, не общая прод-схема (локальная база не
 * гарантирует применённые миграции story 06/07 — см. memory
 * `feedback_local_green_on_empty_test_db_proves_nothing`).
 *
 * @internal
 */
final class VehicleCraftWiringTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TASK_NAMES = [
        'craftLightCart',
        'craftMountainBike',
        'craftSnowmobile',
        'craftDraftCart',
        'craftAutonomousDrone',
    ];

    private const TABLES = [
        'tasks', 'character_tasks', 'crafted_items', 'crafted_items_log',
        'characters', 'telegram_users',
    ];

    private \CodeIgniter\Database\BaseConnection $conn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = Database::connect('tests');

        foreach (self::TABLES as $t) {
            $this->conn->query("DROP TABLE IF EXISTS {$t}");
        }

        $this->conn->query('
            CREATE TABLE tasks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(150) NOT NULL,
                handler_key VARCHAR(100) NULL,
                name_rus VARCHAR(150) NULL,
                description TEXT NULL,
                min_duration INT NULL,
                max_duration INT NULL,
                type VARCHAR(50) NULL,
                difficulty_level INT NULL,
                execution_limit INT NULL,
                parallel_execution_allowed TINYINT NULL,
                interruptible TINYINT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        $this->conn->query('
            CREATE TABLE character_tasks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                character_id INT NOT NULL,
                telegram_user_id INT NOT NULL,
                task_id INT NOT NULL,
                start_time DATETIME NULL,
                end_time DATETIME NULL,
                status VARCHAR(20) NOT NULL,
                task_settings TEXT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        $this->conn->query('
            CREATE TABLE crafted_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name_rus VARCHAR(150) NULL,
                name_eng VARCHAR(150) NULL,
                type VARCHAR(50) NULL,
                direction_craft VARCHAR(50) NULL,
                crafting_location VARCHAR(50) NULL,
                durability_count INT NULL,
                durability_time DATE NULL,
                price DECIMAL(10,2) NULL,
                status VARCHAR(20) NULL DEFAULT "active"
            )
        ');
        $this->conn->query('
            CREATE TABLE crafted_items_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                character_id INT NOT NULL,
                task_id INT NULL,
                crafted_item_id INT NOT NULL,
                type VARCHAR(50) NULL,
                direction_craft VARCHAR(50) NULL,
                crafting_location VARCHAR(50) NULL,
                durability_count INT NULL,
                durability_time DATE NULL,
                quantity INT NOT NULL DEFAULT 1,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        $this->conn->query('
            CREATE TABLE characters (
                id INT AUTO_INCREMENT PRIMARY KEY,
                agility DECIMAL(10,2) NOT NULL DEFAULT 0,
                intellect DECIMAL(10,2) NOT NULL DEFAULT 0,
                level INT NOT NULL DEFAULT 1
            )
        ');
        $this->conn->query('
            CREATE TABLE telegram_users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                telegram_id BIGINT NULL
            )
        ');
    }

    // ── 1) Слот: строка `tasks` обязана нести type=\'craft\' ────────────────

    public function testAllFiveTaskNamesMatchCraftRecipesTaskName(): void
    {
        $cfg = new \Config\CraftRecipes();
        $expected = array_map(static fn (array $r) => $r['task_name'], array_filter(
            $cfg->recipes,
            static fn (string $k) => in_array($k, ['LightCart', 'MountainBike', 'Snowmobile', 'DraftCart', 'AutonomousDrone'], true),
            ARRAY_FILTER_USE_KEY,
        ));
        sort($expected);
        $actual = self::TASK_NAMES;
        sort($actual);
        $this->assertSame($expected, $actual, 'task_name рецептов и список этого теста разошлись');
    }

    // ── 2) Роутер: `Worker::$taskHandlerKeyMap` (красный, если строку убрать) ──

    public function testEveryTransportTaskRegisteredInTaskHandlerKeyMap(): void
    {
        $keyMap = $this->extractProperty(new Worker(), 'taskHandlerKeyMap');
        foreach (self::TASK_NAMES as $taskName) {
            $this->assertArrayHasKey(
                $taskName,
                $keyMap,
                "'{$taskName}' отсутствует в Worker::\$taskHandlerKeyMap — задача завершится, а предмет молча не выдастся."
            );
            $this->assertSame('generic_craft', $keyMap[$taskName]);
        }
    }

    public function testEveryTransportTaskRegisteredInLegacyTaskHandlerMap(): void
    {
        $legacyMap = $this->extractProperty(new Worker(), 'taskHandlerMap');
        foreach (self::TASK_NAMES as $taskName) {
            $this->assertArrayHasKey($taskName, $legacyMap, "'{$taskName}' отсутствует в legacy Worker::\$taskHandlerMap");
            $this->assertSame('Craft\GenericCraftCompletionHandler', $legacyMap[$taskName]);
        }
    }

    public function testWorkerResolvesEveryTransportTaskToCompletionHandler(): void
    {
        $registry = Services::handlerRegistry();
        $this->assertNotNull($registry, 'HandlerRegistry сервис недоступен');

        $worker = new Worker();
        $refl   = new ReflectionClass($worker);
        $method = $refl->getMethod('getHandlerClassName');
        $method->setAccessible(true);

        foreach (self::TASK_NAMES as $taskName) {
            $resolved = $method->invoke($worker, $taskName);
            $this->assertSame(
                GenericCraftCompletionHandler::class,
                $resolved,
                "Worker::getHandlerClassName('{$taskName}') должен вернуть GenericCraftCompletionHandler"
            );
        }
    }

    // ── 3) Поведенческий: старт → задача → completion, до конца ────────────

    public function testCraftCompletesLightCartEndToEnd(): void
    {
        $now = date('Y-m-d H:i:s');

        // Строка `tasks` — ровно то, что заводит story 07 миграция.
        $this->conn->table('tasks')->insert([
            'name'                       => 'craftLightCart',
            'handler_key'                => 'generic_craft',
            'name_rus'                   => 'Крафт лёгкой повозки',
            'min_duration'               => 30,
            'max_duration'               => 60,
            'type'                       => 'craft',
            'difficulty_level'           => 3,
            'execution_limit'            => 0,
            'parallel_execution_allowed' => 1,
            'interruptible'              => 1,
            'created_at'                 => $now,
            'updated_at'                 => $now,
        ]);
        $taskId = (int) $this->conn->insertID();
        $taskRow = $this->conn->table('tasks')->where('id', $taskId)->get()->getRowArray();
        $this->assertSame('craft', $taskRow['type'], "tasks.type обязан быть 'craft' — иначе слот крафта его не увидит (countDistinctActiveSlots)");

        // Каталожная строка — как после TransportCatalogCleanup (story 06): name_eng='LightCart'.
        $this->conn->table('crafted_items')->insert([
            'name_rus'          => 'Лёгкая повозка',
            'name_eng'          => 'LightCart',
            'type'              => 'transport',
            'direction_craft'   => 'transport',
            'crafting_location' => 'On base',
            'durability_count'  => null,
            'price'             => 200.00,
            'status'            => 'active',
        ]);
        $craftedItemId = (int) $this->conn->insertID();

        $this->conn->table('characters')->insert(['agility' => 0, 'intellect' => 0, 'level' => 10]);
        $characterId = (int) $this->conn->insertID();

        // task_settings — ровно контракт GenericCraftActionStart::handle().
        $this->conn->table('character_tasks')->insert([
            'character_id'     => $characterId,
            'telegram_user_id' => 999999, // нет строки в telegram_users → notifyUser тихо вернётся, без сети.
            'task_id'          => $taskId,
            'start_time'       => $now,
            'end_time'         => $now,
            'status'           => 'in_work',
            'task_settings'    => json_encode(['recipe' => 'LightCart', 'quantity' => 1]),
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
        $charTaskId = (int) $this->conn->insertID();

        $task = $this->conn->table('character_tasks')->where('id', $charTaskId)->get()->getRowArray();
        $this->assertNotNull($task);

        // Реальный handler, тот же класс, на который резолвит Worker.
        (new GenericCraftCompletionHandler())->handle($task);

        $log = $this->conn->table('crafted_items_log')
            ->where('character_id', $characterId)
            ->where('crafted_item_id', $craftedItemId)
            ->get()->getRowArray();
        $this->assertNotNull($log, 'Предмет не появился в crafted_items_log — крафт завис молча');
        $this->assertSame(1, (int) $log['quantity']);

        $updatedTask = $this->conn->table('character_tasks')->where('id', $charTaskId)->get()->getRowArray();
        $this->assertSame('completed', $updatedTask['status'], 'Задача осталась в in_work — крафт не довёлся до конца');
    }

    /**
     * @return array<string, string>
     */
    private function extractProperty(Worker $worker, string $name): array
    {
        $refl = new ReflectionClass($worker);
        $prop = $refl->getProperty($name);
        $prop->setAccessible(true);
        $value = $prop->getValue($worker);
        $this->assertIsArray($value);
        /** @var array<string, string> $value */
        return $value;
    }
}
