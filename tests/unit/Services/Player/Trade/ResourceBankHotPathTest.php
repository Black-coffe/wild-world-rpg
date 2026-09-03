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
 * exploit-fix-35 (R4-critical + R4-major 2, R4-major 3) — три независимые дыры этого файла и
 * крона, вскрытые кругом 4:
 *
 * 1. `ResourceBankUpdateHandler::process()` держал одну связную пару
 *    `transStart()`/`transComplete()` на каждую итерацию БЕЗ `try/catch`. `transStrict`
 *    (по умолчанию `true`, нигде в проекте не выключается) делает `transStatus` липким:
 *    упавший запрос внутри транзакции CI4 не бросает исключение (глотает молча,
 *    `handleTransStatus()`), а `transComplete()` при `transStrict=true` флаг обратно не
 *    сбрасывает. Один сбой в одной итерации крона банка отравлял `transStatus` до конца
 *    PHP-процесса — соседние task-handler'ы того же прогона (`Config\Tasks.php`) молча
 *    откатывались бы. Фикс — `transBegin()`/`transCommit()`/`transRollback()` в явном
 *    `try/catch` НА КАЖДУЮ итерацию, с проверкой `transStatus()` и обязательным
 *    `resetTransStatus()` на пути ошибки.
 * 2. Оба существующих теста этого файла открывали `$db1->transBegin()` на ОБЩЕМ соединении
 *    без `try/finally` — упавший ассерт или исключение между `transBegin()` и
 *    `transCommit()` оставляли соединение с висящей транзакцией на весь остаток прогона.
 *    Фикс — `try/finally` вокруг каждого блока плюс защитный сброс в `tearDown()`.
 * 3. `process()` не сужен: он проходит `findAll()` по ВСЕМ строкам `resources` и переписывает
 *    чужие `buy_price`/`sell_price`/счётчики банка на смигрированной БД. Фикс — снимок ВСЕХ
 *    существующих строк `resources`/`resources_bank` в `setUp()` (до вставки собственной
 *    тестовой строки) и точное восстановление в `tearDown()`, если таблица не была создана
 *    самим тестом (её тогда дропает `tearDown()` целиком).
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

    /** @var list<int> дополнительные ресурсы, вставленные отдельными тестами (помимо $resourceId) */
    private array $extraResourceIds = [];

    /** @var list<array<string,mixed>> строки resources_bank, существовавшие ДО setUp() — восстанавливаются в tearDown(), если таблица не создана самим тестом */
    private array $resourcesBankSnapshot = [];

    /** @var list<array{id:int,buy_price:mixed,sell_price:mixed}> строки resources, существовавшие ДО setUp() — восстанавливаются в tearDown(), если таблица не создана самим тестом */
    private array $resourcesSnapshot = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->invalidateGameSettingsCache();
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

        // exploit-fix-35 (R4-major 3) — снимок ЧУЖИХ строк ДО того, как этот тест вставит
        // свою собственную: `ResourceBankUpdateHandler::process()` идёт `findAll()` по ВСЕМ
        // `resources` и переписывает `buy_price`/`sell_price` + счётчики банка каждой строки
        // с существующим `resources_bank`-рядом. На смигрированной БД это чужие, реальные
        // игровые данные. Снимок бессмыслен (и пуст) для таблиц, которые создал сам тест —
        // их `tearDown()` дропает целиком.
        if (! in_array('resources', $this->createdTables, true)) {
            $this->resourcesSnapshot = $db->table('resources')->select('id, buy_price, sell_price')->get()->getResultArray();
        }
        if (! in_array('resources_bank', $this->createdTables, true)) {
            $this->resourcesBankSnapshot = $db->table('resources_bank')->get()->getResultArray();
        }

        $this->resourceId = $this->insertResourceRow('exploit-fix-32 hotpath');
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');

        // exploit-fix-35 (R4-major 2) — защитный гард: если тест (или упавший ассерт
        // внутри него) оставил открытую транзакцию на общем соединении, следующий тест
        // унаследовал бы и `transDepth>0`, и потенциально липкий `transStatus=false`.
        // Закрываем принудительно, не полагаясь на то, что сам тест успел это сделать.
        while ($db->transDepth > 0) {
            $db->transRollback();
        }
        $db->resetTransStatus();

        // Точечная чистка своих строк — обязательна ДАЖЕ для таблиц, которые тест не создавал
        // (на смигрированной БД DROP TABLE недопустим, а строка всё равно наша).
        $ownResourceIds = array_merge([$this->resourceId], $this->extraResourceIds);
        foreach ($ownResourceIds as $id) {
            if ($id > 0) {
                $db->table('resources_bank')->where('resource_id', $id)->delete();
                $db->table('resources')->where('id', $id)->delete();
            }
        }

        // exploit-fix-35 (R4-major 3) — восстановление чужих строк, которые process() мог
        // переписать в ходе теста (только для таблиц, которые тест не создавал сам).
        if (! in_array('resources', $this->createdTables, true)) {
            foreach ($this->resourcesSnapshot as $row) {
                $db->table('resources')->where('id', $row['id'])->update([
                    'buy_price'  => $row['buy_price'],
                    'sell_price' => $row['sell_price'],
                ]);
            }
        }
        if (! in_array('resources_bank', $this->createdTables, true)) {
            foreach ($this->resourcesBankSnapshot as $row) {
                $id = $row['id'];
                unset($row['id']);
                $db->table('resources_bank')->where('id', $id)->update($row);
            }
        }

        // Обратный порядок: `resources_bank` несёт FK на `resources` (реальная миграция
        // CreateResourcesBankTable) — DROP TABLE `resources` первым падает на constraint.
        foreach (array_reverse($this->createdTables) as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }

        $this->invalidateGameSettingsCache();
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

    /**
     * exploit-fix-35 (minor m3, круг 4) — раньше `cache()->clean()` чистил ВСЁ хранилище
     * кэша дважды за тест. `ResourceBankUpdateHandler` читает ровно три ключа
     * `GameSettingsReaderTrait`/`GameSettingsService` (`economy.market.*`) — точечная
     * инвалидация только их, а не хранилища целиком.
     */
    private function invalidateGameSettingsCache(): void
    {
        if (! function_exists('cache')) {
            return;
        }
        $c = cache();
        if (! is_object($c) || ! method_exists($c, 'delete')) {
            return;
        }
        foreach ([
            'game_settings_economy_market_proportional_decay_enabled',
            'game_settings_economy_market_half_life_hours',
            'game_settings_economy_market_counter_cap',
        ] as $key) {
            $c->delete($key);
        }
    }

    private function insertResourceRow(string $label): int
    {
        $db  = Database::connect('tests');
        $now = date('Y-m-d H:i:s');
        $db->table('resources')->insert([
            'name'             => 'exploit-fix-32 ' . $label . ' ' . uniqid('', true),
            'name_en'          => 'exploit_fix_32_' . str_replace(' ', '_', $label) . '_' . uniqid('', true),
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
        return (int) $db->insertID();
    }

    private function seedBankRow(int $resourceId, int $currentQuantity, int $purchased, int $sold): void
    {
        Database::connect('tests')->table('resources_bank')->insert([
            'resource_id'         => $resourceId,
            'current_quantity'    => $currentQuantity,
            'resources_purchased' => $purchased,
            'resources_sold'      => $sold,
            'last_update'         => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<string,mixed>|null */
    private function bankRow(int $resourceId): ?array
    {
        return Database::connect('tests')->table('resources_bank')
            ->where('resource_id', $resourceId)->get()->getRowArray();
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
     *
     * exploit-fix-35 (R4-major 2) — `transBegin()`/`transCommit()` теперь в `try/finally`:
     * упавший ассерт между ними больше не оставляет транзакцию открытой на общем
     * соединении на весь остаток прогона (страхует и `tearDown()`-гард).
     */
    public function testIncrementSurvivesRealTwoConnectionRaceOnExistingRow(): void
    {
        $this->seedBankRow($this->resourceId, 3, 0, 10);

        $db1 = Database::connect('tests');
        $db2 = Database::connect('tests', false);

        $db1->transBegin();

        try {
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
        } finally {
            if ($db1->transDepth > 0) {
                $db1->transRollback();
            }
            $db1->resetTransStatus();
        }

        $this->assertSame(WriteOutcome::Applied, $outcome);
        $bank = $this->bankRow($this->resourceId);
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
     * аргументов = defaultGroup), поэтому его `transBegin()` присоединяется к уже
     * открытой транзакции db1 (CI4 `transDepth`), а его `SELECT … FOR UPDATE` обязан
     * прочитать ПОСЛЕДНЮЮ зафиксированную версию строки (locking read, MySQL InnoDB), а
     * не устаревший снимок db1 — иначе состаривание применилось бы к 10, а не к 17, и
     * бамп трейда стёрся бы под абсолютной перезаписью.
     *
     * Другие ресурсы в `resources` (реальные, если БД уже смигрирована) тоже пройдут через
     * `process()`'s `findAll()`-цикл — снимаются в `setUp()` и восстанавливаются в
     * `tearDown()` (exploit-fix-35, R4-major 3), поэтому здесь безвредно по-настоящему,
     * а не только на пустой локальной БД.
     *
     * exploit-fix-35 (R4-major 2) — та же `try/finally`-страховка, что и у теста выше.
     */
    public function testCronDoesNotEraseTradeCommittedBetweenReadAndWrite(): void
    {
        $this->seedBankRow($this->resourceId, 3, 0, 10);

        $db1 = Database::connect('tests');
        $db2 = Database::connect('tests', false);

        $db1->transBegin();

        try {
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
        } finally {
            if ($db1->transDepth > 0) {
                $db1->transRollback();
            }
            $db1->resetTransStatus();
        }

        $bank = $this->bankRow($this->resourceId);
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

    /**
     * Acceptance criteria (R4-critical) — итерация с искусственно упавшим запросом внутри
     * ОТКРЫТОЙ транзакции не должна отравлять `transStatus` до конца процесса: `process()`
     * обязан завершить остальные ресурсы, закрыть свою транзакцию, сбросить липкий флаг, а
     * следующая (полностью здоровая) транзакция того же соединения обязана закоммититься.
     *
     * Воспроизводится ровно тот механизм, что нашёл ревьюер: искусственный сбой запроса
     * ВНУТРИ уже открытой (нашей собственной, `db1`) транзакции ставит `$db1->transStatus`
     * в `false` (`BaseConnection::handleTransStatus()` — флаг липкий, `transDepth!==0`).
     * `ResourceBankUpdateHandler::process()` резолвит ту же группу `'tests'` (тот же
     * закэшированный объект соединения) — его собственные `transBegin()` присоединяются к
     * уже открытой транзакции `db1` (вложенность CI4, `transDepth` растёт), поэтому каждая
     * его итерация НАСЛЕДУЕТ уже отравленный флаг. Первая обработанная итерация обязана
     * поймать это (`transStatus()===false` после своих собственных запросов), закрыть свою
     * вложенную транзакцию, вызвать `resetTransStatus()` и залогировать `error` — именно
     * это снимает отравление для ВСЕХ последующих итераций. Поскольку CI4 не поддерживает
     * savepoint'ы, вложенный `transRollback()` лишь уменьшает `transDepth` — уже
     * выполненные `UPDATE`'ы первой итерации физически не откатываются (тот же нюанс
     * вложенности, уже задокументированный выше для acceptance criteria #2), поэтому оба
     * тестовых ресурса ожидаемо доходят до состаренного состояния независимо от того,
     * какой из них попал на "отравленную" итерацию.
     */
    public function testProcessSurvivesPoisonedTransStatusAndClosesNestedTransaction(): void
    {
        $secondResourceId       = $this->insertResourceRow('hotpath second');
        $this->extraResourceIds[] = $secondResourceId;

        $this->seedBankRow($this->resourceId, 3, 0, 10);
        $this->seedBankRow($secondResourceId, 5, 4, 2);

        $db1 = Database::connect('tests');
        $db2 = Database::connect('tests', false);

        $db1->transBegin();

        try {
            // Искусственный сбой запроса ВНУТРИ уже открытой транзакции — ровно механизм
            // R4-critical (`handleTransStatus()` при `transDepth!==0`).
            @$db1->query('SELECT 1 FROM exploit_fix_35_table_that_does_not_exist_at_all');
            $this->assertFalse($db1->transStatus(), 'предусловие: искусственный сбой действительно испортил transStatus');

            (new ResourceBankUpdateHandler())->process(1);

            // process() обязан закрыть КАЖДУЮ свою собственную (вложенную) транзакцию —
            // после возврата глубина обязана вернуться ровно к уровню, на котором её
            // оставили мы (1), и липкий флаг обязан быть сброшен изнутри process().
            $this->assertSame(1, $db1->transDepth, 'process() обязан закрыть каждую свою вложенную транзакцию (транзакции этого теста не считово)');
            $this->assertTrue($db1->transStatus(), 'липкий флаг обязан быть сброшен process() до возврата из ошибочной итерации');

            $db1->transCommit();
        } finally {
            if ($db1->transDepth > 0) {
                $db1->transRollback();
            }
            $db1->resetTransStatus();
        }

        $this->assertSame(0, $db1->transDepth, 'наша собственная транзакция обязана закрыться полностью');
        $this->assertTrue($db1->transStatus());

        $this->assertLogContains(
            'error',
            'ResourceBankUpdateHandler: итерация для resource_id=',
            'ошибка итерации обязана попасть в лог уровня error с resource_id'
        );

        // Дефолтное состаривание "-1"/"-1" (economy.market.proportional_decay_enabled=false)
        // — оба ресурса обязаны дойти до состаренного значения: вложенный "rollback" без
        // savepoint'ов физически не отменяет уже выполненные UPDATE'ы (см. докблок метода).
        $bankFirst = $this->bankRow($this->resourceId);
        $this->assertNotNull($bankFirst);
        $this->assertSame(9, (int) $bankFirst['resources_sold'], 'первый ресурс обязан дойти до состаренного значения (10-1) независимо от порядка итераций');
        $this->assertSame(0, (int) $bankFirst['resources_purchased']);

        $bankSecond = $this->bankRow($secondResourceId);
        $this->assertNotNull($bankSecond);
        $this->assertSame(1, (int) $bankSecond['resources_sold'], 'второй ресурс обязан дойти до состаренного значения (2-1) независимо от порядка итераций');
        $this->assertSame(3, (int) $bankSecond['resources_purchased'], '4-1');

        // Следующая, полностью здоровая транзакция того же соединения обязана
        // закоммититься и быть видна другому соединению — прямое доказательство того, что
        // отравление не пережило process().
        $db1->transBegin();
        $db1->table('resources_bank')->where('resource_id', $this->resourceId)->update(['current_quantity' => 9]);
        $db1->transCommit();
        $this->assertTrue($db1->transStatus(), 'здоровая транзакция ПОСЛЕ process() обязана закоммититься штатно');

        $verify = $db2->table('resources_bank')->where('resource_id', $this->resourceId)->get()->getRowArray();
        $this->assertNotNull($verify);
        $this->assertSame(
            9,
            (int) $verify['current_quantity'],
            'здоровая транзакция ПОСЛЕ отравленной итерации крона обязана закоммититься и быть видна другому соединению'
        );
    }
}
