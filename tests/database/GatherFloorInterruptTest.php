<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Controllers\Telegram\Commands\Actions\CancelGatherAction;
use App\Services\Events\GatherFloorInterrupt;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Request as LongmanRequest;
use Longman\TelegramBot\Telegram;

/**
 * ADR-200 (sudden-death-1125 S2) — событие довело добытчика до пассивного пола: добыча прерывается, игрок
 * получает долю добычи по прошедшему времени (один обычный бросок × прошло/всего, вниз, без гарантированного
 * минимума) и одно сообщение «иди на базу», где названо добытое. Платит только тот вызов, что выиграл переход
 * `in_work → interrupted`: гонка с Worker (его захват `in_work → completed`) и повторное прерывание платят один раз.
 * Ручная отмена по-прежнему не даёт ничего.
 *
 * Бросок, запись добычи, имена ресурсов и отправка — швы (их собственная логика покрыта своими тестами);
 * переход статуса, доля и выплата — настоящие, на своей схеме.
 *
 * @internal
 */
final class GatherFloorInterruptTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = ['characters', 'tasks', 'character_tasks', 'telegram_users', 'character_resources'];

    private const TG_USER_ID = 7;
    private const CHAT_ID    = 555000111;

    /** @var \CodeIgniter\Database\BaseConnection<object, object> */
    private $conn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->conn = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $this->conn->query("DROP TABLE IF EXISTS {$t}");
        }
        $this->conn->query('CREATE TABLE characters (
            id INT AUTO_INCREMENT PRIMARY KEY, telegram_user_id INT NOT NULL DEFAULT 0, name VARCHAR(255) NULL,
            cell_number INT NOT NULL DEFAULT 1, biome_id INT NOT NULL DEFAULT 1, health DECIMAL(10,2) NOT NULL DEFAULT 100,
            tired DECIMAL(10,2) NOT NULL DEFAULT 100, level INT NOT NULL DEFAULT 1, experience DOUBLE NOT NULL DEFAULT 0,
            created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->conn->query('CREATE TABLE tasks (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, name_rus VARCHAR(255) NULL, parallel_execution_allowed TINYINT NOT NULL DEFAULT 1)');
        // s1-atomicity (C9): колонки и UNIQUE слота 🔒 — из миграции S1CharacterTasksExclusiveSlot.
        $this->conn->query("CREATE TABLE character_tasks (
            id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NOT NULL, telegram_user_id INT NOT NULL, task_id INT NOT NULL,
            start_time DATETIME NULL, end_time DATETIME NULL, status VARCHAR(50) NOT NULL, task_settings TEXT NULL,
            created_at DATETIME NULL, updated_at DATETIME NULL,
            `is_exclusive` TINYINT(1) NOT NULL DEFAULT 0,
            `exclusive_slot` INT UNSIGNED GENERATED ALWAYS AS (IF(`status` = 'in_work' AND `is_exclusive` = 1, `character_id`, NULL)) STORED,
            UNIQUE KEY `uq_ct_exclusive_slot` (`exclusive_slot`))");
        $this->conn->query('CREATE TABLE telegram_users (id INT AUTO_INCREMENT PRIMARY KEY, telegram_id BIGINT NOT NULL, language_code VARCHAR(8) NULL)');
        $this->conn->query('CREATE TABLE character_resources (
            id INT AUTO_INCREMENT PRIMARY KEY, id_characters INT NOT NULL, id_resources INT NOT NULL, quantity INT NOT NULL DEFAULT 0,
            created_at DATETIME NULL, updated_at DATETIME NULL, UNIQUE KEY uq_char_res (id_characters, id_resources))');

        $this->conn->query("INSERT INTO tasks (id, name, name_rus, parallel_execution_allowed) VALUES (1, 'Gather', 'Сбор ресурсов', 0), (2, 'Explore', 'Разведка', 0)");
        $this->conn->query('INSERT INTO telegram_users (id, telegram_id) VALUES (?, ?)', [self::TG_USER_ID, self::CHAT_ID]);
    }

    protected function tearDown(): void
    {
        foreach (self::TABLES as $t) {
            $this->conn->query("DROP TABLE IF EXISTS {$t}");
        }
        $this->conn->resetDataCache();
        parent::tearDown();
    }

    public function testGatherAtFloorIsInterruptedWithItsShareOfOneRoll(): void
    {
        $now    = time();
        $charId = $this->character(1.0);
        $taskId = $this->gather($charId, $now - 30 * 60, $now + 30 * 60); // половина времени прошла

        $spy = new InterruptSpy($now, [
            ['resource_id' => 11, 'amount' => 9, 'type' => 'wood'],
            ['resource_id' => 12, 'amount' => 4, 'type' => 'stone'],
            ['resource_id' => 13, 'amount' => 1, 'type' => 'ore'],
        ]);

        $this->assertTrue($spy->interrupt($this->row($charId), 'Подземные толчки', 1.0), 'the gather at the floor is interrupted');

        $this->assertSame('interrupted', $this->taskStatus($taskId));
        $this->assertSame([[11, 4], [12, 2]], $spy->persistedPairs(), 'half the time: floor(half) of the same roll, zeros dropped');
        $this->assertSame([3], $spy->wearBlocks, 'tools wear only for the 30 elapsed minutes (3 blocks)');
        $this->assertCount(1, $spy->sent, 'one message');
        $text = $spy->sent[0]['text'];
        $this->assertStringContainsString('Подземные толчки', $text, 'names the event');
        $this->assertStringContainsString('Дерево × 4', $text, 'names what was gathered');
        $this->assertStringContainsString('Камень × 2', $text);
        $this->assertStringContainsString('на базу', $text, 'tells to go to base');
        $this->assertSame(self::CHAT_ID, $spy->sent[0]['chat_id']);
    }

    public function testZeroElapsedGivesNothingAndSaysSo(): void
    {
        $now    = time();
        $charId = $this->character(1.0);
        $taskId = $this->gather($charId, $now, $now + 60 * 60);

        $spy = new InterruptSpy($now, [['resource_id' => 11, 'amount' => 9, 'type' => 'wood']]);

        $this->assertTrue($spy->interrupt($this->row($charId), 'Ураган', 1.0));
        $this->assertSame('interrupted', $this->taskStatus($taskId));
        $this->assertSame([], $spy->persistedPairs(), '0 elapsed gives 0, no guaranteed minimum');
        $this->assertCount(1, $spy->sent);
        $this->assertStringContainsString('ничего', $spy->sent[0]['text']);
    }

    public function testWorkerCompletedFirstPaysOnce(): void
    {
        $now    = time();
        $charId = $this->character(1.0);
        $taskId = $this->gather($charId, $now - 60 * 60, $now);
        $this->assertSame(1, $this->workerClaim($taskId), 'Worker wins the race');

        $spy = new InterruptSpy($now, [['resource_id' => 11, 'amount' => 9, 'type' => 'wood']]);

        $this->assertFalse($spy->interrupt($this->row($charId), 'Ураган', 1.0), 'the Worker already paid');
        $this->assertSame('completed', $this->taskStatus($taskId));
        $this->assertSame([], $spy->persisted, 'no second payment');
        $this->assertSame([], $spy->sent);
    }

    public function testInterruptFirstThenWorkerClaimIsRefused(): void
    {
        $now    = time();
        $charId = $this->character(1.0);
        $taskId = $this->gather($charId, $now - 60 * 60, $now - 1);

        $spy = new InterruptSpy($now, [['resource_id' => 11, 'amount' => 8, 'type' => 'wood']]);

        $this->assertTrue($spy->interrupt($this->row($charId), 'Ураган', 1.0));
        $this->assertSame([[11, 8]], $spy->persistedPairs(), 'elapsed ≥ total: never more than one full roll');
        $this->assertSame(0, $this->workerClaim($taskId), 'Worker finds nothing to complete: paid once');
    }

    public function testSecondInterruptIsANoOp(): void
    {
        $now    = time();
        $charId = $this->character(1.0);
        $this->gather($charId, $now - 20 * 60, $now + 20 * 60);

        $spy = new InterruptSpy($now, [['resource_id' => 11, 'amount' => 10, 'type' => 'wood']]);

        $this->assertTrue($spy->interrupt($this->row($charId), 'Ураган', 1.0));
        $this->assertFalse($spy->interrupt($this->row($charId), 'Ураган', 1.0), 'second attempt does nothing');
        $this->assertCount(1, $spy->persisted);
        $this->assertCount(1, $spy->sent);
    }

    public function testOtherTasksAreKept(): void
    {
        $now    = time();
        $charId = $this->character(1.0);
        $this->conn->query("INSERT INTO character_tasks (character_id, telegram_user_id, task_id, start_time, end_time, status) VALUES (?, ?, 2, ?, ?, 'in_work')",
            [$charId, self::TG_USER_ID, date('Y-m-d H:i:s', $now - 600), date('Y-m-d H:i:s', $now + 600)]);
        $exploreId = (int) $this->conn->insertID();

        $spy = new InterruptSpy($now, []);

        $this->assertFalse($spy->interrupt($this->row($charId), 'Ураган', 1.0), 'no gather — nothing to interrupt');
        $this->assertSame('in_work', $this->taskStatus($exploreId));
        $this->assertSame([], $spy->sent);
    }

    public function testDecisionNeedsGatheringAHitAndTheFloor(): void
    {
        $this->assertTrue(GatherFloorInterrupt::shouldInterrupt(true, -5.0, 1.0, 1.0), 'hit to the floor while gathering');
        $this->assertTrue(GatherFloorInterrupt::shouldInterrupt(true, -3.0, 0.5, 1.0), 'already below the floor and hit again');
        $this->assertFalse(GatherFloorInterrupt::shouldInterrupt(false, -5.0, 1.0, 1.0), 'not gathering keeps going');
        $this->assertFalse(GatherFloorInterrupt::shouldInterrupt(true, -5.0, 6.0, 1.0), 'above the floor keeps its task');
        $this->assertFalse(GatherFloorInterrupt::shouldInterrupt(true, 0.0, 1.0, 1.0), 'no health damage — no interrupt');
        $this->assertFalse(GatherFloorInterrupt::shouldInterrupt(true, 4.0, 1.0, 1.0), 'healing — no interrupt');
        $this->assertFalse(GatherFloorInterrupt::shouldInterrupt(true, null, 1.0, 1.0));
    }

    public function testShareNeverExceedsTheSameRollScaled(): void
    {
        $roll = [['resource_id' => 1, 'amount' => 7, 'type' => 'x'], ['resource_id' => 2, 'amount' => 13, 'type' => 'y']];
        foreach ([0.0, 0.1, 0.3, 0.5, 0.77, 0.99, 1.0] as $share) {
            $byId = array_column($roll, 'amount', 'resource_id');
            foreach (GatherFloorInterrupt::scaleLoot($roll, $share) as $line) {
                $this->assertLessThanOrEqual((int) floor($byId[$line['resource_id']] * $share), $line['amount'], "share {$share}");
                $this->assertGreaterThan(0, $line['amount'], 'zero lines are dropped');
            }
        }
        $this->assertSame([], GatherFloorInterrupt::scaleLoot($roll, 0.0));
        $this->assertSame(0.5, GatherFloorInterrupt::shareOf('2026-11-25 10:00:00', '2026-11-25 11:00:00', strtotime('2026-11-25 10:30:00')));
        $this->assertSame(1.0, GatherFloorInterrupt::shareOf('2026-11-25 10:00:00', '2026-11-25 11:00:00', strtotime('2026-11-25 12:00:00')));
        $this->assertSame(0.0, GatherFloorInterrupt::shareOf('2026-11-25 10:00:00', '2026-11-25 11:00:00', strtotime('2026-11-25 09:00:00')));
    }

    public function testManualCancelStillPaysNothing(): void
    {
        $now    = time();
        $charId = $this->character(50.0);
        $taskId = $this->gather($charId, $now - 30 * 60, $now + 30 * 60);

        LongmanRequest::initialize(new Telegram('123456:TEST-token', 'testbot'));
        $cb = new CallbackQuery([
            'id'            => 'cb-1',
            'from'          => ['id' => self::CHAT_ID, 'is_bot' => false, 'first_name' => 'T'],
            'message'       => ['message_id' => 1, 'date' => $now, 'chat' => ['id' => self::CHAT_ID, 'type' => 'private']],
            'chat_instance' => 'ci',
            'data'          => 'cancelGather',
        ]);
        try {
            (new CancelGatherAction($cb))->handle();
        } catch (\Throwable) {
            // Отправка фото без поднятого сайта падает на encodeFile — уже после отмены; проверяем итог в БД.
        }

        $this->assertSame('interrupted', $this->taskStatus($taskId));
        $count = $this->conn->table('character_resources')->where('id_characters', $charId)->countAllResults();
        $this->assertSame(0, $count, 'manual cancel gives no loot, as before');
    }

    private function character(float $health): int
    {
        $this->conn->query('INSERT INTO characters (telegram_user_id, name, health, level) VALUES (?, ?, ?, 5)', [self::TG_USER_ID, 'Тест', $health]);

        return (int) $this->conn->insertID();
    }

    private function gather(int $charId, int $start, int $end): int
    {
        $this->conn->query("INSERT INTO character_tasks (character_id, telegram_user_id, task_id, start_time, end_time, status, is_exclusive) VALUES (?, ?, 1, ?, ?, 'in_work', 1)",
            [$charId, self::TG_USER_ID, date('Y-m-d H:i:s', $start), date('Y-m-d H:i:s', $end)]);

        return (int) $this->conn->insertID();
    }

    /** Тот же атомарный захват, что у `Worker::handleTask()`. */
    private function workerClaim(int $taskId): int
    {
        $this->conn->table('character_tasks')->where('id', $taskId)->where('status', 'in_work')
            ->update(['status' => 'completed', 'updated_at' => date('Y-m-d H:i:s')]);

        return $this->conn->affectedRows();
    }

    private function taskStatus(int $taskId): string
    {
        $row = $this->conn->table('character_tasks')->select('status')->where('id', $taskId)->get()->getRowArray();

        return is_array($row) && is_string($row['status'] ?? null) ? $row['status'] : '';
    }

    /** @return array<string, mixed> */
    private function row(int $charId): array
    {
        $row = $this->conn->table('characters')->where('id', $charId)->get()->getRowArray();

        return is_array($row) ? $row : [];
    }
}

