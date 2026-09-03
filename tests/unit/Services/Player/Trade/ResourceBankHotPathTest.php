<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Player\Trade;

use App\Models\ResourcesBankModel;
use App\Services\Db\WriteOutcome;
use App\TaskHandlers\ResourceBankUpdateHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * exploit-fix-32 (R3-major) — горячая ветка «строка `resources_bank` уже есть» (продажа,
 * оптовая продажа) и крон `ResourceBankUpdateHandler` раньше читали строку обычным
 * `first()`/`SELECT` и писали счётчики АБСОЛЮТНЫМ значением: параллельная сделка,
 * попавшая в окно между чтением и записью, стиралась целиком — тот же класс дыры, что
 * story 25 закрыла на ветке `Refused` первой сделки ресурса (см. докблок
 * `ResourceBankInsertRaceTest`).
 *
 * **CI-инцидент (review round после первой версии этого файла):** ручная,
 * заведомо неполная схема (`resources` без `game_settings`, без `created_at`/`updated_at`)
 * комбинировалась с ручной внешней транзакцией (`$db1->transBegin()`, обёртывающая вызов
 * `ResourceBankUpdateHandler::process()` — та самая, из которой состоит тест-доказательство
 * acceptance criteria #2). `process()` дергает `GameSettingsReaderTrait::gsBool()` →
 * `SELECT … FROM game_settings` — таблицы не было → запрос падал. Внутри УЖЕ ОТКРЫТОЙ
 * транзакции (`transDepth>0`) CI4 такой сбой не бросает исключением (см.
 * `BaseConnection::query()`: «In transactions, do not throw exception by default») — глотает
 * его молча, выставляя `$db->transStatus=false` на shared-соединении (`Database::connect('tests')`
 * без `false`-аргумента — та же кэшированная инстанция, что использует ЛЮБАЯ модель во ВСЕХ
 * тестах процесса). `transBegin()` НЕ сбрасывает `transStatus` в `true` при старте нового
 * top-level блока — флаг остаётся `false` до конца PHPUnit-процесса. Любой СЛЕДУЮЩИЙ тест
 * (`GreenhouseProductionWaterTest` и др.), чей код использует `transStart()/transComplete()`
 * на этом же shared-соединении, с этого момента ВСЕГДА уходит в `transComplete()`'s
 * rollback-ветку (`transStatus === false`) — списания молча откатываются, счётчики
 * «застревают» на исходном значении.
 *
 * Фикс: таблицы, нужные тесту (`game_settings`, `resources`, `resources_bank`), строятся из
 * РЕАЛЬНЫХ классов миграций — если их ещё нет (пустая CI-БД). Если они УЖЕ существуют
 * (смигрированная БД) — схема не трогается вовсе, тест лишь читает/пишет свои собственные
 * строки (динамический `resource_id` от `insertID()`, не константа — на смигрированной БД
 * фиксированный id столкнулся бы с реальными игровыми ресурсами). `tearDown()` удаляет только
 * то, что создал сам: DROP TABLE — только для таблиц, которых не было до `setUp()`; иначе —
 * точечный DELETE своих строк.
 *
 * @internal
 */
final class ResourceBankHotPathTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    /** @var list<string> таблицы, которых не было до setUp() этого теста — их дропает tearDown() */
    private array $createdTables = [];

    private int $resourceId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();
        $db = Database::connect('tests');

        $this->ensureTable($db, 'game_settings', function (): void {
            $this->runMigration('2026-05-19-100000_CreateGameSettingsTable.php', \App\Database\Migrations\CreateGameSettingsTable::class);
        });
        // exploit-fix-32 — НЕ через реальную миграцию: `2024-03-21-224528_CreateResourcesTable`
        // несёт дормантный баг Forge (`biome_id` TEXT + `unsigned=>true` → CI4 Forge
        // безусловно приписывает "UNSIGNED" любому типу, TEXT UNSIGNED — невалидный SQL,
        // MySQL 8 его отклоняет) — падает и на прогоне ЭТОГО теста, и на голом
        // `php spark migrate` на пустой БД. Отдельный, не относящийся к этой story баг
        // (не в списке `## Files`) — вынесен в отчёт worker'а как CONCERNS, не чинится
        // здесь. Ручная схема ниже — тот же набор колонок, что и в миграции, минус
        // единственная сломанная (`biome_id` без `unsigned`, эта колонка тестом не читается).
        $this->ensureTable($db, 'resources', static function () use ($db): void {
            $db->query('
                CREATE TABLE resources (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(255) NOT NULL UNIQUE,
                    name_en VARCHAR(255) NOT NULL UNIQUE,
                    description TEXT NULL,
                    biome_id TEXT NULL,
                    is_tradeable BOOLEAN NOT NULL,
                    type VARCHAR(255) NOT NULL,
                    price INT NOT NULL,
                    buy_price DECIMAL(10,2) NULL,
                    sell_price DECIMAL(10,2) NULL,
                    rarity INT NOT NULL,
                    level_required INT NOT NULL,
                    initial_quantity INT NOT NULL DEFAULT 100,
                    keyword VARCHAR(100) NULL,
                    icon_text TEXT NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL
                )
            ');
        });
        $this->ensureTable($db, 'resources_bank', function (): void {
            $this->runMigration('2024-04-01-081639_CreateResourcesBankTable.php', \App\Database\Migrations\CreateResourcesBankTable::class);
            $this->runMigration('2026-09-02-130100_Adr181UniqueResourcesBank.php', \App\Database\Migrations\Adr181UniqueResourcesBank::class);
        });

        $now = date('Y-m-d H:i:s');
        $db->table('resources')->insert([
            'name'             => 'exploit-fix-32 hotpath ' . uniqid('', true),
            'name_en'          => 'exploit_fix_32_hotpath_' . uniqid('', true),
            'description'      => null,
            'biome_id'         => null,
            'is_tradeable'     => 1,
            'type'             => 'craft',
            'price'            => 5,
            'buy_price'        => 10.0,
            'sell_price'       => 4.0,
            'rarity'           => 1,
            'level_required'   => 1,
            'initial_quantity' => 100,
            'keyword'          => null,
            'icon_text'        => '🔧',
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
        // Динамический id — на уже смигрированной БД (не создавали таблицу сами) реальные
        // игровые ресурсы уже занимают низкие id, фиксированная константа коллизировала бы.
        $this->resourceId = (int) $db->insertID();
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');

        // Точечная чистка своих строк — обязательна ДАЖЕ для таблиц, которые тест не создавал
        // (на смигрированной БД DROP TABLE недопустим, а строка всё равно наша).
        if ($this->resourceId > 0) {
            $db->table('resources_bank')->where('resource_id', $this->resourceId)->delete();
            $db->table('resources')->where('id', $this->resourceId)->delete();
        }

        // Обратный порядок: `resources_bank` несёт FK на `resources` (реальная миграция
        // CreateResourcesBankTable) — DROP TABLE `resources` первым падает на constraint.
        foreach (array_reverse($this->createdTables) as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }

        $this->cleanCache();
        parent::tearDown();
    }

    /** Строит таблицу из реальной миграции, только если её ещё нет — и только тогда её дропает tearDown(). */
    private function ensureTable(\CodeIgniter\Database\BaseConnection $db, string $table, callable $createViaMigration): void
    {
        if ($db->tableExists($table, false)) {
            return;
        }
        $createViaMigration();
        $this->createdTables[] = $table;
    }

    /**
     * Файлы миграций именуются по дате (`2026-05-19-100000_Class.php`), не по PSR-4 — Composer
     * их не автозагружает. `require_once` по реальному пути перед инстанцированием, как это
     * делает сам CI4 `MigrationRunner`.
     */
    private function runMigration(string $fileName, string $className): void
    {
        if (! class_exists($className, false)) {
            require_once APPPATH . 'Database/Migrations/' . $fileName;
        }
        (new $className())->up();
    }

    private function cleanCache(): void
    {
        if (function_exists('cache')) {
            $c = cache();
            if (is_object($c) && method_exists($c, 'clean')) {
                $c->clean();
            }
        }
    }

    private function seedBankRow(int $currentQuantity, int $purchased, int $sold): void
    {
        Database::connect('tests')->table('resources_bank')->insert([
            'resource_id'         => $this->resourceId,
            'current_quantity'    => $currentQuantity,
            'resources_purchased' => $purchased,
            'resources_sold'      => $sold,
            'last_update'         => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<string,mixed>|null */
    private function bankRow(): ?array
    {
        return Database::connect('tests')->table('resources_bank')
            ->where('resource_id', $this->resourceId)->get()->getRowArray();
    }

    /**
     * Acceptance criteria #1 — две сделки одного ресурса из двух соединений, вторая
     * внутри открытой транзакции первой → после обоих коммитов `resources_sold` = сумма,
     * ни один бамп не потерян.
     *
     * db1 держит открытую транзакцию и снимает REPEATABLE READ снимок строки банка ДО
     * того, как db2 применит и закоммитит свой бамп — ровно то TOCTOU-окно, в которое
     * раньше (обычный `SELECT` + абсолютная запись) проваливалась вторая сделка. `db2`
     * пишет сырым `UPDATE … SET resources_sold = resources_sold + ?` — той же формой,
     * что `ConditionalWriteService::increment()` — эмулируя реальную вторую сделку
     * своим собственным mysqli-линком (`Database::connect('tests', false)`, тот же
     * приём, что `ResourceBankInsertRaceTest`). Продакшн-путь (`incrementCounterIfExists()`,
     * `ResourcesBankModel::conditionalWrite()` резолвит дефолтную группу — тот же
     * коннекшн, что db1) применяется ПОСЛЕ, всё ещё внутри открытой транзакции db1.
     */
    public function testIncrementSurvivesRealTwoConnectionRaceOnExistingRow(): void
    {
        $this->seedBankRow(3, 0, 10);

        $db1 = Database::connect('tests');
        $db2 = Database::connect('tests', false);

        $db1->transBegin();

        $seenBeforeRace = $db1->table('resources_bank')->where('resource_id', $this->resourceId)->get()->getRowArray();
        $this->assertNotNull($seenBeforeRace);
        $this->assertSame(10, (int) $seenBeforeRace['resources_sold'], 'предусловие: снимок db1 видит только исходные 10');

        // "Второе соединение" — вторая сделка того же ресурса, целиком закоммичена
        // ВНУТРИ открытой транзакции db1 (acceptance criteria #1).
        $db2->query(
            'UPDATE resources_bank SET resources_sold = resources_sold + ? WHERE resource_id = ?',
            [7, $this->resourceId]
        );

        // Первая сделка (db1, всё ещё внутри своей открытой транзакции) бампает через
        // продакшн-метод — `increment()` внутри него делает locking read (InnoDB читает
        // последнюю зафиксированную версию, а не снимок db1), поэтому видит уже
        // закоммиченный бамп db2, а не теряет его перезаписью абсолютным значением.
        $outcome = (new ResourcesBankModel())->incrementCounterIfExists($this->resourceId, 5, 'resources_sold');

        $db1->transCommit();

        $this->assertSame(WriteOutcome::Applied, $outcome);
        $bank = $this->bankRow();
        $this->assertNotNull($bank);
        $this->assertSame(
            22,
            (int) $bank['resources_sold'],
            '10 исходных + 7 (db2, внутри окна) + 5 (db1) — ни один бамп не потерян'
        );
        $this->assertSame(3, (int) $bank['current_quantity'], 'increment() не трогает чужие поля строки');
    }

    /**
     * Acceptance criteria #2 — сделка между чтением и записью крона не стирается.
     *
     * db1 открывает транзакцию и снимает REPEATABLE READ снимок ДО трейда (ровно то
     * состояние, которое раньше — при обычном `SELECT` без `FOR UPDATE` — видел бы крон
     * внутри своей собственной, уже открытой, транзакции). Трейд коммитится ПОСЛЕ этого
     * снимка вторым, независимым соединением. `ResourceBankUpdateHandler::process()`
     * резолвит ту же группу `'tests'`, что и db1 (`\Config\Database::connect()` без
     * аргументов = defaultGroup), поэтому его `transStart()` присоединяется к уже
     * открытой транзакции db1 (CI4 `transDepth`), а его `SELECT … FOR UPDATE` обязан
     * прочитать ПОСЛЕДНЮЮ зафиксированную версию строки (locking read, MySQL InnoDB), а
     * не устаревший снимок db1 — иначе состаривание применилось бы к 10, а не к 17, и
     * бамп трейда стёрся бы под абсолютной перезаписью.
     *
     * Другие ресурсы в `resources` (реальные, если БД уже смигрирована) тоже пройдут через
     * `process()`'s `findAll()`-цикл — безвредно: у них либо нет строки `resources_bank`
     * (handler их просто пропускает), либо есть своя, которую этот тест не проверяет.
     */
    public function testCronDoesNotEraseTradeCommittedBetweenReadAndWrite(): void
    {
        $this->seedBankRow(3, 0, 10);

        $db1 = Database::connect('tests');
        $db2 = Database::connect('tests', false);

        $db1->transBegin();

        $staleSnapshot = $db1->table('resources_bank')->where('resource_id', $this->resourceId)->get()->getRowArray();
        $this->assertNotNull($staleSnapshot);
        $this->assertSame(10, (int) $staleSnapshot['resources_sold'], 'предусловие: снимок db1 не видит ещё не случившийся трейд');

        // Трейд коммитится ПОСЛЕ снимка db1, но ДО записи крона — окно между чтением
        // и записью крона из Goal story.
        $db2->query(
            'UPDATE resources_bank SET resources_sold = resources_sold + ? WHERE resource_id = ?',
            [7, $this->resourceId]
        );

        (new ResourceBankUpdateHandler())->process(1);

        $db1->transCommit();

        $bank = $this->bankRow();
        $this->assertNotNull($bank);
        // Дефолтное состаривание (`economy.market.proportional_decay_enabled=false`, как
        // при отсутствующей строке настройки, так и при реальном seed-значении из
        // `2026-12-04-100000_SeedMarketDecayGameSettings`, `value_bool=0`) — "-1" от
        // значения, которое РЕАЛЬНО увидел крон. Увидел бы устаревший снимок (10) —
        // получили бы max(0, 10-1)=9, бамп трейда пропал бы. Увидел свежие 17 (10 + 7
        // трейда) — получаем 16.
        $this->assertSame(
            16,
            (int) $bank['resources_sold'],
            'крон обязан состарить СВЕЖЕЕ значение (17 = 10 + бамп трейда), не устаревший снимок db1 (10)'
        );
    }
}
