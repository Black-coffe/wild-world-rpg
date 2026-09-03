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
 * Схема — минимальная ручная (тот же паттерн, что и `ResourceBankInsertRaceTest`/
 * `ResourceTradeGoldRaceTest`, memory `feedback_test_schema_must_come_from_migration`
 * учтена частично: `resources_bank` несёт РЕАЛЬНЫЙ `UNIQUE KEY uniq_resource_id`
 * (`2026-09-02-130100_Adr181UniqueResourcesBank`), `resources` несёт `price` — колонку,
 * которую читает `ResourceBankUpdateHandler::process()` и которой не было в схеме
 * `ResourceBankInsertRaceTest` (тому набору она не требовалась)).
 *
 * @internal
 */
final class ResourceBankHotPathTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const RESOURCE_ID = 42;

    /** @var list<string> */
    private const TABLES = ['resources', 'resources_bank'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();
        $db = Database::connect('tests');

        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }

        // exploit-fix-32: `price` — колонка реальной миграции
        // `2024-03-21-224528_CreateResourcesTable`, читает её ResourceBankUpdateHandler::process().
        $db->query('
            CREATE TABLE resources (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(191) NOT NULL,
                name_en VARCHAR(191) NULL,
                icon_text VARCHAR(191) NULL,
                price INT NOT NULL DEFAULT 0,
                buy_price DECIMAL(18,2) NOT NULL DEFAULT 0,
                sell_price DECIMAL(18,2) NOT NULL DEFAULT 0,
                rarity INT NOT NULL DEFAULT 1,
                is_tradeable TINYINT NOT NULL DEFAULT 1
            )
        ');
        // exploit-fix-10: реальная форма индекса, накатанная миграцией
        // `2026-09-02-130100_Adr181UniqueResourcesBank` — предпосылка для этого набора
        // тестов не нужна сама по себе (акцент на increment-ветке, не на дубле строк),
        // но воспроизведена для соответствия продовой схеме один в один.
        $db->query('
            CREATE TABLE resources_bank (
                id INT AUTO_INCREMENT PRIMARY KEY,
                resource_id INT NOT NULL,
                current_quantity INT NOT NULL DEFAULT 0,
                resources_purchased INT NOT NULL DEFAULT 0,
                resources_sold INT NOT NULL DEFAULT 0,
                last_update DATETIME NULL,
                UNIQUE KEY uniq_resource_id (resource_id)
            )
        ');

        $db->table('resources')->insert([
            'id'           => self::RESOURCE_ID,
            'name'         => 'Ржавый лом',
            'name_en'      => 'rusty_scrap',
            'price'        => 5,
            'buy_price'    => 10.0,
            'sell_price'   => 4.0,
            'rarity'       => 1,
            'is_tradeable' => 1,
            'icon_text'    => '🔧',
        ]);
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $this->cleanCache();
        parent::tearDown();
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
            'resource_id'         => self::RESOURCE_ID,
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
            ->where('resource_id', self::RESOURCE_ID)->get()->getRowArray();
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

        $seenBeforeRace = $db1->table('resources_bank')->where('resource_id', self::RESOURCE_ID)->get()->getRowArray();
        $this->assertNotNull($seenBeforeRace);
        $this->assertSame(10, (int) $seenBeforeRace['resources_sold'], 'предусловие: снимок db1 видит только исходные 10');

        // "Второе соединение" — вторая сделка того же ресурса, целиком закоммичена
        // ВНУТРИ открытой транзакции db1 (acceptance criteria #1).
        $db2->query(
            'UPDATE resources_bank SET resources_sold = resources_sold + ? WHERE resource_id = ?',
            [7, self::RESOURCE_ID]
        );

        // Первая сделка (db1, всё ещё внутри своей открытой транзакции) бампает через
        // продакшн-метод — `increment()` внутри него делает locking read (InnoDB читает
        // последнюю зафиксированную версию, а не снимок db1), поэтому видит уже
        // закоммиченный бамп db2, а не теряет его перезаписью абсолютным значением.
        $outcome = (new ResourcesBankModel())->incrementCounterIfExists(self::RESOURCE_ID, 5, 'resources_sold');

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
     */
    public function testCronDoesNotEraseTradeCommittedBetweenReadAndWrite(): void
    {
        $this->seedBankRow(3, 0, 10);

        $db1 = Database::connect('tests');
        $db2 = Database::connect('tests', false);

        $db1->transBegin();

        $staleSnapshot = $db1->table('resources_bank')->where('resource_id', self::RESOURCE_ID)->get()->getRowArray();
        $this->assertNotNull($staleSnapshot);
        $this->assertSame(10, (int) $staleSnapshot['resources_sold'], 'предусловие: снимок db1 не видит ещё не случившийся трейд');

        // Трейд коммитится ПОСЛЕ снимка db1, но ДО записи крона — окно между чтением
        // и записью крона из Goal story.
        $db2->query(
            'UPDATE resources_bank SET resources_sold = resources_sold + ? WHERE resource_id = ?',
            [7, self::RESOURCE_ID]
        );

        (new ResourceBankUpdateHandler())->process(1);

        $db1->transCommit();

        $bank = $this->bankRow();
        $this->assertNotNull($bank);
        // Дефолтное состаривание (`economy.market.proportional_decay_enabled=false`) —
        // "-1" от значения, которое РЕАЛЬНО увидел крон. Увидел бы устаревший снимок
        // (10) — получили бы max(0, 10-1)=9, бамп трейда пропал бы. Увидел свежие 17
        // (10 + 7 трейда) — получаем 16.
        $this->assertSame(
            16,
            (int) $bank['resources_sold'],
            'крон обязан состарить СВЕЖЕЕ значение (17 = 10 + бамп трейда), не устаревший снимок db1 (10)'
        );
    }
}
