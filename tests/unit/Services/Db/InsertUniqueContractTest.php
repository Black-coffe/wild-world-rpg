<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Db;

use App\Database\Migrations\W9CreateCharacterAchievementsTable;
use App\Services\Db\ConditionalWriteService;
use App\Services\Db\WriteOutcome;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * exploit-fix-09 (ADR-181 §5) — `ConditionalWriteService::insertUnique()`: дубль ловится
 * централизованно, не в каждом вызывающем. При `DBDebug=true` он прилетает
 * `DatabaseException` (код MySQL в `getCode()`), при `DBDebug=false` — `query()`
 * возвращает `false`, а код лежит в `$db->error()`. Оба режима доказываются на РЕАЛЬНОЙ
 * схеме с `UNIQUE` (урок `feedback_test_schema_must_come_from_migration`): реюзаем
 * `character_achievements` (`W9CreateCharacterAchievementsTable`,
 * `UNIQUE(character_id, achievement_id)`) — таблицу с уникальным ключом, которая
 * существует уже сегодня, а не `character_tasks`/`quest_steps`: их `UNIQUE` появится
 * только в story 10, и поведение «до индекса» доказывает отдельный тест ниже на
 * временной таблице без единого ключа.
 *
 * `AchievementService` (владелец `character_achievements` в проде) этим файлом не
 * тронут — таблица здесь только источник реальной схемы для контракта, само
 * правило story 09 распространяется на два других пути (квест, эксклюзивная задача).
 *
 * @internal
 */
