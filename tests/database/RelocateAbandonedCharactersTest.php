<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Commands\RelocateAbandonedCharacters;
use App\Database\Migrations\CreateActionLogTable;
use App\Database\Migrations\CreateCharactersTable;
use App\Database\Migrations\CreateClaimedCellsTable;
use App\Database\Migrations\CreateMapTable;
use App\Database\Migrations\CreateTelegramUsersTable;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * pvp-detection-clarity-03 — `relocate:abandoned`: dry-run по умолчанию, боевой переезд
 * брошенных персонажей из угла карты (0,0)/(1,1) на южную полосу только по подтверждающему
 * флагу. Схема строится исполнением настоящих классов миграций
 * (`feedback_test_schema_must_come_from_migration`), как `StandoffAttackGateTest`:
 * таблицы (`telegram_users`, `characters`, `map`, `claimed_cells`, `action_log`)
 * создаются, только если их ещё нет, и дропаются в `tearDown()` тем же прогоном
 * `down()`, но только если создавались ЭТИМ тестом (флаг `$created[...]`) — если
 * стенд уже нёс таблицу до запуска, она остаётся нетронутой.
 *
 * pvp-detection-clarity-15 — `claimed_cells` несёт настоящий FK на `map`
 * (`CreateClaimedCellsTable`); если оставить её лежать (как было раньше — «никогда
 * не дропается»), сосед по набору (`TowerAlertServiceTest`), который делает
 * `DROP TABLE map` рукописным DDL, падает на пустой БД с ошибкой FK-constraint.
 * Дроп в `tearDown()` в порядке, обратном FK-зависимостям (`action_log` →
 * `claimed_cells` → `characters` → `map` → `telegram_users`), снимает конфликт,
 * не трогая соседа и не отключая проверки FK глобально.
 *
 * Время сеется часами БД (`NOW() - INTERVAL ... HOUR/DAY`), не `date()` в PHP
 * (`feedback_db_clock_seed_not_php_in_time_window_tests`) — иначе окно активности
 * проверяется разными часами.
 *
 * @internal
 */
final class RelocateAbandonedCharactersTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private BaseConnection $conn;

    /** @var list<int> */
    private array $telegramUserIds = [];

    /** @var list<int> */
    private array $characterIds = [];

    /** @var list<int> целевые клетки южной полосы, созданные этим тестом */
    private array $targetMapIds = [];

    /** @var array<string,bool> какие таблицы создал этот тест (только их и дропаем) */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->conn = Database::connect();
        $this->conn->resetDataCache();

        // pvp-detection-clarity-22 (minor #I): если что-то из этого посередине бросит
        // исключение (FK-конфликт с несовместимой преднесённой таблицей — ровно то, что
        // воспроизвёл ревьюер), PHPUnit не позовёт tearDown() вовсе, и всё, что успело
        // создаться до броска, осталось бы мусором в базе. Ловим здесь и подчищаем ровно
        // то, что отмечено в `$this->created`, прежде чем перебросить исключение дальше —
        // тот же приём, что и в штатном тесте, только раньше по конвейеру.
        try {
            $this->buildSchema();
            $this->ensureOriginCells();
        } catch (\Throwable $e) {
            $this->dropTrackedTables();
            throw $e;
        }
    }

    private function buildSchema(): void
    {
        // Между двумя вызовами `buildSchema()` в одном тесте (minor #I) схема меняется
        // руками (raw DDL) — `tableExists()` кеширует список таблиц на соединении,
        // без сброса он соврёт про уже пересозданную/снесённую таблицу.
        $this->conn->resetDataCache();

        $this->requireMigrationClasses();
        $forge = Database::forge();
        $forge = $forge instanceof Forge ? $forge : null;

        $this->created['telegram_users'] = ! $this->conn->tableExists('telegram_users');
        if ($this->created['telegram_users']) {
            (new CreateTelegramUsersTable($forge))->up();
        }
        $this->created['characters'] = ! $this->conn->tableExists('characters');
        if ($this->created['characters']) {
            (new CreateCharactersTable($forge))->up();
        }
        $this->created['map'] = ! $this->conn->tableExists('map');
        if ($this->created['map']) {
            (new CreateMapTable($forge))->up();
        }
        $this->created['claimed_cells'] = ! $this->conn->tableExists('claimed_cells');
        if ($this->created['claimed_cells']) {
            (new CreateClaimedCellsTable($forge))->up();
        }
        $this->created['action_log'] = ! $this->conn->tableExists('action_log');
        if ($this->created['action_log']) {
            (new CreateActionLogTable($forge))->up();
        }
    }

    protected function tearDown(): void
    {
        if ($this->targetMapIds !== []) {
            $this->conn->table('map')->whereIn('id', $this->targetMapIds)->delete();
        }
        // characters каскадит на claimed_cells/action_log (ON DELETE CASCADE у обеих FK).
        if ($this->characterIds !== []) {
            $this->conn->table('characters')->whereIn('id', $this->characterIds)->delete();
        }
        if ($this->telegramUserIds !== []) {
            $this->conn->table('telegram_users')->whereIn('id', $this->telegramUserIds)->delete();
        }

        $this->targetMapIds    = [];
        $this->characterIds    = [];
        $this->telegramUserIds = [];

        $this->dropTrackedTables();

        parent::tearDown();
    }

    /**
     * Обратный FK-порядок: action_log → claimed_cells → characters → map →
     * telegram_users. Дропает только то, что отмечено в `$this->created` — иначе
     * унесёт таблицу, которую уже нёс стенд до запуска (pvp-detection-clarity-15).
     * `tableExists()` перед каждым `down()` — на пути отказа из `setUp()`
     * (minor #I) флаг может стоять `true`, а самой таблицы физически ещё нет:
     * `CreateClaimedCellsTable::up()` создаёт таблицу и FK одним атомарным
     * `CREATE TABLE`, и при конфликте типов с чужой `map` она не появляется вовсе —
     * безусловный `down()` в этом случае сам бы бросил «таблица не существует».
     */
    private function dropTrackedTables(): void
    {
        $this->conn->resetDataCache();

        $forge = Database::forge();
        $forge = $forge instanceof Forge ? $forge : null;

        $order = [
            ['action_log', CreateActionLogTable::class],
            ['claimed_cells', CreateClaimedCellsTable::class],
            ['characters', CreateCharactersTable::class],
            ['map', CreateMapTable::class],
            ['telegram_users', CreateTelegramUsersTable::class],
        ];

        foreach ($order as [$table, $class]) {
            if (! empty($this->created[$table]) && $this->conn->tableExists($table)) {
                (new $class($forge))->down();
            }
        }

        $this->created = [];
        $this->conn->resetDataCache();
    }

    public function testDryRunFindsOnlyAbandonedAndWritesNothing(): void
    {
        $abandoned = $this->makeCharacter(cellNumber: 1);
        $active    = $this->makeCharacter(cellNumber: 1002);
        $withBase  = $this->makeCharacter(cellNumber: 1);

        $this->insertActionLog($active, hoursAgo: 12); // активен за 30 дней
        $this->insertClaim($withBase, mapId: 1);        // заклеймённая клетка = не брошен

        $this->seedTargetCells(5);

        $result = $this->command()->relocate(days: 30, yMin: 850, yMax: 899, limit: 0, dryRun: true);

        static::assertSame(1, $result['candidates']);
        static::assertSame(1, count($result['plan']));
        static::assertSame($abandoned, $result['plan'][0]['character_id']);
        static::assertSame(0, $result['moved']);

        $row = $this->conn->table('characters')->where('id', $abandoned)->get()->getRowArray();
        static::assertSame(1, (int) $row['cell_number'], 'dry-run не должен трогать cell_number');

        $auditCount = $this->conn->table('action_log')
            ->where('character_id', $abandoned)
            ->where('action_name', 'abandoned_character_relocated')
            ->countAllResults();
        static::assertSame(0, $auditCount, 'dry-run не должен писать аудит');
    }

    public function testCombatRunRelocatesOnlyAbandonedAndLogsAudit(): void
    {
        $abandoned = $this->makeCharacter(cellNumber: 1002);
        $active    = $this->makeCharacter(cellNumber: 1);
        $withBase  = $this->makeCharacter(cellNumber: 1002);

        $this->insertActionLog($active, hoursAgo: 6);
        $this->insertClaim($withBase, mapId: 1002);

        $this->seedTargetCells(5);

        $result = $this->command()->relocate(days: 30, yMin: 850, yMax: 899, limit: 0, dryRun: false);

        static::assertSame(1, $result['moved']);

        $moved = $this->conn->table('characters')->where('id', $abandoned)->get()->getRowArray();
        static::assertGreaterThanOrEqual(850, (int) $moved['cell_number']);
        static::assertLessThanOrEqual(899, $this->coordinateYFor((int) $moved['cell_number']));

        $stayedActive = $this->conn->table('characters')->where('id', $active)->get()->getRowArray();
        static::assertSame(1, (int) $stayedActive['cell_number']);

        $stayedWithBase = $this->conn->table('characters')->where('id', $withBase)->get()->getRowArray();
        static::assertSame(1002, (int) $stayedWithBase['cell_number']);

        $audit = $this->conn->table('action_log')
            ->where('character_id', $abandoned)
            ->where('action_name', 'abandoned_character_relocated')
            ->where('action_status', 'Completed')
            ->get()->getRowArray();
        static::assertNotNull($audit, 'боевой прогон обязан писать аудит-строку');
    }

    public function testCombatRunDoesNotCollideTwoAbandonedOnSameCell(): void
    {
        $first  = $this->makeCharacter(cellNumber: 1);
        $second = $this->makeCharacter(cellNumber: 1002);

        $this->seedTargetCells(4);

        $result = $this->command()->relocate(days: 30, yMin: 850, yMax: 899, limit: 0, dryRun: false);

        static::assertSame(2, $result['moved']);

        $rows = $this->conn->table('characters')->whereIn('id', [$first, $second])->get()->getResultArray();
        $cells = array_map(static fn (array $r): int => (int) $r['cell_number'], $rows);

        static::assertNotSame($cells[0], $cells[1], 'двое не должны оказаться на одной клетке');
    }

    public function testCombatRunIsIdempotent(): void
    {
        $abandoned = $this->makeCharacter(cellNumber: 1);
        $this->seedTargetCells(3);

        $first  = $this->command()->relocate(days: 30, yMin: 850, yMax: 899, limit: 0, dryRun: false);
        $second = $this->command()->relocate(days: 30, yMin: 850, yMax: 899, limit: 0, dryRun: false);

        static::assertSame(1, $first['moved']);
        static::assertSame(0, $second['candidates'], 'после переезда персонаж больше не стоит на 1/1002 — второй прогон не находит кандидатов');
    }

    /**
     * pvp-detection-clarity-22 (minor #I): воспроизводит именно то падение, что нашёл
     * ревьюер — несовместимая преднесённая `map` рвёт `CreateClaimedCellsTable::up()`
     * на FK-конфликте (`map.id` не INT), и `setUp()` обрывается посередине, успев
     * создать `telegram_users`/`characters`. Доказывает, что после этого в базе не
     * остаётся ни одной из пяти таблиц — не только тех, что действительно возникли.
     */
    public function testSetUpCleansUpPartiallyCreatedTablesWhenItFailsMidway(): void
    {
        // Штатный setUp() этого теста уже отработал и создал всю пятёрку — сносим её,
        // чтобы повторить `buildSchema()` на пустой (но испорченной) базе, ровно как
        // это будет у следующего теста в наборе, если предыдущий прогон не подчистил
        // за собой мусор.
        $this->dropTrackedTables();

        $this->conn->query('CREATE TABLE map (id VARCHAR(10) NOT NULL PRIMARY KEY)');
        $this->conn->resetDataCache();

        $threw = false;

        try {
            $this->buildSchema();
            $this->ensureOriginCells();
        } catch (\Throwable $e) {
            $this->dropTrackedTables();
            $threw = true;
        }

        $this->assertTrue($threw, 'предпосылка теста: несовместимая map обязана ронять создание схемы на FK claimed_cells.map_cell_id');
        $this->assertFalse($this->conn->tableExists('telegram_users'), 'мусор от упавшего посередине setUp() не должен остаться в базе');
        $this->assertFalse($this->conn->tableExists('characters'), 'мусор от упавшего посередине setUp() не должен остаться в базе');
        $this->assertFalse($this->conn->tableExists('claimed_cells'), 'FK-провал не должен оставить недо-таблицу');
        $this->assertFalse($this->conn->tableExists('action_log'), 'до action_log в этом прогоне дело не дошло, но проверяем и его');

        // Возвращаем консистентную схему — иначе настоящий tearDown() этого теста
        // унаследует несовместимую map и упадёт сам, забрав с собой остальной набор.
        $this->conn->query('DROP TABLE IF EXISTS map');
        $this->buildSchema();
        $this->ensureOriginCells();
    }

    private function command(): RelocateAbandonedCharacters
    {
        return new RelocateAbandonedCharacters(service('logger'), service('commands'));
    }

    private function ensureOriginCells(): void
    {
        // map.id == map.cell_number для реальных данных (см. ClaimedCellModel); клетки
        // угла карты (0,0)/(1,1) — общая инфраструктура, никогда не удаляются.
        if ($this->conn->table('map')->where('id', 1)->countAllResults() === 0) {
            $this->conn->table('map')->insert([
                'id' => 1, 'cell_number' => 1, 'coordinate_x' => 0, 'coordinate_y' => 0, 'biome_id' => 1,
            ]);
        }
        if ($this->conn->table('map')->where('id', 1002)->countAllResults() === 0) {
            $this->conn->table('map')->insert([
                'id' => 1002, 'cell_number' => 1002, 'coordinate_x' => 1, 'coordinate_y' => 1, 'biome_id' => 1,
            ]);
        }
    }

    /**
     * Заводит $count свободных клеток южной полосы (y=[850,899], биом из списка спавна),
     * с явными уникальными id=cell_number (не пересекаются с origin-клетками 1/1002).
     */
    private function seedTargetCells(int $count): void
    {
        static $next = 900_000;

        for ($i = 0; $i < $count; $i++) {
            $id = $next++;
            $this->conn->table('map')->insert([
                'id' => $id, 'cell_number' => $id, 'coordinate_x' => $i, 'coordinate_y' => 850 + $i, 'biome_id' => 1,
            ]);
            $this->targetMapIds[] = $id;
        }
    }

    private function coordinateYFor(int $cellNumber): int
    {
        $row = $this->conn->table('map')->where('cell_number', $cellNumber)->get()->getRowArray();
        return (int) ($row['coordinate_y'] ?? -1);
    }

    private function makeCharacter(int $cellNumber, int $level = 1): int
    {
        $this->conn->table('telegram_users')->insert(['telegram_id' => random_int(10_000_000, 999_999_999)]);
        $tgId = (int) $this->conn->insertID();
        $this->telegramUserIds[] = $tgId;

        $this->conn->table('characters')->insert([
            'telegram_user_id' => $tgId,
            'name'              => 'Заброшенный ' . $tgId,
            'level'             => $level,
            'cell_number'       => $cellNumber,
        ]);
        $characterId = (int) $this->conn->insertID();
        $this->characterIds[] = $characterId;

        return $characterId;
    }

    /** Часы берутся у БД (`NOW() - INTERVAL ... HOUR`), не у PHP. */
    private function insertActionLog(int $characterId, int $hoursAgo): void
    {
        $this->conn->query(
            'INSERT INTO action_log (character_id, chat_id, action_name, action_status, created_at, updated_at)
             VALUES (?, 0, ?, ?, NOW() - INTERVAL ? HOUR, NOW())',
            [$characterId, 'gather', 'Completed', $hoursAgo]
        );
    }

    private function insertClaim(int $characterId, int $mapId): void
    {
        $this->conn->table('claimed_cells')->insert([
            'character_id' => $characterId,
            'map_cell_id'  => $mapId,
            'claimed_at'   => date('Y-m-d H:i:s'),
            'status'       => 'active',
        ]);
    }

    private function requireMigrationClasses(): void
    {
        $classes = [
            CreateTelegramUsersTable::class => '2024-03-20-153728_CreateTelegramUsersTable.php',
            CreateCharactersTable::class    => '2024-03-20-154155_CreateCharactersTable.php',
            CreateMapTable::class           => '2024-03-18-105708_CreateMapTable.php',
            CreateClaimedCellsTable::class  => '2024-05-23-061031_CreateClaimedCellsTable.php',
            CreateActionLogTable::class     => '2024-03-18-134951_CreateActionLogTable.php',
        ];

        foreach ($classes as $class => $file) {
            if (! class_exists($class, false)) {
                require_once APPPATH . 'Database/Migrations/' . $file;
            }
        }
    }
}
