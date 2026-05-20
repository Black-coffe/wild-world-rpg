<?php

namespace Tests\Database;

use App\Services\Tasks\ActiveTasksService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * S27 — ActiveTasksService::getCraftQueue: craft pipeline (active + queued).
 *
 * Фильтр tasks.type='craft', active(in_work) первыми + ETA, queued FIFO,
 * исключение чужих чаров и не-craft задач, парсинг qty из task_settings.
 *
 * @internal
 */
final class CraftQueueServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private int $craftTaskId = 0;
    private int $gatherTaskId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $db = Database::connect('tests');
        foreach (['character_tasks', 'tasks'] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('CREATE TABLE tasks (id INT AUTO_INCREMENT PRIMARY KEY, name_rus VARCHAR(255) NULL, type VARCHAR(50) NULL)');
        $db->query('
            CREATE TABLE character_tasks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                character_id INT NOT NULL, task_id INT NOT NULL,
                start_time DATETIME NULL, end_time DATETIME NULL,
                status VARCHAR(50) NOT NULL, task_settings TEXT NULL,
                created_at DATETIME NULL, updated_at DATETIME NULL
            )');

        $db->table('tasks')->insert(['name_rus' => 'Изготовление ножа', 'type' => 'craft']);
        $this->craftTaskId = (int) $db->insertID();
        $db->table('tasks')->insert(['name_rus' => 'Добыча ресурсов', 'type' => 'gather']);
        $this->gatherTaskId = (int) $db->insertID();
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (['character_tasks', 'tasks'] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        parent::tearDown();
    }

    private function addTask(int $charId, int $taskId, string $status, ?string $endTime, int $qty = 1): int
    {
        $db = Database::connect('tests');
        $db->table('character_tasks')->insert([
            'character_id'  => $charId,
            'task_id'       => $taskId,
            'start_time'    => date('Y-m-d H:i:s'),
            'end_time'      => $endTime,
            'status'        => $status,
            'task_settings' => json_encode(['recipe' => 'Knife', 'quantity' => $qty]),
        ]);
        return (int) $db->insertID();
    }

    public function testActiveCraftWithEta(): void
    {
        $this->addTask(1, $this->craftTaskId, 'in_work', date('Y-m-d H:i:s', time() + 1800), 2);

        $q = (new ActiveTasksService())->getCraftQueue(1);

        $this->assertCount(1, $q['active']);
        $this->assertCount(0, $q['queued']);
        $this->assertSame('Изготовление ножа', $q['active'][0]['name']);
        $this->assertSame(2, $q['active'][0]['qty']);
        $this->assertGreaterThan(1700, $q['active'][0]['seconds_left']);
        $this->assertLessThanOrEqual(1800, $q['active'][0]['seconds_left']);
    }

    public function testQueuedCraftListedFifo(): void
    {
        $this->addTask(1, $this->craftTaskId, 'in_work', date('Y-m-d H:i:s', time() + 600));
        $first  = $this->addTask(1, $this->craftTaskId, 'queued', null, 3);
        $second = $this->addTask(1, $this->craftTaskId, 'queued', null, 1);

        $q = (new ActiveTasksService())->getCraftQueue(1);

        $this->assertCount(1, $q['active']);
        $this->assertCount(2, $q['queued']);
        // FIFO по id.
        $this->assertSame($first, $q['queued'][0]['charTaskId']);
        $this->assertSame($second, $q['queued'][1]['charTaskId']);
        $this->assertSame(3, $q['queued'][0]['qty']);
    }

    public function testNonCraftTasksExcluded(): void
    {
        $this->addTask(1, $this->gatherTaskId, 'in_work', date('Y-m-d H:i:s', time() + 600));

        $q = (new ActiveTasksService())->getCraftQueue(1);

        $this->assertSame([], $q['active']);
        $this->assertSame([], $q['queued']);
    }

    public function testOtherCharactersExcluded(): void
    {
        $this->addTask(2, $this->craftTaskId, 'queued', null);

        $q = (new ActiveTasksService())->getCraftQueue(1);

        $this->assertSame([], $q['active']);
        $this->assertSame([], $q['queued']);
    }

    public function testCompletedTasksExcluded(): void
    {
        $this->addTask(1, $this->craftTaskId, 'completed', date('Y-m-d H:i:s', time() - 60));

        $q = (new ActiveTasksService())->getCraftQueue(1);

        $this->assertSame([], $q['active']);
        $this->assertSame([], $q['queued']);
    }
}