final class InsertUniqueContractTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const SCRATCH_TABLE = 'insert_unique_contract_scratch';

    private bool $createdAchievementsTable = false;
    private bool $createdScratchTable      = false;

    protected function setUp(): void
    {
        parent::setUp();

        $db = Database::connect('tests');
        if (! $db->tableExists('character_achievements')) {
            $this->requireMigrationClass();
            $forge = Database::forge('tests');
            (new W9CreateCharacterAchievementsTable($forge instanceof Forge ? $forge : null))->up();
            $this->createdAchievementsTable = true;
        }
    }

    protected function tearDown(): void
    {
        // DBDebug — value persists on the shared connection (DatabaseTestTrait docblock
        // warning) — всегда возвращаем к дефолту, даже если тест упал до restore.
        $this->enableDBDebug();
        $this->resetSessionSqlModeToProjectDefault();

        $db = Database::connect('tests');

        if ($this->createdScratchTable) {
            $db->query('DROP TABLE IF EXISTS ' . self::SCRATCH_TABLE);
            $this->createdScratchTable = false;
        }

        if ($this->createdAchievementsTable) {
            $this->requireMigrationClass();
            $forge = Database::forge('tests');
            (new W9CreateCharacterAchievementsTable($forge instanceof Forge ? $forge : null))->down();
        } else {
            $db->table('character_achievements')->truncate();
        }

        parent::tearDown();
    }

    /**
     * exploit-fix-27: `sql_mode` — сессионное значение, персистирует на общем
     * `tests`-соединении между тестами (тот же урок, что и `DBDebug` в `tearDown()`
     * выше). Возвращает соединение к проектному дефолту ровно той же формой, которой
     * CI4 снимает `STRICT_TRANS_TABLES`/`STRICT_ALL_TABLES` при `strictOn = false`
     * на коннекте ({@see \CodeIgniter\Database\MySQLi\Connection::connect()}).
     */
    private function resetSessionSqlModeToProjectDefault(): void
    {
        Database::connect('tests')->query(
            "SET SESSION sql_mode = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                                @@sql_mode,
                                'STRICT_ALL_TABLES,', ''),
                            ',STRICT_ALL_TABLES', ''),
                        'STRICT_ALL_TABLES', ''),
                    'STRICT_TRANS_TABLES,', ''),
                ',STRICT_TRANS_TABLES', ''),
            'STRICT_TRANS_TABLES', '')"
        );
    }

    private function requireMigrationClass(): void
    {
        if (! class_exists(W9CreateCharacterAchievementsTable::class, false)) {
            require_once APPPATH . 'Database/Migrations/2026-06-03-110000_W9CreateCharacterAchievementsTable.php';
        }
    }

    /** @return array<string,mixed> */
    private function achievementRow(int $characterId, int $achievementId = 7): array
    {
        return [
            'character_id'   => $characterId,
            'achievement_id' => $achievementId,
            'unlocked_at'    => date('Y-m-d H:i:s'),
        ];
    }

    private function achievementRowCount(int $characterId, int $achievementId): int
    {
        return (int) Database::connect('tests')->table('character_achievements')
            ->where(['character_id' => $characterId, 'achievement_id' => $achievementId])
            ->countAllResults();
    }

    // ── DBDebug = true: дубль прилетает исключением, insertUnique() гасит его в Refused ──

    public function testInsertUniqueAppliesFirstInsertUnderDbDebugTrue(): void
    {
        $this->enableDBDebug();

        $outcome = (new ConditionalWriteService(Database::connect('tests')))
            ->insertUnique('character_achievements', $this->achievementRow(101));

        $this->assertSame(WriteOutcome::Applied, $outcome);
        $this->assertSame(1, $this->achievementRowCount(101, 7));
    }

    public function testInsertUniqueRefusesDuplicateUnderDbDebugTrueWithoutThrowing(): void
    {
        $this->enableDBDebug();
        $service = new ConditionalWriteService(Database::connect('tests'));

        $first  = $service->insertUnique('character_achievements', $this->achievementRow(102));
        $second = $service->insertUnique('character_achievements', $this->achievementRow(102));

        $this->assertSame(WriteOutcome::Applied, $first);
        $this->assertSame(
            WriteOutcome::Refused,
            $second,
            'дубль под DBDebug=true обязан гаситься в Refused до того, как исключение уйдёт наружу'
        );
        $this->assertSame(1, $this->achievementRowCount(102, 7), 'вторая попытка не должна была создать вторую строку');
    }

    // ── DBDebug = false: query() возвращает false, код ошибки — в $db->error() ──

    public function testInsertUniqueAppliesFirstInsertUnderDbDebugFalse(): void
    {
        $this->disableDBDebug();

        $outcome = (new ConditionalWriteService(Database::connect('tests')))
            ->insertUnique('character_achievements', $this->achievementRow(103));

        $this->assertSame(WriteOutcome::Applied, $outcome);
        $this->assertSame(1, $this->achievementRowCount(103, 7));
    }

    public function testInsertUniqueRefusesDuplicateUnderDbDebugFalseWithoutThrowing(): void
    {
        $this->disableDBDebug();
        $service = new ConditionalWriteService(Database::connect('tests'));

        $first  = $service->insertUnique('character_achievements', $this->achievementRow(104));
        $second = $service->insertUnique('character_achievements', $this->achievementRow(104));

        $this->assertSame(WriteOutcome::Applied, $first);
        $this->assertSame(
            WriteOutcome::Refused,
            $second,
            'дубль под DBDebug=false обязан гаситься в Refused, а не оставаться необработанным false'
        );
        $this->assertSame(1, $this->achievementRowCount(104, 7));
    }

    // ── до появления UNIQUE на character_tasks/quest_steps (story 10) ──

    /**
     * Acceptance 🔴: сегодня у `character_tasks`/`quest_steps` ещё нет уникального
     * ключа — `insertUnique()` обязан не ломать нынешний путь, а просто вставлять.
     * Симулируем это на временной таблице без единого индекса (кроме PRIMARY):
     * "дублирующая" по бизнес-полям строка вставляется второй раз свободно, ровно
     * как сейчас ведут себя реальные `character_tasks`/`quest_steps`.
     */
    public function testInsertUniqueJustInsertsBeforeAnyUniqueIndexExists(): void
    {
        $db = Database::connect('tests');
        $db->query(
            'CREATE TABLE ' . self::SCRATCH_TABLE . ' ('
            . 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,'
            . 'character_id INT UNSIGNED NOT NULL,'
            . 'task_id INT UNSIGNED NOT NULL'
            . ')'
        );
        $this->createdScratchTable = true;

        $service = new ConditionalWriteService($db);
        $row     = ['character_id' => 5, 'task_id' => 9];

        $first  = $service->insertUnique(self::SCRATCH_TABLE, $row);
        $second = $service->insertUnique(self::SCRATCH_TABLE, $row);

        $this->assertSame(WriteOutcome::Applied, $first);
        $this->assertSame(
            WriteOutcome::Applied,
            $second,
            'без UNIQUE на схеме сегодняшний путь (до story 10) не должен ломаться — вставка проходит дважды'
        );
        $this->assertSame(
            2,
            (int) $db->table(self::SCRATCH_TABLE)->where($row)->countAllResults(),
            'обе строки обязаны физически лежать в таблице — сегодня индекс их не различает'
        );
    }

    // ── exploit-fix-14 / exploit-fix-27 — нарушение NOT NULL внутри insertUnique() ──

    /**
     * Acceptance 🔴 (story 14, story 27 — R2-major №3): нарушение NOT NULL внутри
     * `insertUnique()` обязано дать исход, отличный от `Refused` — недостающая
     * обязательная колонка не то же самое, что дубль. До story 27 этот тест проверял
     * только «не Refused» и называл себя «единственно возможным исходом на этом
     * DB-конфиге» — но `sql_mode` СЕССИОННЫЙ, а не жёстко зашитый в конфиг
     * соединения: любой вызывающий, поднявший `STRICT_TRANS_TABLES` на своей сессии,
     * получит настоящее исключение. Театром было утверждение «исключения физически
     * не будет» — оно верно только для дефолтной сессии проекта, не для примитива
     * вообще. Этот тест теперь доказывает именно дефолт: `strictOn = false`
     * (`app/Config/Database.php:42,67`) снимает `STRICT_TRANS_TABLES`/
     * `STRICT_ALL_TABLES` из `sql_mode` на коннекте
     * ({@see \CodeIgniter\Database\MySQLi\Connection::connect()}), даже когда
     * глобальный `sql_mode` сервера строгий (замер 03.09.2026 подтвердил строгий
     * режим на уровне сервера и для `wildworld_tests`, и для прода) — MySQL молча
     * подставляет implicit default (`0` у `INT`), запрос проходит, `insertUnique()`
     * отдаёт `Applied`. Сосед ниже доказывает противоположную сессию явно.
     */
    public function testInsertUniqueOnMissingNotNullColumnAppliesWithImplicitDefaultWithoutSessionStrictMode(): void
    {
        $db = Database::connect('tests');
        $this->resetSessionSqlModeToProjectDefault();
        $db->query(
            'CREATE TABLE ' . self::SCRATCH_TABLE . ' ('
            . 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,'
            . 'character_id INT UNSIGNED NOT NULL,'
            . 'must_have_task_id INT UNSIGNED NOT NULL'
            . ')'
        );
        $this->createdScratchTable = true;

        $this->enableDBDebug();
        $outcome = (new ConditionalWriteService($db))->insertUnique(self::SCRATCH_TABLE, [
            'character_id' => 5,
            // `must_have_task_id` намеренно не передан — обязательная колонка без DEFAULT.
        ]);

        $this->assertSame(
            WriteOutcome::Applied,
            $outcome,
            'без STRICT_TRANS_TABLES на сессии implicit default проходит и метод отдаёт Applied, не Refused'
        );
    }

    /**
     * Acceptance 🔴 (story 27 — контр-пример к соседу выше): та же схема, та же
     * пропавшая `NOT NULL`-колонка, но сессия ЯВНО поднимает `STRICT_TRANS_TABLES`
     * (форма — точная противоположность тому, как CI4 её снимает при
     * `strictOn = false`). На этой сессии implicit default недоступен — MySQL
     * бросает исключение, `query()` его не гасит (глотать можно только дубль по
     * `ON DUPLICATE KEY UPDATE`, см. докблок метода), и `insertUnique()` пробрасывает
     * `DatabaseException` наружу как есть.
     */
    public function testInsertUniqueOnMissingNotNullColumnThrowsUnderSessionStrictMode(): void
    {
        $db = Database::connect('tests');
        $db->query(
            'CREATE TABLE ' . self::SCRATCH_TABLE . ' ('
            . 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,'
            . 'character_id INT UNSIGNED NOT NULL,'
            . 'must_have_task_id INT UNSIGNED NOT NULL'
            . ')'
        );
        $this->createdScratchTable = true;

        $db->query("SET SESSION sql_mode = CONCAT(@@sql_mode, ',STRICT_TRANS_TABLES')");

        $this->enableDBDebug();
        $this->expectException(DatabaseException::class);

        try {
            (new ConditionalWriteService($db))->insertUnique(self::SCRATCH_TABLE, [
                'character_id' => 5,
                // `must_have_task_id` намеренно не передан — обязательная колонка без DEFAULT.
            ]);
        } finally {
            // tearDown() тоже снимает STRICT_TRANS_TABLES, но explicitly здесь — на случай,
            // если ожидаемое исключение по какой-то причине не долетит до PHPUnit.
            $this->resetSessionSqlModeToProjectDefault();
        }
    }
}
