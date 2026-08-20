<?php

declare(strict_types=1);

namespace Tests\Unit\Transport;

use App\Controllers\Telegram\Commands\Actions\CraftedResourcesAction;
use App\Controllers\Worker;
use App\TaskHandlers\Craft\GenericCraftCompletionHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Config\Services;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Telegram;
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
        'characters', 'telegram_users', 'game_settings',
    ];

    private \CodeIgniter\Database\BaseConnection $conn;

    protected function setUp(): void
    {
        parent::setUp();

        if (! defined('PHPUNIT_TESTSUITE')) {
            define('PHPUNIT_TESTSUITE', true);
        }
        // Штатная фейковая инициализация Request — без неё Request::send() падает
        // на null Telegram-инстансе (паттерн VehicleRepairTest).
        new Telegram('123456:TEST-fake-token-for-tests', 'test_bot');

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
                telegram_user_id INT NULL,
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
        $this->conn->query('
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
        // Пусто: VehicleEffectsService::isEnabled() падает на дефолт
        // world.vehicle.enabled=false — тот же дефолт, что на проде без ручной правки.
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

    // ── 4) Ревью-находка (MAJOR): килсвитч гасит дверь, а не только эффект ─────

    /**
     * Килсвитч ВКЛЮЧЁН → витрина «🚚 Транспорт» ведёт себя ровно как сегодня:
     * живые кнопки крафта, разблокированный статус в тексте.
     */
    public function testTransportShowcaseIsLiveWhenKillswitchEnabled(): void
    {
        $action    = $this->newCraftedResourcesActionWithoutConstructor();
        $character = ['id' => 0, 'level' => 20];

        $text = $this->invokeShowcaseText($action, $character, true);
        $this->assertStringContainsString('🔓 🛒 Лёгкая повозка', $text, 'при включённом килсвитче доступная машина обязана быть 🔓');
        $this->assertStringNotContainsString('ещё не открыт', $text, 'включённый килсвитч не должен показывать честную заглушку');

        $rows = $this->invokeShowcaseButtons($action, true);
        $flat = array_merge(...$rows);
        $this->assertCount(5, $flat, 'при включённом килсвитче все 5 кнопок крафта живые');
        $this->assertSame('genericCraft_LightCart_1', $flat[0]['callback_data']);
    }

    /**
     * Килсвитч ВЫКЛЮЧЕН → ни одной рабочей кнопки крафта машины и ни одной
     * строки, обещающей эффект (иначе игрок тратит ресурсы на бонус, который
     * никогда не наступит — VehicleEffectsService::profileFor() всегда нейтрален).
     */
    public function testTransportShowcaseHasNoLiveCraftAndNoEffectPromiseWhenKillswitchDisabled(): void
    {
        $action    = $this->newCraftedResourcesActionWithoutConstructor();
        $character = ['id' => 0, 'level' => 20];

        $text = $this->invokeShowcaseText($action, $character, false);
        $this->assertStringContainsString('ещё не открыт', $text, 'выключенный килсвитч обязан честно сказать «ещё не открыт»');
        $this->assertStringNotContainsString('🔓', $text, 'выключенный килсвитч не должен показывать разблокированные машины');
        $this->assertStringNotContainsString('скоро', $text, 'заглушка не обещает сроков, которых никто не давал');
        $this->assertStringNotContainsString('уровня', $text, 'выключенный килсвитч не должен упоминать level-gate — фича вообще недоступна');

        $rows = $this->invokeShowcaseButtons($action, false);
        $this->assertSame([], $rows, 'выключенный килсвитч обязан убрать все кнопки крафта машины');
    }

    // ── 5) Регресс прода: CraftedResourcesAction::reply() падал TypeError на CharacterEntity ──

    /**
     * 108 срабатываний на проде: `CharacterModel::find()`/`->first()` отдаёт
     * `CharacterEntity`, а `reply(string, string, array $character)` требовал строгий
     * `array` — `TypeError` на КАЖДОМ открытии экрана «Крафтовые ресурсы». Тест идёт
     * РЕАЛЬНЫМ путём (реальный `CallbackQuery`, реальный `handle()`), как
     * `VehicleRepairTest` — красный на коде с `array $character`, зелёный после
     * `array|CharacterEntity $character`.
     */
    public function testHandleAssemblesScreenWhenCharacterModelReturnsEntity(): void
    {
        $tgId = 222001;
        $this->conn->table('telegram_users')->insert(['telegram_id' => $tgId]);
        $tgUserId = (int) $this->conn->insertID();

        $this->conn->table('characters')->insert([
            'telegram_user_id' => $tgUserId,
            'level'             => 20,
        ]);

        // characterModel->where(...)->first() отдаёт CharacterEntity (returnType модели) —
        // никакого array-приведения в проде между БД и `reply()` нет.
        $action = new CraftedResourcesAction($this->callbackQuery($tgId, 'resourcesCrafting'));
        $response = $action->handle();

        $this->assertTrue($response->isOk(), 'экран «Крафтовые ресурсы» обязан собраться для CharacterEntity');
    }

    /** Настоящий CallbackQuery — как из реального вебхука клика по кнопке. */
    private function callbackQuery(int $tgId, string $data): CallbackQuery
    {
        return new CallbackQuery([
            'id'   => 'cbq_1',
            'from' => ['id' => $tgId, 'is_bot' => false, 'first_name' => 'Тест'],
            'message' => [
                'message_id' => 1,
                'date'       => time(),
                'chat'       => ['id' => $tgId, 'type' => 'private'],
                'text'       => 'placeholder',
            ],
            'chat_instance' => 'ci_1',
            'data'          => $data,
        ]);
    }

    private function newCraftedResourcesActionWithoutConstructor(): CraftedResourcesAction
    {
        $refl = new ReflectionClass(CraftedResourcesAction::class);

        return $refl->newInstanceWithoutConstructor();
    }

    /**
     * @param array<string,mixed> $character
     */
    private function invokeShowcaseText(CraftedResourcesAction $action, array $character, bool $vehicleEnabled): string
    {
        $method = (new ReflectionClass($action))->getMethod('transportShowcaseText');
        $method->setAccessible(true);

        /** @var string $result */
        $result = $method->invoke($action, $character, $vehicleEnabled);

        return $result;
    }

    /**
     * @return list<list<array{text:string,callback_data:string}>>
     */
    private function invokeShowcaseButtons(CraftedResourcesAction $action, bool $vehicleEnabled): array
    {
        $method = (new ReflectionClass($action))->getMethod('transportShowcaseButtonRows');
        $method->setAccessible(true);

        /** @var list<list<array{text:string,callback_data:string}>> $result */
        $result = $method->invoke($action, $vehicleEnabled);

        return $result;
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
