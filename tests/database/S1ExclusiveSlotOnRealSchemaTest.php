<?php

declare(strict_types=1);

namespace Tests\Database;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * s1-atomicity-09 — миграция 🔒-слота на настоящей схеме `character_tasks`: таблица строится
 * `CreateCharacterTasksTable` (FK `character_id` → `characters` ON DELETE/UPDATE CASCADE), а не
 * DDL-копией без ключей. STORED-колонка от столбца с CASCADE-ключом MySQL отвергает (1215), поэтому
 * миграция обязана пройти здесь; UNIQUE держит вторую 🔒 `in_work`, `paused` рядом допустима,
 * каскадное удаление персонажа работает.
 *
 * @internal
 */
final class S1ExclusiveSlotOnRealSchemaTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const MIGRATIONS = [
        '2024-03-20-153728_CreateTelegramUsersTable',
        '2024-03-20-154155_CreateCharactersTable',
        '2024-03-22-111828_CreateTasksTable',
        '2024-03-22-132411_CreateCharacterTasksTable',
        '2026-05-09-200000_AddQueuedStatusToCharacterTasks',
        '2026-05-10-190000_AddPausedStatusToCharacterTasks',
    ];

    private const S1 = '2026-12-25-100000_S1CharacterTasksExclusiveSlot';

    private const TABLES = ['telegram_users', 'characters', 'tasks', 'character_tasks'];

    private const CHAR = 1;
    private const TG   = 1;
    private const TASK_EXCLUSIVE = 1;
    private const TASK_PARALLEL  = 2;

    private BaseConnection $conn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = Database::connect();
        $this->dropTables();
        try {
            $forge = Database::forge();
            foreach (self::MIGRATIONS as $file) {
                $this->migration($file, $forge instanceof Forge ? $forge : null)->up();
            }
            $now = date('Y-m-d H:i:s');
            $this->conn->query(
                'INSERT INTO telegram_users (id, telegram_id, created_at, updated_at) VALUES (?, ?, ?, ?)',
                [self::TG, 777001, $now, $now]
            );
            $this->conn->query(
                'INSERT INTO characters (id, telegram_user_id, name, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
                [self::CHAR, self::TG, 'Тест', $now, $now]
            );
            foreach ([self::TASK_EXCLUSIVE => 0, self::TASK_PARALLEL => 1] as $id => $parallel) {
                $this->conn->query(
                    'INSERT INTO tasks (id, name, name_rus, description, type, difficulty_level, '
                    . 'parallel_execution_allowed, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$id, 'task' . $id, 'Задача ' . $id, '', 'test', 1, $parallel, $now, $now]
                );
            }
        } catch (\Throwable $e) {
            $this->dropTables();

            throw $e;
        }
    }

    protected function tearDown(): void
    {
        $this->dropTables();
        parent::tearDown();
    }

    public function testMigrationAppliesOnCascadeSchema(): void
    {
        $this->applyS1();

        $this->assertTrue($this->conn->fieldExists('exclusive_slot', 'character_tasks'));
        $this->assertTrue($this->conn->fieldExists('is_exclusive', 'character_tasks'));
    }

    /** F11: падение после ADD COLUMN `exclusive_slot` — повторный up() доводит миграцию, а не стопорится на дубле колонки. */
    public function testRerunAfterPartialApplyCompletes(): void
    {
        $this->applyS1();
        $this->conn->query('ALTER TABLE `character_tasks` DROP INDEX `uq_ct_exclusive_slot`');
        $this->conn->resetDataCache();

        $this->applyS1();
        $this->applyS1();

        $idx = $this->conn->query("SHOW INDEX FROM `character_tasks` WHERE Key_name = 'uq_ct_exclusive_slot'");
        $this->assertNotFalse($idx);
        $this->assertNotSame([], $idx->getResultArray(), 'UNIQUE восстановлен повторным up()');
    }

    /** Повтор после частичного запуска при новых дублях: отказ миграции, а не ошибка MySQL на снятии колонки. */
    public function testRerunWithDuplicatesRefusesWithTheIntendedMessage(): void
    {
        $this->applyS1();
        $this->conn->query('ALTER TABLE `character_tasks` DROP INDEX `uq_ct_exclusive_slot`');
        $this->insertRow(self::TASK_EXCLUSIVE, 'in_work', 1);
        $this->insertRow(self::TASK_EXCLUSIVE, 'in_work', 1);
        $this->conn->resetDataCache();

        try {
            $this->migration(self::S1, null)->up();
            $this->fail('миграция приняла дубли');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('разберите дубли', $e->getMessage());
        }
        $this->conn->resetDataCache();
        $this->assertFalse($this->conn->fieldExists('is_exclusive', 'character_tasks'), 'таблица возвращена к виду до миграции');
        $this->assertFalse($this->conn->fieldExists('exclusive_slot', 'character_tasks'));
    }

    public function testSecondExclusiveInWorkIsRefusedByUnique(): void
    {
        $this->applyS1();
        $this->insertRow(self::TASK_EXCLUSIVE, 'in_work', 1);

        $refused = false;
        try {
            $this->insertRow(self::TASK_EXCLUSIVE, 'in_work', 1);
        } catch (\Throwable $e) {
            $refused = str_contains($e->getMessage(), 'uq_ct_exclusive_slot') || $e->getCode() === 1062;
        }

        $this->assertTrue($refused, 'вторая 🔒 in_work того же персонажа должна упасть на UNIQUE');
        $this->assertSame(1, $this->countRows());
    }

    public function testPausedExclusiveAndParallelSitBesideExclusiveInWork(): void
    {
        $this->applyS1();
        $this->insertRow(self::TASK_EXCLUSIVE, 'in_work', 1);
        $this->insertRow(self::TASK_EXCLUSIVE, 'paused', 1);
        $this->insertRow(self::TASK_PARALLEL, 'in_work', 0);
        $this->insertRow(self::TASK_PARALLEL, 'in_work', 0);

        $this->assertSame(4, $this->countRows());
    }

    public function testDeletingCharacterCascadesToItsTasks(): void
    {
        $this->applyS1();
        $this->insertRow(self::TASK_EXCLUSIVE, 'in_work', 1);
        $this->insertRow(self::TASK_PARALLEL, 'in_work', 0);

        $this->conn->query('DELETE FROM characters WHERE id = ?', [self::CHAR]);

        $this->assertSame(0, $this->countRows());
    }

    private function applyS1(): void
    {
        try {
            $this->migration(self::S1, null)->up();
        } catch (\Throwable $e) {
            $this->fail($e->getCode() . ' ' . $e->getMessage());
        }
        // Миграция спрашивала fieldExists до ALTER: кэш списка полей устарел.
        $this->conn->resetDataCache();
    }

    private function insertRow(int $taskId, string $status, int $isExclusive): void
    {
        $now = date('Y-m-d H:i:s');
        $this->conn->query(
            'INSERT INTO character_tasks (character_id, telegram_user_id, task_id, start_time, status, '
            . 'is_exclusive, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [self::CHAR, self::TG, $taskId, $now, $status, $isExclusive, $now, $now]
        );
    }

    private function countRows(): int
    {
        $row = $this->conn->query('SELECT COUNT(*) AS c FROM character_tasks WHERE character_id = ?', [self::CHAR])
            ->getRowArray();

        return is_array($row) && is_numeric($row['c'] ?? null) ? (int) $row['c'] : -1;
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
