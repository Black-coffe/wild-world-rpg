<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Player\Trade;

use App\Models\ResourcesBankModel;
use App\Services\Db\WriteOutcome;
use App\Services\Player\Trade\ResourceTradeService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use ReflectionClass;

/**
 * exploit-fix-16 (ADR-181 §M3) — `resources_bank` несёт `UNIQUE(resource_id)` (story 10,
 * `2026-09-02-130100_Adr181UniqueResourcesBank`). Обе точки, создающие строку банка
 * (`ResourceTradeService::sellResource()` и `bumpBankSold()`, вызываемый из
 * `bulkSellResources()`), раньше делали `first()` → `insert()` без `try/catch` внутри
 * транзакции сделки: два одновременных первых трейда нового ресурса гонятся за одной
 * строкой и второй ловил MySQL 1062 → необработанный `DatabaseException` → 500 у живого
 * игрока. После фикса обе точки идут через `createOrBumpBank()` →
 * `ConditionalWriteService::insertUnique()`; на `Refused` (конкурент уже создал строку
 * между проверкой вызывающего и вставкой) строка перечитывается и обновляется штатно.
 *
 * Схема таблиц — минимальная ручная (паттерн `BulkSellResourcesTest`/
 * `ResourceTradeGoldRaceTest`, memory `feedback_test_schema_must_come_from_migration`
 * учтена частично: `resources_bank` несёт РЕАЛЬНЫЙ `UNIQUE KEY uniq_resource_id` один в
 * один с миграцией story 10 — именно этот индекс и есть предмет проверки, остальные
 * таблицы — вспомогательная обвязка, которую `ResourceTradeService` требует для чтения
 * персонажа/инвентаря/цены).
 *
 * Реальную гонку двух соединений (TOCTOU-окно между `where()->first()` вызывающего и
 * `insertUnique()` внутри `createOrBumpBank()`) детерминированно доказывает прямой вызов
 * приватного `createOrBumpBank()` через reflection на уже существующей строке банка —
 * ровно то состояние, которое видела бы вставка, окажись она второй. Одновременно
 * `insertUnique()` реально коллидирует с самим UNIQUE-индексом на РЕАЛЬНОЙ MySQL, а не с
 * тест-двойником (в отличие от `RelocationConfirmOutcomeTest`, где форма индекса на
 * `character_tasks` коллизию не давала — см. докблок того класса).
 *
 * @internal
 */