final class InterruptSpy extends GatherFloorInterrupt
{
    /** @var list<list<array<string, mixed>>> */
    public array $persisted = [];

    /** @var list<int> */
    public array $wearBlocks = [];

    /** @var list<array{chat_id: int, text: string}> */
    public array $sent = [];

    /**
     * @param list<array{resource_id: int, amount: int, type: string}> $roll
     */
    public function __construct(private int $fixedNow, private array $roll)
    {
        parent::__construct();
    }

    protected function now(): int
    {
        return $this->fixedNow;
    }

    protected function rollFullGather(array $task, array|\App\Entities\CharacterEntity $character, int $wearBlocks): array
    {
        $this->wearBlocks[] = $wearBlocks;

        return $this->roll;
    }

    protected function persistLoot(array $found, array|\App\Entities\CharacterEntity $character, array $task): ?string
    {
        $this->persisted[] = $found;

        return null;
    }

    protected function resourceNames(array $ids): array
    {
        return [11 => 'Дерево', 12 => 'Камень', 13 => 'Руда'];
    }

    protected function send(int $chatId, string $text, array $keyboard): void
    {
        $this->sent[] = ['chat_id' => $chatId, 'text' => $text];
    }

    protected function promoteCraftQueue(int $characterId): array
    {
        return [];
    }

    /** @return list<array{int, int}> */
    public function persistedPairs(): array
    {
        $out = [];
        foreach ($this->persisted as $found) {
            foreach ($found as $line) {
                $out[] = [(int) $line['resource_id'], (int) $line['amount']];
            }
        }

        return $out;
    }
}
