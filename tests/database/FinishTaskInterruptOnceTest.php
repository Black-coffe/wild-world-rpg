<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Controllers\Telegram\Commands\Actions\FinishTaskAction;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Telegram;

/**
 * F21 (s2-conditional-writes-09) — прерывание задачи применяет штраф только при успешном
 * переходе `in_work → interrupted`.
 *
 * Двойной тап: оба тапа прошли гейт-чтение задачи в `in_work`. Настоящий `FinishTaskAction::handle()`
 * читает задачу; сразу после этого чтения отдельное соединение делает то, что закоммитил соседний
 * тап: `in_work → interrupted` и один штраф. Продолжив, вызов обязан получить отказ без второго
 * штрафа. Затем ещё один последовательный тап — тоже отказ, статы не меняются. (Соседний тап не
 * зовётся вложенно на том же соединении: CI4 берёт результат запроса из `resultID` соединения ПОСЛЕ
 * события `DBQuery`, и вложенные запросы подменили бы гейт-чтение.)
 *
 * `tired` не сверяется: `CreateCharactersTable` объявляет его `DECIMAL(7)` без дробной части, штраф
 * 0,2 там округляется в ноль.
 *
 * Схема — исполнением настоящих классов миграций (`character_tasks` с NOT NULL и ENUM статуса,
 * включая `queued`/`paused`); `task_settings` — рукописно (нет ни в одной миграции, дрейф прода).
 *
 * @internal
 */
final class FinishTaskInterruptOnceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const RED_REASON = 'interrupt penalty applied twice, or robot award lost a concurrent increment';

    private const MIGRATIONS = [
        '2024-03-17-222643_CreateBiomesTable',
        '2024-03-18-105708_CreateMapTable',
        '2024-03-20-153728_CreateTelegramUsersTable',
        '2024-03-20-154155_CreateCharactersTable',
        '2024-04-09-132757_AddBiomeIdToCharacters',
        '2026-05-11-150000_AddLastRespawnAtToCharacters',
        '2024-03-18-134951_CreateActionLogTable',
        '2024-03-22-111828_CreateTasksTable',
        '2024-03-22-132411_CreateCharacterTasksTable',
        '2026-05-09-200000_AddQueuedStatusToCharacterTasks',
        '2026-05-10-190000_AddPausedStatusToCharacterTasks',
    ];

    private const TABLES = [
        'biomes', 'map', 'telegram_users', 'characters', 'action_log', 'tasks', 'character_tasks',
    ];

    private const STATS = ['experience', 'health', 'strength', 'agility', 'intellect'];

    /** Легаси-штраф при factor 1.0 (ветеран) — FinishTaskAction. */
    private const PENALTY = [
        'experience' => 0.5, 'health' => 1.0, 'strength' => 0.25,
        'agility' => 0.5, 'intellect' => 0.5, 'tired' => 0.2,
    ];

    private BaseConnection $conn;

    /** @var (callable(object): void)|null */
    private $listener = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! defined('PHPUNIT_TESTSUITE')) {
            define('PHPUNIT_TESTSUITE', true);
        }
        new Telegram('123456:TEST-fake-token-for-tests', 'test_bot');

        $this->conn = Database::connect();
        $this->dropTables();
        try {
            $forge = Database::forge();
            foreach (self::MIGRATIONS as $file) {
                $this->migration($file, $forge instanceof Forge ? $forge : null)->up();
            }
            $this->conn->query('ALTER TABLE character_tasks ADD COLUMN task_settings TEXT NULL');
        } catch (\Throwable $e) {
            $this->dropTables();
            throw $e;
        }
        $this->conn->resetDataCache();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->listener !== null) {
                Events::removeListener('DBQuery', $this->listener);
                $this->listener = null;
            }
            $this->dropTables();
        } finally {
            parent::tearDown();
        }
    }

    public function testDoubleTapAppliesPenaltyOnce(): void
    {
        $now   = date('Y-m-d H:i:s');
        $tgId  = random_int(760_000_000, 769_999_999);
        $this->conn->table('telegram_users')->insert([
            'telegram_id' => $tgId, 'username' => 'tap', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $tgUid = (int) $this->conn->insertID();
        $this->conn->table('characters')->insert([
            'telegram_user_id' => $tgUid, 'name' => 'Tapper', 'level' => 99, 'experience' => 100,
            'health' => 50, 'tired' => 50, 'strength' => 10, 'agility' => 10, 'intellect' => 10, 'gold' => 0,
            'cell_number' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $charId = (int) $this->conn->insertID();
        $this->conn->table('tasks')->insert([
            'name' => 'gathering', 'name_rus' => 'Сбор', 'description' => '', 'min_duration' => 1, 'max_duration' => 1,
            'type' => 'test', 'difficulty_level' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $taskId = (int) $this->conn->insertID();
        $this->conn->table('character_tasks')->insert([
            'character_id' => $charId, 'telegram_user_id' => $tgUid, 'task_id' => $taskId,
            'start_time' => $now, 'end_time' => date('Y-m-d H:i:s', time() + 3600), 'status' => 'in_work',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $ctId = (int) $this->conn->insertID();

        $before = $this->stats($charId);
        $data   = 'finishAllTasks_' . $ctId;

        // Соседний тап закоммитил прерывание и свой штраф сразу после гейт-чтения этого вызова.
        $fired = false;
        $this->listener = function (object $query) use (&$fired, $ctId, $charId): void {
            if ($fired) {
                return;
            }
            $sql = ltrim((string) $query->getQuery());
            if (! str_starts_with($sql, 'SELECT') || ! str_contains($sql, 'character_tasks')) {
                return;
            }
            $fired = true;
            $other = Database::connect('tests', false);
            $other->query("UPDATE character_tasks SET status = 'interrupted' WHERE id = ? AND status = 'in_work'", [$ctId]);
            $other->query(
                'UPDATE characters SET experience = experience - ?, health = health - ?, strength = strength - ?,'
                . ' agility = agility - ?, intellect = intellect - ? WHERE id = ?',
                [self::PENALTY['experience'], self::PENALTY['health'], self::PENALTY['strength'],
                    self::PENALTY['agility'], self::PENALTY['intellect'], $charId]
            );
        };
        Events::on('DBQuery', $this->listener);

        (new FinishTaskAction($this->callbackQuery($tgId, $data)))->handle();

        Events::removeListener('DBQuery', $this->listener);
        $this->listener = null;

        $this->assertTrue($fired, 'предусловие: соседний тап вклинился после гейт-чтения');
        $this->assertSame('interrupted', $this->taskStatus($ctId));

        $after = $this->stats($charId);
        foreach (self::STATS as $f) {
            $this->assertEqualsWithDelta($before[$f] - self::PENALTY[$f], $after[$f], 0.001, self::RED_REASON . " ({$f})");
        }

        // Ещё один тап по уже прерванной задаче — отказ, статы не меняются.
        (new FinishTaskAction($this->callbackQuery($tgId, $data)))->handle();
        $again = $this->stats($charId);
        foreach (self::STATS as $f) {
            $this->assertEqualsWithDelta($after[$f], $again[$f], 0.001, self::RED_REASON . " ({$f}, repeat)");
        }
        $this->assertSame('interrupted', $this->taskStatus($ctId));
    }

    private function migration(string $file, ?Forge $forge): Migration
    {
        require_once APPPATH . 'Database/Migrations/' . $file . '.php';
        $class = 'App\\Database\\Migrations\\' . substr($file, 18);
        $m = new $class($forge);
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

    /** @return array<string, float> */
    private function stats(int $charId): array
    {
        $row = $this->conn->table('characters')->where('id', $charId)->get()->getRowArray();
        $this->assertIsArray($row);
        $out = [];
        foreach (self::STATS as $f) {
            $out[$f] = is_numeric($row[$f] ?? null) ? (float) $row[$f] : -1.0;
        }

        return $out;
    }

    private function taskStatus(int $ctId): string
    {
        $row = $this->conn->table('character_tasks')->where('id', $ctId)->get()->getRowArray();
        $this->assertIsArray($row);

        return (string) $row['status'];
    }

    private function callbackQuery(int $tgId, string $data): CallbackQuery
    {
        return new CallbackQuery([
            'id'            => 'cbq_' . random_int(1, 999999999),
            'from'          => ['id' => $tgId, 'is_bot' => false, 'first_name' => 'Тест'],
            'message'       => [
                'message_id' => 1, 'date' => time(),
                'chat'       => ['id' => $tgId, 'type' => 'private'],
                'text'       => 'placeholder',
            ],
            'chat_instance' => 'ci_' . $tgId,
            'data'          => $data,
        ]);
    }
}
