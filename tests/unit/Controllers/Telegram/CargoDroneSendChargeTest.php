<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers\Telegram;

use App\Controllers\Telegram\Commands\Actions\Drone\CargoDroneSendAction;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Telegram;

/**
 * exploit-fix-26 (R2-major) — заряд карго-дрона в ручной отправке (`CargoDroneSendAction`)
 * списывался безусловным `UPDATE` абсолютным значением, посчитанным из `$charge`,
 * прочитанного до транзакции (`find($logId)` в начале `handle()`): два параллельных вылета
 * по одному прочитанному заряду писали одно и то же уменьшенное число — второй вылет
 * оказывался бесплатным. Близнец {@see CargoDroneAutoSendChargeTest} для ручной отправки.
 *
 * Схема таблиц и приём воспроизведения гонки — тот же паттерн, что и в
 * `CargoDroneAutoSendChargeTest` (`Events::on('DBQuery', …)` перехватывает SELECT внутри
 * `CharacterResourceModel::decrementIfAtLeast()` и сразу после него симулирует уже
 * завершившийся конкурентный вылет, обнулив запас заряда ниже `drain`). Таблицы общего
 * стенда `wildworld_tests` не дропаются — чистятся только собственные строки этого теста.
 *
 * @internal
 */
final class CargoDroneSendChargeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    /** @var list<string> таблицы, которые СОЗДАЛ этот тест (и поэтому вправе дропнуть). */
    private array $createdTables = [];

    /** @var list<int> id персонажей, заведённых этим тестом. */
    private array $ownCharacterIds = [];

    /**
     * exploit-fix-41 (R5-minor, m5) — `telegram_users` не входит в CHAR_LINKED (там нет
     * колонки-владельца персонажа), но каждый вызов {@see seedCharacter()} и каждая
     * маркерная запись «после handle()» вставляют туда строку. tearDown() ниже удаляет
     * ровно эти id, а не всю таблицу — она общая с соседними воркерами.
     *
     * @var list<int>
     */
    private array $ownTelegramUserIds = [];

    /** Таблица → колонка-владелец персонажа, для чистки строк в общих таблицах. */
    private const CHAR_LINKED = [
        'characters'           => 'id',
        'character_resources'  => 'id_characters',
        'crafted_items_log'    => 'character_id',
        'base_storage'         => 'character_id',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (! defined('PHPUNIT_TESTSUITE')) {
            define('PHPUNIT_TESTSUITE', true);
        }
        // Нужен, чтобы App\Services\Telegram\Request коротил на фейковый ServerResponse вместо
        // реального HTTP (урок feedback_taskhandler_telegram_init_in_tests).
        new Telegram('123456:TEST-fake-token-for-tests', 'test_bot');

        $this->createTableIfMissing('telegram_users', '
            CREATE TABLE telegram_users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                telegram_id BIGINT NULL
            )
        ');
        $this->createTableIfMissing('characters', '
            CREATE TABLE characters (
                id INT AUTO_INCREMENT PRIMARY KEY,
                telegram_user_id INT NULL,
                name VARCHAR(64) NULL,
                gold INT NULL DEFAULT 0,
                health DECIMAL(10,2) NULL DEFAULT 100,
                tired DECIMAL(10,2) NULL DEFAULT 100,
                cell_number INT NULL,
                biome_id INT NULL,
                level INT NULL DEFAULT 1,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            ) AUTO_INCREMENT=' . random_int(3_000_000, 3_999_999));
        // AUTO_INCREMENT со случайного высокого значения — `characters` в общей БД отсутствует и
        // создаётся каждым прогоном заново, сдвиг нужен, чтобы не столкнуться с id из прошлых
        // прогонов в персистентных character-linked таблицах (см. CHAR_LINKED).
        $this->createTableIfMissing('resources', '
            CREATE TABLE resources (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(64) NULL,
                name_en VARCHAR(64) NULL,
                icon_text VARCHAR(8) NULL,
                type VARCHAR(64) NULL,
                weight DECIMAL(10,3) NULL DEFAULT 1,
                rarity INT NULL DEFAULT 1,
                price DECIMAL(10,2) NULL DEFAULT 1,
                is_tradeable TINYINT NULL DEFAULT 1,
                buy_price DECIMAL(10,2) NULL DEFAULT 0,
                sell_price DECIMAL(10,2) NULL DEFAULT 0
            )
        ');
        $this->createTableIfMissing('character_resources', '
            CREATE TABLE character_resources (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_characters INT NULL,
                id_resources INT NULL,
                id_telegram_users INT NULL,
                quantity INT NULL DEFAULT 0,
                custom_data TEXT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        $this->createTableIfMissing('crafted_items_log', '
            CREATE TABLE crafted_items_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                character_id INT NULL,
                task_id INT NULL,
                crafted_item_id INT NULL,
                type VARCHAR(100) NULL,
                direction_craft VARCHAR(100) NULL,
                crafting_location VARCHAR(255) NULL,
                durability_count INT NULL DEFAULT 0,
                durability_time DATE NULL,
                quantity INT NULL DEFAULT 1,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        $this->createTableIfMissing('base_storage', '
            CREATE TABLE base_storage (
                id INT AUTO_INCREMENT PRIMARY KEY,
                character_id INT NULL,
                resource_id INT NULL,
                quantity INT NULL DEFAULT 0,
                arrived_from_cell INT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');

        $cache = service('cache');
        if (is_object($cache) && method_exists($cache, 'clean')) {
            $cache->clean();
        }
    }

    protected function tearDown(): void
    {
        Events::removeAllListeners('DBQuery');
        // exploit-fix-41 — DBDebug=false персистирует на общем `tests`-соединении между
        // тестами (тот же урок, что и в InsertUniqueContractTest); всегда возвращаем
        // к дефолту, даже если конкретный тест не трогал его сам.
        $this->enableDBDebug();

        try {
            foreach (self::CHAR_LINKED as $table => $col) {
                if (in_array($table, $this->createdTables, true) || $this->ownCharacterIds === []) {
                    continue;
                }
                try {
                    $this->db()->table($table)->whereIn($col, $this->ownCharacterIds)->delete();
                } catch (\Throwable $e) {
                    // Таблица общая и уже не наша — не мешаем соседнему воркеру фаталом здесь.
                }
            }
            if (! in_array('telegram_users', $this->createdTables, true) && $this->ownTelegramUserIds !== []) {
                try {
                    $this->db()->table('telegram_users')->whereIn('id', $this->ownTelegramUserIds)->delete();
                } catch (\Throwable $e) {
                    // Таблица общая и уже не наша — не мешаем соседнему воркеру фаталом здесь.
                }
            }
        } finally {
            foreach (array_reverse($this->createdTables) as $t) {
                $this->db()->query("DROP TABLE IF EXISTS {$t}");
            }
        }

        parent::tearDown();
    }

    private function db(): BaseConnection
    {
        return Database::connect('tests');
    }

    private function createTableIfMissing(string $table, string $ddl): void
    {
        // exploit-fix-31 follow-up — `tableExists()` c дефолтным `$cached=true` держит список
        // имён таблиц в `BaseConnection::$dataCache` на весь процесс: на пустой CI-базе этот
        // же тест сам создаёт и дропает `telegram_users` в каждом `tearDown()`, но кеш об этом
        // не узнаёт — второй тестовый метод в том же файле видит «уже есть» по стухшему кешу,
        // ничего не создаёт, и первый же INSERT/SELECT падает на «таблица не существует». На
        // персистентной `wildworld_tests` баг незаметен: таблица там мигрирована один раз и
        // никогда не дропается этим тестом. `$cached=false` бьёт по БД каждый раз, но таблиц
        // в списке немного и это только тестовый фикстур-путь.
        if (! $this->db()->tableExists($table, false)) {
            $this->db()->query($ddl);
            $this->createdTables[] = $table;
        }
    }

    private function ensureResource(string $name, string $nameEn): int
    {
        $row = $this->db()->table('resources')->where('name_en', $nameEn)->orderBy('id', 'ASC')->limit(1)->get();
        $arr = $row === false ? null : $row->getRowArray();
        if (is_array($arr) && isset($arr['id'])) {
            return (int) $arr['id'];
        }

        $this->db()->table('resources')->insert([
            'name' => $name, 'name_en' => $nameEn, 'type' => 'crafting',
            'weight' => 1, 'rarity' => 1, 'price' => 1,
        ]);

        return (int) $this->db()->insertID();
    }

    private function backpackQty(int $charId, int $resId): int
    {
        $row = $this->db()->table('character_resources')
            ->where('id_characters', $charId)
            ->where('id_resources', $resId)
            ->get();
        $arr = $row === false ? null : $row->getRowArray();

        return is_array($arr) && is_numeric($arr['quantity'] ?? null) ? (int) $arr['quantity'] : 0;
    }

    /** Вставляет строку telegram_users и запоминает id для чистки в tearDown() (m5). */
    private function insertTelegramUser(int $tgId): int
    {
        $this->db()->table('telegram_users')->insert(['telegram_id' => $tgId]);
        $id = (int) $this->db()->insertID();
        $this->ownTelegramUserIds[] = $id;

        return $id;
    }

    /** @return array{0:int,1:int} [telegram_id, character_id] */
    private function seedCharacter(): array
    {
        $tgId  = random_int(730_000_000, 739_999_999);
        $tgUid = $this->insertTelegramUser($tgId);

        $this->db()->table('characters')->insert([
            'telegram_user_id' => $tgUid, 'gold' => 0, 'health' => 100, 'tired' => 100,
        ]);
        $charId = (int) $this->db()->insertID();
        $this->ownCharacterIds[] = $charId;

        return [$tgId, $charId];
    }

    private function seedCargoDrone(int $charId, int $durability): int
    {
        $this->db()->table('crafted_items_log')->insert([
            'character_id' => $charId, 'crafted_item_id' => 1, 'quantity' => 1,
            'durability_count' => $durability,
        ]);

        return (int) $this->db()->insertID();
    }

    /** Новая CallbackQuery на каждый вызов handle() — как в CargoDroneAutoSendChargeTest. */
    private function cbq(int $tgId, string $data): CallbackQuery
    {
        return new CallbackQuery([
            'id'   => 'cbq_' . random_int(1, 999999999),
            'from' => ['id' => $tgId, 'is_bot' => false, 'first_name' => 'Тест'],
            'message' => [
                'message_id' => 1, 'date' => time(),
                'chat' => ['id' => $tgId, 'type' => 'private'],
                'text' => 'placeholder',
            ],
            'chat_instance' => 'ci_' . $tgId,
            'data' => $data,
        ]);
    }

    public function testSuccessfulSendDrainsChargeExactlyOnce(): void
    {
        [$tgId, $charId] = $this->seedCharacter();
        $woodId = $this->ensureResource('Древесина', 'Wood');

        $this->db()->table('character_resources')->insert([
            'id_characters' => $charId, 'id_resources' => $woodId, 'quantity' => 5,
        ]);
        $logId = $this->seedCargoDrone($charId, 250);

        $response = (new CargoDroneSendAction($this->cbq($tgId, "cargoDroneSend_{$logId}_{$woodId}")))->handle();

        $this->assertInstanceOf(ServerResponse::class, $response);

        $log = $this->db()->table('crafted_items_log')->where('id', $logId)->get()->getRowArray();
        $this->assertIsArray($log);
        $this->assertSame(150, (int) $log['durability_count'], 'заряд списан ровно один раз (default drain=100)');
        $this->assertSame(0, $this->backpackQty($charId, $woodId), 'весь запас уехал на склад');

        $storageRow = $this->db()->table('base_storage')
            ->where('character_id', $charId)->where('resource_id', $woodId)->get()->getRowArray();
        $this->assertIsArray($storageRow, 'груз реально доставлен на склад');
        $this->assertSame(5, (int) $storageRow['quantity']);
    }

    /**
     * exploit-fix-26 (R2-major) — два вылета по одному прочитанному заряду: второй должен
     * получить отказ, а не списать доставку без оплаты зарядом. Гонка воспроизведена тем же
     * приёмом, что и в `CargoDroneAutoSendChargeTest`: колбэк на `DBQuery` перехватывает
     * WRITE-запрос (`UPDATE`/`DELETE ... character_resources`) внутри
     * `CharacterResourceModel::decrementIfAtLeast()` — не SELECT, см. докблок метода-близнеца
     * в `CargoDroneAutoSendChargeTest` про искажение `$this->resultID` при перехвате READ — и
     * сразу после него пишет `crafted_items_log.durability_count` ниже `drain` — состояние,
     * которое оставил бы уже завершившийся конкурентный вылет.
     */
    public function testConcurrentChargeDrainRefusesSecondLaunchAndRollsBackDelivery(): void
    {
        [$tgId, $charId] = $this->seedCharacter();
        $woodId = $this->ensureResource('Древесина', 'Wood');

        $this->db()->table('character_resources')->insert([
            'id_characters' => $charId, 'id_resources' => $woodId, 'quantity' => 5,
        ]);
        $logId = $this->seedCargoDrone($charId, 150);

        // Отдельное, НЕ разделяемое соединение (getShared=false) — своя сессия с автокоммитом,
        // не участвующая в транзакции handle(). Без этого запись из колбэка шла бы по ТОЙ ЖЕ
        // связи, что и вся транзакция action'а, — и откатывалась бы вместе с ней, а не осталась
        // бы, как оставила бы уже зафиксировавшаяся отдельная транзакция конкурента.
        $concurrentDb = Database::connect('tests', false);

        $logIdForHook = $logId;
        Events::on('DBQuery', function (Query $query) use ($logIdForHook, $concurrentDb): void {
            $sql = $query->getOriginalQuery();
            // ConditionalWriteService собирает UPDATE/DELETE через prefixTable() — сырой SQL без
            // бэктиков вокруг имени таблицы, поэтому фильтр здесь без бэктиков.
            $isResourceWrite = str_contains($sql, 'character_resources')
                && (str_starts_with($sql, 'UPDATE') || str_starts_with($sql, 'DELETE'));
            if (! $isResourceWrite) {
                return;
            }
            // Симулируем уже завершившийся конкурентный вылет: заряд просел ниже drain (100)
            // между чтением $charge в начале handle() и условным списанием ниже. Пишем через
            // отдельное соединение — эффект остаётся, даже если основная транзакция откатится.
            $concurrentDb->table('crafted_items_log')
                ->where('id', $logIdForHook)
                ->update(['durability_count' => 50]);
        });

        $response = (new CargoDroneSendAction($this->cbq($tgId, "cargoDroneSend_{$logId}_{$woodId}")))->handle();

        $concurrentDb->close();

        $this->assertInstanceOf(ServerResponse::class, $response);
        $replyText = $response->getResult() instanceof \Longman\TelegramBot\Entities\Message
            ? $response->getResult()->getText()
            : null;
        $this->assertIsString($replyText);
        $this->assertStringContainsString('Заряд', $replyText, 'отказ по заряду обязан называть заряд причиной, а не рюкзак/ресурс (эталон для CargoDroneAutoSendAction, exploit-fix-31 m1)');

        $log = $this->db()->table('crafted_items_log')->where('id', $logId)->get()->getRowArray();
        $this->assertIsArray($log);
        $this->assertSame(50, (int) $log['durability_count'], 'заряд списан конкурентом ровно один раз — этот вылет своего списания не добавил');
        $this->assertSame(5, $this->backpackQty($charId, $woodId), 'списание рюкзака откатилось вместе с отказом по заряду');

        $storageRow = $this->db()->table('base_storage')
            ->where('character_id', $charId)->where('resource_id', $woodId)->get()->getRowArray();
        $this->assertNull($storageRow, 'доставка на склад откатилась — второй вылет не состоялся');
    }

    /**
     * exploit-fix-31 (R3-critical) — до правки на пути `$outcome !== WriteOutcome::Applied`
     * (`Refused`/`Missing` при списании ресурса) `handle()` не звал ни `transComplete()`, ни
     * `transRollback()` вовсе: `transStart()` уже открыл транзакцию глубиной 1, и она
     * оставалась висеть после `return`. `BotController::finally` пишет `last_seen` и строку
     * firehose НА ТОМ ЖЕ соединении сразу после — обе записи попадали в чужую незакрытую
     * транзакцию и терялись при следующем открытии (`transStart()` следующего запроса) или
     * при обрыве соединения.
     *
     * Гонка на уровне ресурса воспроизведена тем же приёмом, что и в
     * `CargoDroneAutoSendChargeTest::testAllDecrementsRefusedAfterPlanLeavesChargeAndLogUntouched()`:
     * колбэк на `DBQuery` перехватывает builder-`SELECT` внутри
     * `CharacterResourceModel::decrementIfAtLeast()` (`where(...)->first()`) и обнуляет остаток
     * ПОСЛЕ того, как строка найдена, но ДО условного `UPDATE ... WHERE quantity >= ?` — тот
     * же снимок, что оставил бы конкурентный писатель, потративший ресурс в промежутке между
     * чтением `$invQty` (до `transStart()`) и условным списанием (внутри неё). Это ведёт в
     * `WriteOutcome::Refused`, тот самый путь, где раньше не звалось ничего.
     */
    public function testResourceRefusalClosesTransactionSoLaterWritesSurvive(): void
    {
        [$tgId, $charId] = $this->seedCharacter();
        $woodId = $this->ensureResource('Древесина', 'Wood');

        $this->db()->table('character_resources')->insert([
            'id_characters' => $charId, 'id_resources' => $woodId, 'quantity' => 5,
        ]);
        $logId = $this->seedCargoDrone($charId, 250);

        $charIdForHook = $charId;
        $woodIdForHook = $woodId;
        Events::on('DBQuery', function (Query $query) use ($charIdForHook, $woodIdForHook): void {
            $sql = $query->getOriginalQuery();
            if (! str_contains($sql, 'FROM `character_resources`') || ! str_contains($sql, '`id_characters`')) {
                return;
            }
            $this->db()->table('character_resources')
                ->where('id_characters', $charIdForHook)
                ->where('id_resources', $woodIdForHook)
                ->update(['quantity' => 0]);
        });

        $response = (new CargoDroneSendAction($this->cbq($tgId, "cargoDroneSend_{$logId}_{$woodId}")))->handle();

        Events::removeAllListeners('DBQuery');

        $this->assertInstanceOf(ServerResponse::class, $response);
        $this->assertSame(
            0,
            $this->db()->transDepth,
            'транзакция обязана закрыться на пути отказа по ресурсу — глубина 0 после handle()'
        );

        $log = $this->db()->table('crafted_items_log')->where('id', $logId)->get()->getRowArray();
        $this->assertIsArray($log);
        $this->assertSame(250, (int) $log['durability_count'], 'заряд не тронут — списание ресурса отказало раньше');

        // Запись сразу после handle() на ТОМ ЖЕ соединении — как BotController::finally
        // пишет last_seen/firehose. Если бы транзакция осталась висеть открытой, эта запись
        // ушла бы в неё же и была бы невидима другому соединению до незапланированного
        // коммита/отката.
        $markerId = $this->insertTelegramUser($tgId);

        $secondDb = Database::connect('tests', false);
        $visible  = $secondDb->table('telegram_users')->where('id', $markerId)->get()->getRowArray();
        $secondDb->close();

        $this->assertIsArray($visible, 'запись, сделанная после handle(), обязана пережить конец «запроса» — видна второму соединению');
    }

    /**
     * exploit-fix-36 (R4-major) — исключение между `transStart()` и завершением (любой запрос
     * тела: `decrementIfAtLeast()`/`deliver()`/`ConditionalWriteService`) раньше пробрасывалось
     * мимо `transRollback()` — транзакция оставалась открытой на глубине 1, тот же класс потери,
     * что exploit-fix-31 закрыл для веток отказа по `WriteOutcome`. Исключение воспроизведено
     * искусственно: `DBQuery`-хук бросает на конкретном запросе — `INSERT`/`UPDATE` в
     * `base_storage` внутри `BaseStorageModel::deliver()`, который выполняется уже ПОСЛЕ
     * условного списания ресурса из рюкзака, но ДО списания заряда дрона.
     */
    public function testExceptionDuringTransactionRollsBackAndClosesTransaction(): void
    {
        [$tgId, $charId] = $this->seedCharacter();
        $woodId = $this->ensureResource('Древесина', 'Wood');

        $this->db()->table('character_resources')->insert([
            'id_characters' => $charId, 'id_resources' => $woodId, 'quantity' => 5,
        ]);
        $logId = $this->seedCargoDrone($charId, 250);

        Events::on('DBQuery', function (Query $query): void {
            $sql = $query->getOriginalQuery();
            $isStorageWrite = str_contains($sql, 'base_storage')
                && (str_starts_with($sql, 'INSERT') || str_starts_with($sql, 'UPDATE'));
            if ($isStorageWrite) {
                throw new \RuntimeException('exploit-fix-36: искусственный сбой внутри транзакции дрона');
            }
        });

        $caught = null;
        try {
            (new CargoDroneSendAction($this->cbq($tgId, "cargoDroneSend_{$logId}_{$woodId}")))->handle();
        } catch (\Throwable $e) {
            $caught = $e;
        }

        Events::removeAllListeners('DBQuery');

        $this->assertInstanceOf(\RuntimeException::class, $caught, 'исключение обязано быть доставлено вызывающему, а не проглочено');
        $this->assertSame('exploit-fix-36: искусственный сбой внутри транзакции дрона', $caught->getMessage());

        $this->assertSame(0, $this->db()->transDepth, 'транзакция обязана закрыться на пути исключения — глубина 0 после handle()');
        $this->assertTrue($this->db()->transStatus(), 'resetTransStatus() обязан вернуть флаг успеха, иначе следующий transStart() того же соединения унаследует чужой сбой');

        $this->assertSame(5, $this->backpackQty($charId, $woodId), 'списание рюкзака откатилось вместе с исключением');

        $log = $this->db()->table('crafted_items_log')->where('id', $logId)->get()->getRowArray();
        $this->assertIsArray($log);
        $this->assertSame(250, (int) $log['durability_count'], 'заряд не тронут — исключение случилось раньше его списания');

        $storageRow = $this->db()->table('base_storage')
            ->where('character_id', $charId)->where('resource_id', $woodId)->get()->getRowArray();
        $this->assertNull($storageRow, 'доставка на склад откатилась вместе с исключением');

        // Запись сразу после handle() на ТОМ ЖЕ соединении — как BotController::finally пишет
        // last_seen/firehose. Если бы транзакция осталась висеть открытой, эта запись ушла бы в
        // неё же и была бы невидима другому соединению до незапланированного коммита/отката.
        $markerId = $this->insertTelegramUser($tgId);

        $secondDb = Database::connect('tests', false);
        $visible  = $secondDb->table('telegram_users')->where('id', $markerId)->get()->getRowArray();
        $secondDb->close();

        $this->assertIsArray($visible, 'запись, сделанная после handle(), обязана пережить конец «запроса» — видна второму соединению');
    }

    /**
     * exploit-fix-41 (R5-minor, m6) — путь «запрос упал молча, исключения нет»: story 36
     * закрыла только путь исключения (`catch (\Throwable $e)`), но `transComplete()` может
     * вернуть `false` без единого брошенного исключения — при `DBDebug=false` неудачный
     * запрос помечает `transStatus=false` (`BaseConnection::handleTransStatus()`) и просто
     * возвращает `false`, а `transStrict=true` (`Config\Database`) держит `transStatus=false`
     * до явного `resetTransStatus()`.
     *
     * Тихий отказ воспроизведён реальной SQL-ошибкой (обращение к несуществующей колонке)
     * внутри транзакции handle(), на ТОМ ЖЕ соединении — `DBDebug=false` гарантирует
     * отсутствие исключения независимо от глубины транзакции (в отличие от `DBDebug=true`,
     * где внутри транзакции по умолчанию тоже не бросает, но это не тот путь, который
     * назвал ревьюер). `DBQuery`-хук ставит флаг `$injected`, чтобы не зациклиться на
     * собственном же инжектированном запросе. Перехват — на WRITE-запросе (`INSERT` в
     * `base_storage` внутри `BaseStorageModel::deliver()`), не на SELECT: как объясняет
     * докблок близнеца в `CargoDroneAutoSendChargeTest`, колбэк-запрос на том же
     * соединении переписывает `$this->resultID` ДО того, как исходный `SELECT` успевает
     * его прочитать, — искажение измерения, а не воспроизведение сценария ревьюера. Для
     * write-типа `query()` этой ветки не касается.
     */
    public function testSilentTransactionFailureWithoutExceptionResetsTransStatus(): void
    {
        [$tgId, $charId] = $this->seedCharacter();
        $woodId = $this->ensureResource('Древесина', 'Wood');

        $this->db()->table('character_resources')->insert([
            'id_characters' => $charId, 'id_resources' => $woodId, 'quantity' => 5,
        ]);
        $logId = $this->seedCargoDrone($charId, 250);

        $this->disableDBDebug();

        $injected = false;
        Events::on('DBQuery', function (Query $query) use (&$injected): void {
            if ($injected) {
                return;
            }
            $sql = $query->getOriginalQuery();
            $isStorageWrite = str_contains($sql, 'base_storage')
                && (str_starts_with($sql, 'INSERT') || str_starts_with($sql, 'UPDATE'));
            if (! $isStorageWrite) {
                return;
            }
            $injected = true;
            // Тихий отказ: колонка не существует → resultID=false → handleTransStatus()
            // помечает transStatus=false. DBDebug=false — без исключения, безусловно.
            $this->db()->query('UPDATE crafted_items_log SET no_such_column = 1 WHERE id = -1');
        });

        $response = (new CargoDroneSendAction($this->cbq($tgId, "cargoDroneSend_{$logId}_{$woodId}")))->handle();

        Events::removeAllListeners('DBQuery');
        $this->enableDBDebug();

        $this->assertTrue($injected, 'хук обязан был поймать и подменить целевой запрос — иначе тест ничего не проверил');

        $this->assertInstanceOf(ServerResponse::class, $response);
        $replyText = $response->getResult() instanceof \Longman\TelegramBot\Entities\Message
            ? $response->getResult()->getText()
            : null;
        $this->assertIsString($replyText);
        $this->assertStringContainsString('откатилась', $replyText, 'игрок обязан получить отказ на тихо упавшем запросе');

        $this->assertSame(0, $this->db()->transDepth, 'транзакция обязана закрыться на тихом отказе — глубина 0 после handle()');
        $this->assertTrue(
            $this->db()->transStatus(),
            'resetTransStatus() обязан вернуть флаг успеха после тихого (без исключения) отказа — иначе следующий transStart() того же соединения унаследует чужой сбой'
        );

        $this->assertSame(5, $this->backpackQty($charId, $woodId), 'списание рюкзака откатилось вместе с тихим отказом');

        $log = $this->db()->table('crafted_items_log')->where('id', $logId)->get()->getRowArray();
        $this->assertIsArray($log);
        $this->assertSame(250, (int) $log['durability_count'], 'заряд не тронут — вся транзакция откатилась');

        // Запись сразу после handle() на ТОМ ЖЕ соединении — как BotController::finally пишет
        // last_seen/firehose. Если бы транзакция осталась в поломанном состоянии, эта запись
        // ушла бы в откатывающуюся транзакцию или сама бы отказала.
        $markerId = $this->insertTelegramUser($tgId);

        $secondDb = Database::connect('tests', false);
        $visible  = $secondDb->table('telegram_users')->where('id', $markerId)->get()->getRowArray();
        $secondDb->close();

        $this->assertIsArray($visible, 'запись, сделанная после handle(), обязана пережить конец «запроса» — видна второму соединению');
    }
}
