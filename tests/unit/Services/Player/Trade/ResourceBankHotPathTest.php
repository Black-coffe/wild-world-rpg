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
 * exploit-fix-39 (R5-critical, R5-major 2/3, R5 m10) — круг 5 воспроизвёл реальный
 * end-to-end сбой, который story 35 не закрыла: ранний выход «строки банка нет»
 * (`!$bankData`) уходил в `transCommit(); continue;` МИМО единственной проверки
 * `transStatus()` — упавший `SELECT … FOR UPDATE` (lock-wait-timeout против живой
 * сделки) даёт ТОТ ЖЕ `$bankData === null`, что и штатное «строки нет», и утекал молча,
 * без лога, оставляя `transStatus=false` до конца процесса крона. Старый различающий
 * тест ниже (`testProcessSurvivesPoisonedTransStatusAndClosesNestedTransaction`) травил
 * флаг ЧУЖИМ запросом ДО вызова `process()` — обе его итерации доходили до проверки в
 * конце тела и ловились, ветка `!$bankData` не исполнялась ни разу, поэтому дефект
 * оставался незамеченным (R5-major 3). Тот же тест ТРЕБОВАЛ, чтобы `process()`,
 * вызванный ВНУТРИ уже открытой транзакции вызывающего, стирал чужой отравленный флаг
 * (`resetTransStatus()` в `catch` был безусловным) — без savepoint'ов в CI4 это
 * означало, что крон способен «вылечить» обречённую на откат чужую транзакцию, и она
 * спокойно закоммитится (R5-major 2).
 *
 * Фикс в `ResourceBankUpdateHandler::process()`: весь код итерации (чтение, запись)
 * сходится в ОДНУ точку проверки `transStatus()` — она видит упавший
 * `SELECT … FOR UPDATE` ровно так же, как упавшую запись, потому что оба случая дают
 * один и тот же `$bankData===null`/не-null путь и ни один не выходит из тела итерации
 * раньше этой точки (single `transStatus()` call site — вызов дважды в одном PHP-скоупе
 * даёт для phpstan `identical.alwaysFalse` на «чистой» — с точки зрения статического
 * анализа — функции). Та же точка проверяет исход `transCommit()` (R5 m10) и вызывает
 * `resetTransStatus()` ТОЛЬКО если ЭТА итерация была транзакцией верхнего уровня
 * (`transDepth` был `0` до её собственного `transBegin()`).
 *
 * Три новых различающих теста, реальный триггер (второе mysqli-соединение держит
 * `FOR UPDATE`, `SET SESSION innodb_lock_wait_timeout=1` на соединении `process()`, не
 * мок):
 * - `testProcessLogsAndRecoversWhenReadInsideIterationFails` (Тест A) — лочит строку
 *   `resources_bank` ДО вызова `process()`: его собственный `SELECT … FOR UPDATE`
 *   ловит реальный lock-wait-timeout.
 * - `testProcessLogsAndRecoversWhenWriteInsideIterationFails` (Тест B) — лочит строку
 *   `resources` (не `resources_bank`, иначе заблокировался бы тот же `SELECT … FOR
 *   UPDATE`, что и в тесте A): чтение банка проходит штатно, а `resourceModel->update()`
 *   внутри итерации ловит lock-wait-timeout на записи.
 * - `testProcessDoesNotClearCallersPoisonedTransStatusWhenNested` (Тест C, заменяет
 *   старый тест выше) — отравляет флаг ВНЕШНЕЙ транзакции ДО вызова `process()` и
 *   проверяет, что флаг остаётся `false` ПОСЛЕ (а не стирается — R5-major 2).
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
        //
        // exploit-fix-39 (R6-major 2) — раньше `while ($db->transDepth > 0)` без верхней
        // границы: `transRollback()` при глубине >1 лишь декрементирует счётчик
        // (`BaseConnection::transRollback()` — нет savepoint'ов), но если РЕАЛЬНЫЙ
        // драйверный откат на глубине 1 (`_transRollback()`) вернёт `false` (обрыв
        // соединения, etc.) — CI4 не декрементирует `transDepth` вовсе, и цикл крутится
        // бесконечно, вешая весь прогон. Теперь гард ограничен стартовой глубиной: не
        // больше `$startDepth` попыток, `resetTransStatus()` всегда после цикла, и если
        // глубина всё ещё не `0` — тест обязан упасть громко с текстом остатка, а не
        // молча зависнуть или тихо пропустить незакрытую транзакцию дальше.
        $startDepth = $db->transDepth;
        for ($i = 0; $i < $startDepth && $db->transDepth > 0; $i++) {
            $db->transRollback();
        }
        $db->resetTransStatus();

        if ($db->transDepth > 0) {
            throw new \RuntimeException(
                'ResourceBankHotPathTest::tearDown(): аварийный гард не смог закрыть транзакцию за '
                . $startDepth . ' попыт(ку/ки/ок) transRollback() — transDepth=' . $db->transDepth . ' всё ещё > 0'
            );
        }

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
     * Восстанавливает `innodb_lock_wait_timeout` на дефолт MySQL (50с) — тесты A/B
     * ускоряют таймаут до 1с на общем закэшированном соединении `tests` (`defaultGroup`
     * под phpunit), чтобы не ждать реальные полминуты; значение сессионное, но
     * соединение переживает тест и разделяется со всем остальным прогоном — если не
     * вернуть, следующий тест того же процесса, которому легитимно нужно подождать лок
     * дольше секунды, ловил бы ложный таймаут.
     */
    private function restoreLockWaitTimeout(\CodeIgniter\Database\BaseConnection $db): void
    {
        $db->query('SET SESSION innodb_lock_wait_timeout = 50');
    }

    /**
     * Тест A (acceptance criteria story 39) — падение ЧТЕНИЯ внутри итерации: реальный
     * lock-wait-timeout, не мок.
     *
     * Второе, независимое mysqli-соединение (`db2`, тот же приём, что и в тестах выше)
     * держит `SELECT … FOR UPDATE` на строке `resources_bank` заблокированного ресурса
     * ДО вызова `process()` — ровно та живая сделка, о которой говорит Goal story
     * (`ConditionalWriteService::increment()` берёт X-lock на ту же строку). Соединению,
     * которым пользуется `process()` (`\Config\Database::connect()` без аргументов =
     * `defaultGroup`, под phpunit это та же группа `'tests'`, что и `db1`), выставлен
     * `innodb_lock_wait_timeout=1`, чтобы MySQL сам уронил `SELECT … FOR UPDATE` его
     * собственной итерации реальной ошибкой 1205 — не через искусственный несуществующий
     * запрос, а через настоящий конфликт блокировок.
     *
     * Условие story: упавший SELECT даёт $bankData===null — та же единственная точка
     * проверки `transStatus()`, что и штатное «строки нет» — ловит его, ошибка попадает
     * в error-лог с `resource_id`, `transStatus()===true` и `transDepth===0` после
     * `process()`, соседний незаблокированный ресурс обработан
     * штатно, а следующая транзакция того же соединения коммитится и видна второму
     * соединению.
     */
    public function testProcessLogsAndRecoversWhenReadInsideIterationFails(): void
    {
        $secondResourceId         = $this->insertResourceRow('hotpath read-lock neighbour');
        $this->extraResourceIds[] = $secondResourceId;

        $this->seedBankRow($this->resourceId, 3, 0, 10);
        $this->seedBankRow($secondResourceId, 5, 4, 2);

        $db1 = Database::connect('tests');
        $db2 = Database::connect('tests', false);

        try {
            $db2->transBegin();
            $db2->query(
                'SELECT id FROM ' . $db1->prefixTable('resources_bank') . ' WHERE resource_id = ? FOR UPDATE',
                [$this->resourceId]
            );

            $db1->query('SET SESSION innodb_lock_wait_timeout = 1');

            (new ResourceBankUpdateHandler())->process(1);
        } finally {
            if ($db2->transDepth > 0) {
                $db2->transRollback();
            }
            $this->restoreLockWaitTimeout($db1);
        }

        $this->assertSame(0, $db1->transDepth, 'process() обязан закрыть свою транзакцию даже на сбойной итерации чтения');
        $this->assertTrue($db1->transStatus(), 'top-level итерация обязана сбросить липкий флаг перед возвратом из process()');

        $this->assertLogContains(
            'error',
            'resource_id=' . $this->resourceId,
            'сбой SELECT … FOR UPDATE обязан попасть в лог error с resource_id заблокированного ресурса'
        );

        $bankBlocked = $this->bankRow($this->resourceId);
        $this->assertNotNull($bankBlocked);
        $this->assertSame(10, (int) $bankBlocked['resources_sold'], 'сбойная итерация не применяет частичную запись — строка не изменилась');
        $this->assertSame(0, (int) $bankBlocked['resources_purchased']);

        $bankSecond = $this->bankRow($secondResourceId);
        $this->assertNotNull($bankSecond);
        $this->assertSame(1, (int) $bankSecond['resources_sold'], 'сосед по циклу не пострадал от чужого сбоя чтения');
        $this->assertSame(3, (int) $bankSecond['resources_purchased']);

        $db1->transBegin();
        $db1->table('resources_bank')->where('resource_id', $this->resourceId)->update(['current_quantity' => 9]);
        $db1->transCommit();
        $this->assertTrue($db1->transStatus(), 'здоровая транзакция ПОСЛЕ сбойного чтения обязана закоммититься штатно');

        $verify = $db2->table('resources_bank')->where('resource_id', $this->resourceId)->get()->getRowArray();
        $this->assertNotNull($verify);
        $this->assertSame(9, (int) $verify['current_quantity'], 'здоровая транзакция после сбоя видна второму соединению');
    }

    /**
     * Тест B (acceptance criteria story 39) — падение ЗАПИСИ внутри итерации, тот же
     * реальный триггер, но лок стоит на другой таблице: `resources_bank`
     * заблокирована бы `SELECT … FOR UPDATE` (тест A), поэтому чтение банка обязано
     * пройти штатно, а падать обязана именно запись — `db2` держит `FOR UPDATE` на
     * строке `resources` того же ресурса, и её ловит `resourceModel->update()`
     * (`buy_price`/`sell_price`) внутри тела итерации.
     */
    public function testProcessLogsAndRecoversWhenWriteInsideIterationFails(): void
    {
        $secondResourceId         = $this->insertResourceRow('hotpath write-lock neighbour');
        $this->extraResourceIds[] = $secondResourceId;

        $this->seedBankRow($this->resourceId, 3, 0, 10);
        $this->seedBankRow($secondResourceId, 5, 4, 2);

        $db1 = Database::connect('tests');
        $db2 = Database::connect('tests', false);

        try {
            $db2->transBegin();
            $db2->query(
                'SELECT id FROM ' . $db1->prefixTable('resources') . ' WHERE id = ? FOR UPDATE',
                [$this->resourceId]
            );

            $db1->query('SET SESSION innodb_lock_wait_timeout = 1');

            (new ResourceBankUpdateHandler())->process(1);
        } finally {
            if ($db2->transDepth > 0) {
                $db2->transRollback();
            }
            $this->restoreLockWaitTimeout($db1);
        }

        $this->assertSame(0, $db1->transDepth, 'process() обязан закрыть свою транзакцию даже на сбойной итерации записи');
        $this->assertTrue($db1->transStatus(), 'top-level итерация обязана сбросить липкий флаг перед возвратом из process()');

        $this->assertLogContains(
            'error',
            'resource_id=' . $this->resourceId,
            'сбой записи обязан попасть в лог error с resource_id заблокированного ресурса'
        );

        $bankBlocked = $this->bankRow($this->resourceId);
        $this->assertNotNull($bankBlocked);
        $this->assertSame(10, (int) $bankBlocked['resources_sold'], 'сбойная итерация не применяет частичную запись банка');
        $this->assertSame(0, (int) $bankBlocked['resources_purchased']);

        $bankSecond = $this->bankRow($secondResourceId);
        $this->assertNotNull($bankSecond);
        $this->assertSame(1, (int) $bankSecond['resources_sold'], 'сосед по циклу не пострадал от чужого сбоя записи');
        $this->assertSame(3, (int) $bankSecond['resources_purchased']);

        $db1->transBegin();
        $db1->table('resources_bank')->where('resource_id', $this->resourceId)->update(['current_quantity' => 9]);
        $db1->transCommit();
        $this->assertTrue($db1->transStatus(), 'здоровая транзакция ПОСЛЕ сбойной записи обязана закоммититься штатно');

        $verify = $db2->table('resources_bank')->where('resource_id', $this->resourceId)->get()->getRowArray();
        $this->assertNotNull($verify);
        $this->assertSame(9, (int) $verify['current_quantity'], 'здоровая транзакция после сбоя видна второму соединению');
    }

    /**
     * Тест C (acceptance criteria story 39, замена старого неразличающего теста
     * R5-major 3) — `process()` вызван ВНУТРИ уже открытой транзакции вызывающего, флаг
     * которой отравлен ДО вызова. R5-major 2: без savepoint'ов в CI4 вложенный
     * `transRollback()` лишь уменьшает `transDepth`, физически не откатывая уже
     * выполненные запросы внешней транзакции — поэтому единственный правильный ответ
     * `process()` на чужую беду это НЕ трогать чужой флаг. Старый тест на этом самом
     * месте требовал обратного (`assertTrue($db1->transStatus())` после `process()`) и
     * тем самым закреплял «крон лечит чужой сбой» как эталонное поведение. Реализация
     * ниже: ни одна итерация не резолвит `topLevelIteration` в `true` (обе вложены в
     * уже открытую транзакцию `db1`, `transDepth` не `0` ни у одной), единственная
     * точка проверки после чтения/записи видит флаг уже `false` (унаследован от
     * отравления ДО `process()`) и бросает для каждой итерации — сами записи
     * (`resourceModel->update()`/`resourcesBankModel->update()`) при этом физически
     * выполняются (флаг не блокирует дальнейшие запросы, лишь помечает исход), но
     * остаются частью ЕДИНОЙ, всё ещё не закоммиченной внешней транзакции `db1` и
     * стираются финальным РЕАЛЬНЫМ `transRollback()` теста на `transDepth===1` (CI4
     * коммитит/откатывает физически только на границе глубины 0↔1 — нет
     * savepoint'ов для промежуточных уровней) — поэтому счётчики банка после теста
     * равны исходным.
     */
    public function testProcessDoesNotClearCallersPoisonedTransStatusWhenNested(): void
    {
        $secondResourceId         = $this->insertResourceRow('hotpath nested poisoned');
        $this->extraResourceIds[] = $secondResourceId;

        $this->seedBankRow($this->resourceId, 3, 0, 10);
        $this->seedBankRow($secondResourceId, 5, 4, 2);

        $db1 = Database::connect('tests');

        $db1->transBegin();

        try {
            // Отравляем флаг ВНЕШНЕЙ (уже открытой) транзакции ДО вызова process() —
            // тот же искусственный несуществующий запрос, что и в старом тесте.
            @$db1->query('SELECT 1 FROM exploit_fix_35_table_that_does_not_exist_at_all');
            $this->assertFalse($db1->transStatus(), 'предусловие: искусственный сбой действительно испортил transStatus');

            (new ResourceBankUpdateHandler())->process(1);

            // process() обязан закрыть КАЖДУЮ свою собственную (вложенную) транзакцию —
            // глубина обязана вернуться ровно к уровню, на котором её оставили мы (1).
            $this->assertSame(1, $db1->transDepth, 'process() обязан закрыть каждую свою вложенную транзакцию');
            // Внешний отравленный флаг НЕ должен стираться вложенным process() —
            // R5-major 2, ядро этого теста.
            $this->assertFalse($db1->transStatus(), 'внешний отравленный флаг обязан остаться отравленным после вложенного process()');
        } finally {
            if ($db1->transDepth > 0) {
                $db1->transRollback();
            }
            $db1->resetTransStatus();
        }

        $this->assertSame(0, $db1->transDepth, 'наша собственная транзакция обязана закрыться полностью');
        $this->assertTrue($db1->transStatus());

        // Ни одна вложенная итерация не top-level — обе видят уже отравленный флаг
        // сразу после своего SELECT и откатываются, не применив запись.
        $bankFirst = $this->bankRow($this->resourceId);
        $this->assertNotNull($bankFirst);
        $this->assertSame(10, (int) $bankFirst['resources_sold'], 'вложенная итерация не применяет запись под уже отравленным флагом');

        $bankSecond = $this->bankRow($secondResourceId);
        $this->assertNotNull($bankSecond);
        $this->assertSame(2, (int) $bankSecond['resources_sold'], 'вложенная итерация не применяет запись под уже отравленным флагом');
    }
}
