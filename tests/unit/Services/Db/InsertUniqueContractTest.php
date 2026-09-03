<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Db;

use App\Database\Migrations\W9CreateCharacterAchievementsTable;
use App\Services\Db\ConditionalWriteService;
use App\Services\Db\WriteOutcome;
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

    // ── exploit-fix-14 — нарушение NOT NULL внутри insertUnique() ──

    /**
     * Acceptance 🔴 (story 14): нарушение NOT NULL внутри `insertUnique()` обязано дать
     * исключение или иной не-`Refused` исход, а не `Refused` — недостающая обязательная
     * колонка не то же самое, что дубль.
     *
     * Проектный `app/Config/Database.php` несёт `strictOn = false` для группы `tests`
     * (как и для `default`) — CI4 явно снимает `STRICT_TRANS_TABLES`/`STRICT_ALL_TABLES`
     * из `sql_mode` соединения на коннекте (`MySQLi\Connection::connect()`), даже когда
     * глобальный `sql_mode` сервера строгий (замер 03.09.2026 подтвердил строгий режим на
     * уровне сервера и для `wildworld_tests`, и для прода). Поэтому здесь пропавшая
     * `NOT NULL`-колонка без `DEFAULT` не бросает `DatabaseException` — MySQL молча
     * подставляет implicit-default (`0` у `INT`), запрос проходит, `insertUnique()` отдаёт
     * `Applied`. Это и есть «иной не-`Refused` исход», которым явно оговорена акцептанс-
     * критерия — проверяем именно факт «не Refused», а не наличие исключения, потому что
     * в этом DB-конфиге исключения физически не будет.
     */
    public function testInsertUniqueOnMissingNotNullColumnIsNotRefused(): void
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

        $this->enableDBDebug();
        $outcome = (new ConditionalWriteService($db))->insertUnique(self::SCRATCH_TABLE, [
            'character_id' => 5,
            // `must_have_task_id` намеренно не передан — обязательная колонка без DEFAULT.
        ]);

        $this->assertNotSame(
            WriteOutcome::Refused,
            $outcome,
            'нарушение NOT NULL не должно маскироваться под "дубль"'
        );
    }
}
