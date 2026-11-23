<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Models\CharacterTaskModel;
use App\Services\Db\WriteOutcome;
use App\Services\Tasks\ActiveTasksService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * s1-atomicity-01 (ask 2) — «одна 🔒-задача в работе» держит БД, а не только гейт ADR-167.
 *
 * `character_tasks.exclusive_slot` = `character_id` только у строки `in_work` с `is_exclusive = 1`,
 * `UNIQUE uq_ct_exclusive_slot` по ней не пускает вторую. Колонки и индекс берутся вызовом `up()`
 * настоящей миграции S1, базовые `tasks`/`character_tasks` — рукописные (у `task_settings` нет
 * миграции, дрейф прода).
 *
 * Гонка: оба старта прошли гейт `findBlockingTask()`; между решением первого старта (чтение флага
 * задачи) и его вставкой второе соединение успевает вставить свою 🔒-строку.
 *
 * @internal
 */
final class ExclusiveTaskSlotConstraintTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const RED_REASON = 'exclusive task slot is not held by the database';

    private const MIGRATION = '2026-12-25-100000_S1CharacterTasksExclusiveSlot';

    private const LOCK_CACHE_KEY = 'game_settings_craft_exclusive_lock_enabled';

    private const CHAR = 7;

    private BaseConnection $conn;

    private int $gatherTaskId = 0;
    private int $cookTaskId   = 0;
    private int $bandageTaskId = 0;

    /** @var (callable(object): void)|null */
    private $listener = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->conn = Database::connect();
        $this->dropTables();
        $this->conn->query('
            CREATE TABLE tasks (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NULL, name_rus VARCHAR(255) NULL,
                type VARCHAR(50) NULL, parallel_execution_allowed TINYINT NOT NULL DEFAULT 1
            )');
        $this->conn->query("
            CREATE TABLE character_tasks (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                character_id INT UNSIGNED NOT NULL,
                telegram_user_id BIGINT NULL,
                task_id INT UNSIGNED NOT NULL,
                start_time DATETIME NOT NULL, end_time DATETIME NULL,
                status ENUM('in_work','completed','interrupted','queued','paused') NOT NULL DEFAULT 'in_work',
                task_settings TEXT NULL,
                created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL
            )");

        $this->conn->table('tasks')->insert(['name' => 'Gather', 'name_rus' => 'Добыча ресурсов', 'type' => 'optionally', 'parallel_execution_allowed' => 0]);
        $this->gatherTaskId = (int) $this->conn->insertID();
        $this->conn->table('tasks')->insert(['name' => 'craftMushroomSoup', 'name_rus' => 'Готовка: Грибная похлёбка', 'type' => 'craft', 'parallel_execution_allowed' => 0]);
        $this->cookTaskId = (int) $this->conn->insertID();
        $this->conn->table('tasks')->insert(['name' => 'craftBandage', 'name_rus' => 'Крафт Повязки', 'type' => 'craft', 'parallel_execution_allowed' => 1]);
        $this->bandageTaskId = (int) $this->conn->insertID();

        $this->setLock(true);
        $this->migration()->up();
        $this->conn->resetDataCache();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->listener !== null) {
                Events::removeListener('DBQuery', $this->listener);
                $this->listener = null;
            }
            service('cache')->delete(self::LOCK_CACHE_KEY);
            $this->dropTables();
        } finally {
            parent::tearDown();
        }
    }

    public function testTwoConcurrentExclusiveStartsLetExactlyOneThrough(): void
    {
        $other = Database::connect('tests', false);
        $this->assertInstanceOf(BaseConnection::class, $other);
        $first  = new ActiveTasksService();
        $second = new ActiveTasksService($other);

        // Оба старта прошли гейт ADR-167: слот пуст на момент проверки.
        $this->assertNull($first->findBlockingTask(self::CHAR));
        $this->assertNull($second->findBlockingTask(self::CHAR));

        $secondOutcome = null;
        $fired         = false;
        $secondRow     = $this->row($this->cookTaskId);
        $this->listener = static function (object $query) use (&$fired, &$secondOutcome, $second, $secondRow): void {
            if ($fired || ! $query instanceof Query || ! str_contains($query->getQuery(), 'parallel_execution_allowed')) {
                return;
            }
            $fired         = true;
            $secondOutcome = $second->insertExclusiveTaskRow($secondRow);
        };
        Events::on('DBQuery', $this->listener);

        $firstOutcome = $first->insertExclusiveTaskRow($this->row($this->gatherTaskId));

        $this->assertTrue($fired, self::RED_REASON . ': the second start never interleaved');
        $this->assertSame(WriteOutcome::Applied, $secondOutcome, self::RED_REASON);
        $this->assertSame(WriteOutcome::Refused, $firstOutcome, self::RED_REASON);
        $this->assertSame(1, $this->countRows('in_work'), self::RED_REASON);
        $this->assertSame(0, $first->lastInsertedTaskId(), self::RED_REASON);
        $this->assertGreaterThan(0, $second->lastInsertedTaskId(), self::RED_REASON);
    }

    public function testParallelTaskRunsNextToExclusiveOne(): void
    {
        $svc = new ActiveTasksService();

        $this->assertSame(WriteOutcome::Applied, $svc->insertExclusiveTaskRow($this->row($this->gatherTaskId)), self::RED_REASON);
        $this->assertSame(WriteOutcome::Applied, $svc->insertExclusiveTaskRow($this->row($this->bandageTaskId)), self::RED_REASON);

        $this->assertSame(2, $this->countRows('in_work'), self::RED_REASON);
        $this->assertSame(0, $this->isExclusive($svc->lastInsertedTaskId()), self::RED_REASON);
    }

    public function testPausedQueuedAndCompletedExclusiveRowsDoNotTakeTheSlot(): void
    {
        $svc = new ActiveTasksService();

        $this->assertSame(WriteOutcome::Applied, $svc->insertExclusiveTaskRow($this->row($this->gatherTaskId)), self::RED_REASON);
        foreach (['paused', 'queued', 'completed'] as $status) {
            $this->assertSame(
                WriteOutcome::Applied,
                $svc->insertExclusiveTaskRow($this->row($this->cookTaskId, $status)),
                self::RED_REASON . " ({$status})"
            );
            $this->assertSame(1, $this->isExclusive($svc->lastInsertedTaskId()), self::RED_REASON . " ({$status})");
        }

        $this->assertSame(WriteOutcome::Refused, $svc->insertExclusiveTaskRow($this->row($this->cookTaskId)), self::RED_REASON);
        $this->assertSame(1, $this->countRows('in_work'), self::RED_REASON);
    }

    public function testMoveToInWorkRefusesWhileSlotIsTakenWithoutBreakingCallerTransaction(): void
    {
        $svc = new ActiveTasksService();
        $svc->insertExclusiveTaskRow($this->row($this->gatherTaskId));
        $svc->insertExclusiveTaskRow($this->row($this->cookTaskId, 'paused'));
        $pausedId = $svc->lastInsertedTaskId();
        $this->assertGreaterThan(0, $pausedId, self::RED_REASON);

        $this->conn->transStart();
        $outcome = $svc->moveToInWork($pausedId, 'paused', ['end_time' => date('Y-m-d H:i:s', time() + 600)]);
        $this->assertTrue($this->conn->transStatus(), self::RED_REASON . ': transStatus spoiled');
        $this->conn->transComplete();

        $this->assertSame(WriteOutcome::Refused, $outcome, self::RED_REASON);
        $this->assertSame('paused', $this->taskStatus($pausedId), self::RED_REASON);
        $this->assertSame(WriteOutcome::Missing, $svc->moveToInWork($pausedId, 'queued'), self::RED_REASON);

        // Слот освободился — тот же перевод проходит.
        $this->conn->query("UPDATE character_tasks SET status = 'completed' WHERE task_id = ?", [$this->gatherTaskId]);
        $this->assertSame(WriteOutcome::Applied, $svc->moveToInWork($pausedId, 'paused'), self::RED_REASON);
        $this->assertSame('in_work', $this->taskStatus($pausedId), self::RED_REASON);
    }

    public function testMoveToInWorkRejectsStatusAndSlotInSet(): void
    {
        $svc = new ActiveTasksService();

        foreach (['status', 'exclusive_slot', 'bad-name'] as $key) {
            try {
                $svc->moveToInWork(1, 'paused', [$key => 'x']);
                $this->fail(self::RED_REASON . ": {$key} accepted in \$set");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testKillswitchOffWritesNonExclusiveRows(): void
    {
        $this->setLock(false);
        $svc = new ActiveTasksService();

        $this->assertSame(WriteOutcome::Applied, $svc->insertExclusiveTaskRow($this->row($this->gatherTaskId)), self::RED_REASON);
        $this->assertSame(0, $this->isExclusive($svc->lastInsertedTaskId()), self::RED_REASON);
        $this->assertSame(WriteOutcome::Applied, $svc->insertExclusiveTaskRow($this->row($this->cookTaskId)), self::RED_REASON);
        $this->assertSame(2, $this->countRows('in_work'), self::RED_REASON);
    }

    public function testSlotTakenTextNamesTheBlockerOrFallsBack(): void
    {
        $svc = new ActiveTasksService();
        $this->assertSame($svc->alreadyStartedExclusiveTaskText(), $svc->slotTakenText(self::CHAR), self::RED_REASON);

        $svc->insertExclusiveTaskRow($this->row($this->gatherTaskId));
        $text = $svc->slotTakenText(self::CHAR, 'Готовка: Грибная похлёбка');

        $this->assertStringContainsString('Сейчас занят: Добыча ресурсов', $text, self::RED_REASON);
        $this->assertStringContainsString('Хочешь начать: Готовка: Грибная похлёбка', $text, self::RED_REASON);
    }

    public function testModelAllowsFlagButNeverTheGeneratedSlot(): void
    {
        $fields = (new \ReflectionProperty(CharacterTaskModel::class, 'allowedFields'))->getValue(new CharacterTaskModel());
        $this->assertIsArray($fields);

        $this->assertContains('is_exclusive', $fields, self::RED_REASON);
        $this->assertNotContains('exclusive_slot', $fields, self::RED_REASON);
    }

    public function testMigrationBackfillsFlagInEveryStatus(): void
    {
        $m = $this->migration();
        $m->down();
        $this->conn->resetDataCache();
        $this->assertFalse($this->conn->fieldExists('is_exclusive', 'character_tasks'), self::RED_REASON . ': down() left is_exclusive');
        $this->assertFalse($this->conn->fieldExists('exclusive_slot', 'character_tasks'), self::RED_REASON . ': down() left exclusive_slot');

        foreach (['in_work', 'paused', 'queued', 'completed'] as $status) {
            $this->rawRow($this->cookTaskId, $status);
        }
        $this->rawRow($this->bandageTaskId, 'in_work');

        $m->up();

        $flags = $this->conn->query('SELECT t.parallel_execution_allowed AS p, ct.is_exclusive AS e FROM character_tasks ct JOIN tasks t ON t.id = ct.task_id')->getResultArray();
        $this->assertCount(5, $flags);
        foreach ($flags as $f) {
            $this->assertSame((int) $f['p'] === 0 ? 1 : 0, (int) $f['e'], self::RED_REASON . ': backfill');
        }
    }

    public function testMigrationRefusesExistingViolationsAndKeepsRows(): void
    {
        $m = $this->migration();
        $m->down();
        $this->conn->resetDataCache();
        $this->rawRow($this->gatherTaskId, 'in_work');
        $this->rawRow($this->cookTaskId, 'in_work');

        try {
            $m->up();
            $this->fail(self::RED_REASON . ': up() created the index over a violation');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('character_tasks', $e->getMessage());
            $this->assertStringContainsString((string) self::CHAR, $e->getMessage());
        }

        $this->assertSame(2, $this->countRows('in_work'), self::RED_REASON . ': rows touched');
    }

    private function migration(): Migration
    {
        $path = APPPATH . 'Database/Migrations/' . self::MIGRATION . '.php';
        if (! is_file($path)) {
            $this->fail(self::RED_REASON . ': migration ' . self::MIGRATION . ' is missing');
        }
        require_once $path;
        $class = 'App\\Database\\Migrations\\' . substr(self::MIGRATION, 18);
        $m     = new $class(Database::forge());
        $this->assertInstanceOf(Migration::class, $m);

        return $m;
    }

    private function setLock(bool $on): void
    {
        service('cache')->save(self::LOCK_CACHE_KEY, ['v' => $on, 't' => 'bool'], 60);
    }

    /** @return array<string, mixed> */
    private function row(int $taskId, string $status = 'in_work'): array
    {
        return [
            'character_id' => self::CHAR,
            'task_id'      => $taskId,
            'start_time'   => date('Y-m-d H:i:s'),
            'end_time'     => date('Y-m-d H:i:s', time() + 1800),
            'status'       => $status,
        ];
    }

    private function rawRow(int $taskId, string $status): void
    {
        $now = date('Y-m-d H:i:s');
        $this->conn->table('character_tasks')->insert([
            'character_id' => self::CHAR, 'task_id' => $taskId, 'start_time' => $now,
            'status' => $status, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function countRows(string $status): int
    {
        return $this->conn->table('character_tasks')
            ->where('character_id', self::CHAR)
            ->where('status', $status)
            ->countAllResults();
    }

    private function taskStatus(int $id): string
    {
        $row = $this->conn->table('character_tasks')->select('status')->where('id', $id)->get()->getRowArray();

        return is_array($row) && is_string($row['status'] ?? null) ? $row['status'] : '';
    }

    private function isExclusive(int $id): int
    {
        $row = $this->conn->table('character_tasks')->select('is_exclusive')->where('id', $id)->get()->getRowArray();

        return is_array($row) && is_numeric($row['is_exclusive'] ?? null) ? (int) $row['is_exclusive'] : -1;
    }

    private function dropTables(): void
    {
        foreach (['character_tasks', 'tasks'] as $t) {
            $this->conn->query("DROP TABLE IF EXISTS `{$t}`");
        }
        $this->conn->resetDataCache();
    }
}
