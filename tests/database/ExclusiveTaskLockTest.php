<?php

namespace Tests\Database;

use App\Services\Tasks\ActiveTasksService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * ADR-167 — правило «🔒 поверх 🔒 не начинается».
 *
 * Жалоба Анжелы (2026-08-10): «нельзя запустить сбор ресурсов, если идёт крафт на
 * костре, но можно запустить крафт на костре, если идёт сбор». Флаг
 * `tasks.parallel_execution_allowed=0` читали только полевые действия, старты
 * крафта/ремонта/переезда — нет.
 *
 * Тест держит обе половины правила: блокирующее дело действительно блокирует, а
 * фоновое (⏳) и своя же очередь — по-прежнему нет.
 *
 * @internal
 */
final class ExclusiveTaskLockTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private int $gatherTaskId = 0;
    private int $cookTaskId   = 0;
    private int $bandageTaskId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $db = Database::connect('tests');
        foreach (['character_tasks', 'tasks'] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('
            CREATE TABLE tasks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NULL, name_rus VARCHAR(255) NULL,
                type VARCHAR(50) NULL, parallel_execution_allowed TINYINT NOT NULL DEFAULT 1
            )');
        $db->query('
            CREATE TABLE character_tasks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                character_id INT NOT NULL, task_id INT NOT NULL,
                start_time DATETIME NULL, end_time DATETIME NULL,
                status VARCHAR(50) NOT NULL, task_settings TEXT NULL,
                created_at DATETIME NULL, updated_at DATETIME NULL
            )');

        $db->table('tasks')->insert(['name' => 'Gather', 'name_rus' => 'Добыча ресурсов', 'type' => 'optionally', 'parallel_execution_allowed' => 0]);
        $this->gatherTaskId = (int) $db->insertID();
        $db->table('tasks')->insert(['name' => 'craftMushroomSoup', 'name_rus' => 'Готовка: Грибная похлёбка', 'type' => 'craft', 'parallel_execution_allowed' => 0]);
        $this->cookTaskId = (int) $db->insertID();
        $db->table('tasks')->insert(['name' => 'craftBandage', 'name_rus' => 'Крафт Повязки', 'type' => 'craft', 'parallel_execution_allowed' => 1]);
        $this->bandageTaskId = (int) $db->insertID();
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (['character_tasks', 'tasks'] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        parent::tearDown();
    }

    private function addTask(int $charId, int $taskId, string $status = 'in_work', int $minutesLeft = 30): void
    {
        Database::connect('tests')->table('character_tasks')->insert([
            'character_id' => $charId,
            'task_id'      => $taskId,
            'start_time'   => date('Y-m-d H:i:s'),
            'end_time'     => date('Y-m-d H:i:s', time() + $minutesLeft * 60),
            'status'       => $status,
        ]);
    }

    public function testNoTasksMeansNothingBlocks(): void
    {
        $this->assertNull((new ActiveTasksService())->findBlockingTask(1));
    }

    /** Фоновая задача (⏳) не занимает персонажа — это обещание бейджа. */
    public function testBackgroundTaskDoesNotBlock(): void
    {
        $this->addTask(1, $this->bandageTaskId);

        $this->assertNull((new ActiveTasksService())->findBlockingTask(1));
    }

    public function testBlockingTaskIsFoundWithNameAndTimeLeft(): void
    {
        $this->addTask(1, $this->gatherTaskId, 'in_work', 42);

        $found = (new ActiveTasksService())->findBlockingTask(1);

        $this->assertIsArray($found);
        $this->assertSame('Добыча ресурсов', $found['name_rus']);
        $this->assertGreaterThan(0, $found['minutes_left']);
        $this->assertLessThanOrEqual(42, $found['minutes_left']);
    }

    /** Только in_work: очередь (queued) не выполняется, значит и не занимает. */
    public function testQueuedTaskDoesNotBlock(): void
    {
        $this->addTask(1, $this->cookTaskId, 'queued');

        $this->assertNull((new ActiveTasksService())->findBlockingTask(1));
    }

    public function testOtherCharacterTasksDoNotBlock(): void
    {
        $this->addTask(2, $this->gatherTaskId);

        $this->assertNull((new ActiveTasksService())->findBlockingTask(1));
    }

    /**
     * Повторный запуск ТОГО ЖЕ рецепта не считается конфликтом: он уйдёт в
     * очередь и дождётся своей смены, второе дело одновременно не пойдёт.
     */
    public function testSameTaskIsIgnoredSoCraftQueueKeepsWorking(): void
    {
        $this->addTask(1, $this->cookTaskId);

        $this->assertNull((new ActiveTasksService())->findBlockingTask(1, $this->cookTaskId));
    }

    /** А вот чужое 🔒-дело игнорировать нельзя — это и есть починенный случай. */
    public function testDifferentBlockingTaskStillBlocksDespiteIgnore(): void
    {
        $this->addTask(1, $this->gatherTaskId);

        $found = (new ActiveTasksService())->findBlockingTask(1, $this->cookTaskId);

        $this->assertIsArray($found);
        $this->assertSame('Добыча ресурсов', $found['name_rus']);
    }

    /**
     * Симметрия, ради которой всё затевалось: идёт добыча — готовку начать нельзя.
     * До ADR-167 этот вызов возвращал null, а костёр стартовал поверх добычи.
     */
    public function testStartingBlockingCraftDuringGatherIsRefused(): void
    {
        $this->addTask(1, $this->gatherTaskId, 'in_work', 15);

        $conflict = (new ActiveTasksService())
            ->exclusiveConflict(1, 0, 'Готовка: Грибная похлёбка', $this->cookTaskId);

        $this->assertIsString($conflict);
        $this->assertStringContainsString('Добыча ресурсов', $conflict);
        $this->assertStringContainsString('Готовка: Грибная похлёбка', $conflict);
    }

    /** Обратная сторона той же симметрии — она уже работала и должна остаться. */
    public function testStartingGatherDuringCookingIsRefused(): void
    {
        $this->addTask(1, $this->cookTaskId);

        $conflict = (new ActiveTasksService())->exclusiveConflict(1, 0, 'Добыча ресурсов');

        $this->assertIsString($conflict);
        $this->assertStringContainsString('Готовка: Грибная похлёбка', $conflict);
    }

    /**
     * Фоновый крафт поверх 🔒 остаётся разрешённым: бейдж «⏳ Идёт в фоне» обещает
     * именно это, и правило не должно втихую отбирать обещанное.
     */
    public function testStartingBackgroundCraftDuringBlockingTaskIsAllowed(): void
    {
        $this->addTask(1, $this->gatherTaskId);

        $this->assertNull((new ActiveTasksService())->exclusiveConflict(1, 1, 'Крафт Повязки'));
    }
}