final class ResourceBankInsertRaceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const CHAR_ID     = 771;
    private const RESOURCE_ID = 42;

    /** @var list<string> */
    private const TABLES = ['characters', 'character_resources', 'resources', 'resources_bank'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();
        $db = Database::connect('tests');

        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }

        $db->query('
            CREATE TABLE characters (
                id INT AUTO_INCREMENT PRIMARY KEY,
                gold DECIMAL(18,2) NOT NULL DEFAULT 0,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        $db->query('
            CREATE TABLE character_resources (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_characters INT NOT NULL,
                id_resources INT NOT NULL,
                quantity INT NOT NULL DEFAULT 0,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        $db->query('
            CREATE TABLE resources (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(191) NOT NULL,
                name_en VARCHAR(191) NULL,
                icon_text VARCHAR(191) NULL,
                buy_price DECIMAL(18,2) NOT NULL DEFAULT 0,
                sell_price DECIMAL(18,2) NOT NULL DEFAULT 0,
                rarity INT NOT NULL DEFAULT 1,
                is_tradeable TINYINT NOT NULL DEFAULT 1
            )
        ');
        // exploit-fix-10: реальная форма индекса, накатанная миграцией
        // `2026-09-02-130100_Adr181UniqueResourcesBank` — это и есть предмет проверки.
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

    private function seedCharacter(int $id, float $gold): void
    {
        Database::connect('tests')->table('characters')->insert(['id' => $id, 'gold' => $gold]);
    }

    private function giveResource(int $charId, int $resourceId, int $qty): void
    {
        Database::connect('tests')->table('character_resources')->insert([
            'id_characters' => $charId, 'id_resources' => $resourceId, 'quantity' => $qty,
        ]);
    }

    private function bankRowsForResource(int $resourceId): int
    {
        return (int) Database::connect('tests')->table('resources_bank')
            ->where('resource_id', $resourceId)->countAllResults();
    }

    /** @return array<string,mixed>|null */
    private function bankRow(int $resourceId): ?array
    {
        return Database::connect('tests')->table('resources_bank')
            ->where('resource_id', $resourceId)->get()->getRowArray();
    }

    /**
     * Вызывающий `sellResource()`/`bumpBankSold()` частный метод, который и создаёт
     * строку банка через `insertUnique()`. Reflection — тот же паттерн, что уже устоялся
     * в этом наборе тестов (`RelocationConfirmOutcomeTest`), для доступа к приватной
     * реализации без раздувания публичного API сервиса.
     */
    private function callCreateOrBumpBank(ResourceTradeService $service, int $resourceId, int $qty): void
    {
        $method = (new ReflectionClass($service))->getMethod('createOrBumpBank');
        $method->setAccessible(true);
        $method->invoke($service, $resourceId, $qty);
    }

    /**
     * Первая продажа ресурса: строки в `resources_bank` ещё нет — вставка идёт через
     * `insertUnique()` и получает `Applied`. Сделка успешна, ровно одна строка банка.
     */
    public function testSellResourceCreatesBankRowOnFirstTradeViaInsertUnique(): void
    {
        $this->seedCharacter(self::CHAR_ID, 0.0);
        $this->giveResource(self::CHAR_ID, self::RESOURCE_ID, 12);

        $result = (new ResourceTradeService())->sellResource(
            ['id' => self::CHAR_ID, 'gold' => 0],
            self::RESOURCE_ID,
            5
        );

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame(1, $this->bankRowsForResource(self::RESOURCE_ID));
        $bank = $this->bankRow(self::RESOURCE_ID);
        $this->assertNotNull($bank);
        $this->assertSame(5, (int) $bank['resources_sold']);
    }

    /**
     * ЯДРО РЕГРЕССИИ: строка банка уже существует в момент, когда `createOrBumpBank()`
     * пытается её создать — ровно состояние, которое застаёт проигравшая сторона гонки
     * первого трейда (конкурент вставил строку в TOCTOU-окне между `where()->first()`
     * вызывающего и `insertUnique()`). `insertUnique()` реально коллидирует с
     * `UNIQUE(resource_id)` на MySQL и возвращает `Refused` — метод обязан не бросить
     * исключение, перечитать строку и обновить счётчик, учтя продажу ровно один раз.
     */
    public function testCreateOrBumpBankSurvivesRowCreatedConcurrentlyViaInsertUnique(): void
    {
        // "Второе соединение" уже создало строку банка раньше проверки этого вызова.
        Database::connect('tests')->table('resources_bank')->insert([
            'resource_id'         => self::RESOURCE_ID,
            'current_quantity'    => 3,
            'resources_purchased' => 0,
            'resources_sold'      => 10,
            'last_update'         => date('Y-m-d H:i:s'),
        ]);

        $service = new ResourceTradeService();

        $this->callCreateOrBumpBank($service, self::RESOURCE_ID, 5);

        $this->assertSame(
            1,
            $this->bankRowsForResource(self::RESOURCE_ID),
            'insertUnique() Refused не должен был создать вторую строку'
        );
        $bank = $this->bankRow(self::RESOURCE_ID);
        $this->assertNotNull($bank);
        $this->assertSame(
            15,
            (int) $bank['resources_sold'],
            'продажа обязана быть учтена ровно один раз (10 существующих + 5 новых)'
        );
        $this->assertSame(
            3,
            (int) $bank['current_quantity'],
            'перечитывание строки на Refused не должно затирать чужие счётчики банка'
        );
    }

    /**
     * Второй вызывающий (`bumpBankSold()`, путь оптовой продажи) идёт через тот же
     * `createOrBumpBank()` — первая продажа при пустом банке тоже проходит без ошибки.
     */
    public function testBulkSellResourcesCreatesBankRowThroughSamePrimitive(): void
    {
        $this->seedCharacter(self::CHAR_ID, 0.0);
        $this->giveResource(self::CHAR_ID, self::RESOURCE_ID, 20);

        $result = (new ResourceTradeService())->bulkSellResources(
            ['id' => self::CHAR_ID, 'gold' => 0],
            100
        );

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame(1, $this->bankRowsForResource(self::RESOURCE_ID));
        $bank = $this->bankRow(self::RESOURCE_ID);
        $this->assertNotNull($bank);
        $this->assertSame(20, (int) $bank['resources_sold']);
    }

    /**
     * Третья точка (team-lead delta 2026-09-03): `buyResource()` →
     * `ResourcesBankModel::updatePurchasedQuantity()` → `createOrBumpCounter()`. Первая
     * покупка ресурса при пустом банке создаёт строку через `insertUnique()`; сохраняем
     * унаследованную семантику покупки — `current_quantity` новой строки равен купленному
     * количеству (не 0, как у продажи, Non-goals story 16: формулы не трогать).
     */
    public function testBuyResourceCreatesBankRowOnFirstPurchaseViaInsertUnique(): void
    {
        $this->seedCharacter(self::CHAR_ID, 1000.0);

        $result = (new ResourceTradeService())->buyResource(
            ['id' => self::CHAR_ID, 'gold' => 1000, 'level' => 1],
            self::RESOURCE_ID,
            4
        );

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame(1, $this->bankRowsForResource(self::RESOURCE_ID));
        $bank = $this->bankRow(self::RESOURCE_ID);
        $this->assertNotNull($bank);
        $this->assertSame(4, (int) $bank['resources_purchased']);
        $this->assertSame(4, (int) $bank['current_quantity'], 'первая покупка заводит current_quantity = qty, как и раньше');
    }

    /**
     * ЯДРО РЕГРЕССИИ покупки: строка банка уже существует (конкурент создал её в
     * TOCTOU-окне перед этой покупкой) — `updatePurchasedQuantity()` обязан не бросить
     * исключение на реальной коллизии с `UNIQUE(resource_id)`, а учесть покупку один раз
     * поверх чужой строки, не трогая `resources_sold`/`current_quantity` конкурента.
     */
    public function testBuyResourceSurvivesRowCreatedConcurrentlyForPurchasedCounter(): void
    {
        $this->seedCharacter(self::CHAR_ID, 1000.0);

        Database::connect('tests')->table('resources_bank')->insert([
            'resource_id'         => self::RESOURCE_ID,
            'current_quantity'    => 9,
            'resources_purchased' => 4,
            'resources_sold'      => 6,
            'last_update'         => date('Y-m-d H:i:s'),
        ]);

        $result = (new ResourceTradeService())->buyResource(
            ['id' => self::CHAR_ID, 'gold' => 1000, 'level' => 1],
            self::RESOURCE_ID,
            3
        );

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame(
            1,
            $this->bankRowsForResource(self::RESOURCE_ID),
            'insertUnique() Refused не должен был создать вторую строку'
        );
        $bank = $this->bankRow(self::RESOURCE_ID);
        $this->assertNotNull($bank);
        $this->assertSame(7, (int) $bank['resources_purchased'], 'покупка учтена один раз (4 существующих + 3 новых)');
        $this->assertSame(6, (int) $bank['resources_sold'], 'обновление счётчика покупок не должно трогать чужой resources_sold');
        $this->assertSame(9, (int) $bank['current_quantity'], 'перечитывание строки на Refused не должно затирать чужой current_quantity');
    }

    /**
     * exploit-fix-25 (R2-major reviewer 1) — ЯДРО ЭТОЙ STORY: двумя РЕАЛЬНЫМИ соединениями,
     * не reflection-подстановкой готового состояния, как выше. Первое соединение открывает
     * транзакцию (как `bulkSellResources()`) и снимает снимок REPEATABLE READ ПУСТОЙ
     * таблицы `SELECT`-ом до того, как второе соединение вставляет и коммитит строку банка.
     * Старый код (`where()->first()` на `Refused`) в этот момент читал бы по снимку и не
     * увидел бы строку конкурента вовсе — бамп терялся молча. `increment()` — `UPDATE`,
     * который в InnoDB читает последнюю зафиксированную версию (locking read), а не снимок
     * транзакции, поэтому видит строку конкурента независимо от снимка. Проверка исхода —
     * после `transCommit()` первого соединения, как требует acceptance criteria story.
     */
    public function testCreateOrBumpCounterSurvivesRealTwoConnectionRepeatableReadRace(): void
    {
        $db1 = Database::connect('tests');        // соединение, которым пользуется модель ниже (defaultGroup='tests' в testing)
        $db2 = Database::connect('tests', false);  // независимое второе соединение — отдельный mysqli-линк

        $db1->transBegin();

        // Снимаем снимок REPEATABLE READ на db1: таблица сейчас пуста для этого ресурса.
        $seenBeforeRace = $db1->table('resources_bank')->where('resource_id', self::RESOURCE_ID)->countAllResults();
        $this->assertSame(0, $seenBeforeRace, 'предусловие: до гонки строки банка ещё нет');

        // "Второе соединение" создаёт строку банка и коммитит вне снимка db1.
        $db2->table('resources_bank')->insert([
            'resource_id'         => self::RESOURCE_ID,
            'current_quantity'    => 3,
            'resources_purchased' => 0,
            'resources_sold'      => 10,
            'last_update'         => date('Y-m-d H:i:s'),
        ]);

        // Внутри транзакции db1 (снимок не видит строку конкурента) — insertUnique() ловит
        // Refused через реальную коллизию UNIQUE(resource_id), не через снимок.
        $outcome = (new ResourcesBankModel())->createOrBumpCounter(self::RESOURCE_ID, 5, 'resources_sold');

        $db1->transCommit();

        $this->assertSame(WriteOutcome::Applied, $outcome, 'increment() обязан применить бамп, а не потеряться на снимке');
        $this->assertSame(
            1,
            $this->bankRowsForResource(self::RESOURCE_ID),
            'Refused не должен был создать вторую строку'
        );
        $bank = $this->bankRow(self::RESOURCE_ID);
        $this->assertNotNull($bank);
        $this->assertSame(
            15,
            (int) $bank['resources_sold'],
            'бамп конкурента (10) + новый (5) виден после transComplete() — не потерян снимком db1'
        );
        $this->assertSame(
            3,
            (int) $bank['current_quantity'],
            'increment() не трогает чужие поля строки'
        );
    }
}
