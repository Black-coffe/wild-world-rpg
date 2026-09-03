<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers\Telegram;

use App\Controllers\Telegram\Commands\Actions\Drone\CargoDroneAutoSendAction;
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
 * exploit-fix-15 (M1) — заряд карго-дрона в автовывозе (`CargoDroneAutoSendAction`)
 * списывался безусловно и до проверки `$delivered`, вне ветки успеха — в отличие от
 * образца `CargoDroneSendAction`, который списывает заряд только внутри
 * `WriteOutcome::Applied`. После правки `durability_count` и `crafted_items_log`
 * трогаются только если хотя бы один предмет реально уехал.
 *
 * `CargoAutoLoadService::plan()` читает `character_resources` ДО открытия транзакции
 * (`:100` в actione), а сам `decrementIfAtLeast()` — уже ВНУТРИ неё, отдельным SELECT+UPDATE
 * (`CharacterResourceModel::decrementIfAtLeast()`). Между этими двумя чтениями честная гонка
 * возможна только при параллельном писателе — не симулируема тайминг-ожиданием в
 * последовательном PHPUnit (тот же вывод, что и `CancelQueuedCraftConditionalDeleteTest`).
 * Здесь она воспроизведена детерминированно через `Events::on('DBQuery', …)`: колбэк
 * перехватывает ИМЕННО SQL-запрос `CargoAutoLoadService::plan()` (сигнатура JOIN +
 * ORDER BY) и сразу после него, но ДО вызова `decrementIfAtLeast()`, обнуляет остаток в
 * `character_resources` — ровно то состояние, которое оставил бы конкурентный писатель,
 * успевший потратить ресурс в промежутке между чтением плана и условным списанием.
 *
 * Схема таблиц — минимальный набор колонок, которые реально трогают
 * `CargoDroneAutoSendAction`, `CargoAutoLoadService`, `BaseStorageModel::deliver()` и
 * `BaseAction::getUserAndCharacter()` (тот же паттерн DDL, что и
 * `CancelQueuedCraftConditionalDeleteTest`; миграции этих таблиц локально с нуля не идут —
 * `feedback_test_schema_must_come_from_migration`). Таблицы общего стенда `wildworld_tests`,
 * которые существовали ДО этого теста (параллельные воркеры), не дропаются — чистятся
 * только собственные строки этого теста.
 *
 * @internal
 */
final class CargoDroneAutoSendChargeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    /** @var list<string> таблицы, которые СОЗДАЛ этот тест (и поэтому вправе дропнуть). */
    private array $createdTables = [];

    /** @var list<int> id персонажей, заведённых этим тестом. */
    private array $ownCharacterIds = [];

    /**
     * exploit-fix-41 (R5-minor, m5) — `telegram_users` не входит в CHAR_LINKED (там нет
     * колонки-владельца персонажа), но каждый вызов {@see seedCharacter()} и маркерная
     * запись «после handle()» вставляют туда строку. tearDown() ниже удаляет ровно эти
     * id, а не всю таблицу — она общая с соседними воркерами.
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
        // exploit-fix-31 follow-up — тот же кеш-баг, что и в CargoDroneSendChargeTest (общий
        // фикстур-паттерн): `tableExists()` с `$cached=true` держит список имён на весь
        // процесс, и на пустой CI-базе второй тестовый метод в этом файле видит стухшее «уже
        // есть» после того, как первый метод сам дропнул им же созданную таблицу в
        // `tearDown()`. `$cached=false` бьёт по БД каждый раз вместо кеша.
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

    /** Новая CallbackQuery на каждый вызов handle() — как в CancelQueuedCraftConditionalDeleteTest. */
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

    public function testAllDecrementsRefusedAfterPlanLeavesChargeAndLogUntouched(): void
    {
        [$tgId, $charId] = $this->seedCharacter();
        $woodId = $this->ensureResource('Древесина', 'Wood');

        $this->db()->table('character_resources')->insert([
            'id_characters' => $charId, 'id_resources' => $woodId, 'quantity' => 5,
        ]);
        $logId = $this->seedCargoDrone($charId, 100);

        // Честная гонка: между тем, как `CargoAutoLoadService::plan()` прочитал остаток Wood
        // (5, «есть что взять») СВОИМ сырым SQL (raw `cr`/`r`-алиасы, без билдера), и тем, как
        // `CharacterResourceModel::decrementIfAtLeast()` внутри транзакции спишет его ЗАНОВО
        // отдельным условным `UPDATE`, кто-то успел потратить Wood до 0. `plan()` использует
        // сырой `Database::connect()->query()` — его SQL не содержит бэктиков построителя и
        // потому НЕ совпадает с сигнатурой ниже; перехватываем именно builder-чтение внутри
        // `decrementIfAtLeast()` (`where(...)->first()` — те же бэктики, что и в
        // `RepairBuildingShortageRollbackTest`) и режем остаток сразу после того, как он нашёл
        // строку, но ДО условного `UPDATE ... WHERE quantity >= ?`.
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

        $response = (new CargoDroneAutoSendAction($this->cbq($tgId, 'cargoDroneAuto_' . $logId)))->handle();

        $this->assertInstanceOf(ServerResponse::class, $response);

        $log = $this->db()->table('crafted_items_log')->where('id', $logId)->get()->getRowArray();
        $this->assertIsArray($log);
        $this->assertSame(100, (int) $log['durability_count'], 'заряд не списан — ни один предмет не уехал');
        $this->assertSame(0, $this->backpackQty($charId, $woodId), 'остаток так и остался обнулённым гонкой — decrementIfAtLeast отказал (Refused), UPDATE не применился');
    }

    public function testSuccessfulDeliveryDrainsChargeExactlyOnce(): void
    {
        [$tgId, $charId] = $this->seedCharacter();
        $woodId = $this->ensureResource('Древесина', 'Wood');

        $this->db()->table('character_resources')->insert([
            'id_characters' => $charId, 'id_resources' => $woodId, 'quantity' => 5,
        ]);
        $logId = $this->seedCargoDrone($charId, 250);

        $response = (new CargoDroneAutoSendAction($this->cbq($tgId, 'cargoDroneAuto_' . $logId)))->handle();

        $this->assertInstanceOf(ServerResponse::class, $response);

        $log = $this->db()->table('crafted_items_log')->where('id', $logId)->get()->getRowArray();
        $this->assertIsArray($log);
        $this->assertSame(150, (int) $log['durability_count'], 'заряд списан ровно один раз (default drain=100 — GameSettings недоступны, откат на caller-default DroneService::cargoBatteryDrainPerLaunch())');
        $this->assertSame(0, $this->backpackQty($charId, $woodId), 'весь запас уехал на склад');

        $storageRow = $this->db()->table('base_storage')
            ->where('character_id', $charId)->where('resource_id', $woodId)->get()->getRowArray();
        $this->assertIsArray($storageRow, 'груз реально доставлен на склад');
        $this->assertSame(5, (int) $storageRow['quantity']);
    }

    /**
     * exploit-fix-26 (R2-major) — два вылета по одному прочитанному заряду: раньше
     * `durability_count` записывался абсолютным значением, посчитанным из `$charge`,
     * прочитанного ДО транзакции (`find($logId)` в начале `handle()`) — второй параллельный
     * вылет читал тот же заряд и писал то же самое уменьшенное число, второй вылет
     * оказывался бесплатным.
     *
     * Гонка воспроизведена детерминированно тем же приёмом, что и выше, но перехватывает
     * WRITE-запрос, а не SELECT: колбэк на `DBQuery` ловит `UPDATE`/`DELETE ... character_resources`
     * — то есть само условное списание рюкзака внутри `CharacterResourceModel::decrementIfAtLeast()`
     * (при полном списании остатка примитив уходит в `DELETE FROM ... WHERE quantity = ?` — см.
     * докблок `ConditionalWriteService::decrementIfAtLeast()`) — и сразу после него пишет
     * `crafted_items_log.durability_count` ниже `drain`, симулируя уже завершившийся конкурентный
     * вылет, который «успел» списать заряд первым.
     *
     * Перехват именно WRITE-, а не READ-запроса — не случайный выбор: `BaseConnection::query()`
     * триггерит событие `DBQuery` ДО того, как для read-типа собирает `Result`-обёртку вокруг
     * `$this->resultID`, а колбэк-обработчик сам выполняет запрос НА ТОЙ ЖЕ `$this->db()`-связи —
     * это перезаписывает `$this->resultID` соединения ДО того, как исходный `SELECT` успел его
     * прочитать, и `first()` снаружи детерминированно возвращает `null` независимо от реальных
     * данных в таблице (не гонка, а искажение самого измерения). Для write-типа код обходит эту
     * ветку — `query()` возвращает `true` сразу после события, не трогая `$this->resultID` —
     * поэтому перехват на `UPDATE`/`DELETE` этого искажения не вносит.
     *
     * decrementIfAtLeast() на durability_count обязан отказать (заряда уже не хватает) —
     * и весь остальной груз этого вылета (уже списанный из рюкзака и уже доставленный на
     * склад в этой же транзакции) обязан откатиться вместе с отказом, а не закоммититься
     * без оплаты зарядом.
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
            // бэктиков вокруг имени таблицы (в отличие от builder-SELECT выше по стеку), поэтому
            // фильтр здесь без бэктиков.
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

        $response = (new CargoDroneAutoSendAction($this->cbq($tgId, 'cargoDroneAuto_' . $logId)))->handle();

        $concurrentDb->close();

        $this->assertInstanceOf(ServerResponse::class, $response);
        // exploit-fix-31 (R3 m1) — раньше этот путь (chargeRefused) отвечал общим текстом
        // «Рюкзак опустел раньше, чем дрон успел взлететь» — рюкзак тут ни при чём, груз уже
        // доставлен на склад в этой же транзакции, причина отказа — заряд.
        $replyText = $response->getResult() instanceof \Longman\TelegramBot\Entities\Message
            ? $response->getResult()->getText()
            : null;
        $this->assertIsString($replyText);
        $this->assertStringContainsString('Заряд', $replyText, 'отказ по заряду обязан называть заряд причиной, а не рюкзак');
        $this->assertStringNotContainsString('Рюкзак опустел', $replyText, 'текст про пустой рюкзак не годится для отказа по заряду — груз реально уехал');

        $log = $this->db()->table('crafted_items_log')->where('id', $logId)->get()->getRowArray();
        $this->assertIsArray($log);
        $this->assertSame(50, (int) $log['durability_count'], 'заряд списан конкурентом ровно один раз — этот вылет своего списания не добавил');
        $this->assertSame(5, $this->backpackQty($charId, $woodId), 'списание рюкзака откатилось вместе с отказом по заряду');

        $storageRow = $this->db()->table('base_storage')
            ->where('character_id', $charId)->where('resource_id', $woodId)->get()->getRowArray();
        $this->assertNull($storageRow, 'доставка на склад откатилась — второй вылет не состоялся');
    }

    /**
     * exploit-fix-36 (R4-major) — исключение между `transStart()` и завершением (любой запрос
     * тела: `decrementIfAtLeast()`/`deliver()`/`ConditionalWriteService`) раньше пробрасывалось
     * мимо `transRollback()` — транзакция оставалась открытой на глубине 1, тот же класс потери,
     * что exploit-fix-31 закрыл для веток отказа по `WriteOutcome`. Близнец
     * {@see CargoDroneSendChargeTest::testExceptionDuringTransactionRollsBackAndClosesTransaction()}.
     * Исключение воспроизведено искусственно: `DBQuery`-хук бросает на `INSERT`/`UPDATE` в
     * `base_storage` внутри `BaseStorageModel::deliver()` — выполняется уже ПОСЛЕ условного
     * списания ресурса из рюкзака, но ДО списания заряда дрона.
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
            (new CargoDroneAutoSendAction($this->cbq($tgId, 'cargoDroneAuto_' . $logId)))->handle();
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
     * до явного `resetTransStatus()`. Близнец
     * {@see CargoDroneSendChargeTest::testSilentTransactionFailureWithoutExceptionResetsTransStatus()}.
     *
     * Тихий отказ воспроизведён реальной SQL-ошибкой (обращение к несуществующей колонке)
     * внутри транзакции handle(), на ТОМ ЖЕ соединении — `DBDebug=false` гарантирует
     * отсутствие исключения. `DBQuery`-хук ставит флаг `$injected`, чтобы не зациклиться
     * на собственном же инжектированном запросе. Перехват — на WRITE-запросе (`INSERT` в
     * `base_storage` внутри `BaseStorageModel::deliver()`), не на SELECT: тот же приём,
     * что и в `testExceptionDuringTransactionRollsBackAndClosesTransaction()` выше —
     * перехват builder-SELECT внутри `decrementIfAtLeast()` (как в
     * `testAllDecrementsRefusedAfterPlanLeavesChargeAndLogUntouched()`) переписал бы
     * `$this->resultID` соединения ДО того, как исходный `SELECT` успел его прочитать
     * (докблок класса выше), искажая измерение вместо воспроизведения сценария ревьюера.
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

        $response = (new CargoDroneAutoSendAction($this->cbq($tgId, 'cargoDroneAuto_' . $logId)))->handle();

        Events::removeAllListeners('DBQuery');
        $this->enableDBDebug();

        $this->assertTrue($injected, 'хук обязан был поймать и подменить целевой запрос — иначе тест ничего не проверил');

        $this->assertInstanceOf(ServerResponse::class, $response);
        $replyText = $response->getResult() instanceof \Longman\TelegramBot\Entities\Message
            ? $response->getResult()->getText()
            : null;
        $this->assertIsString($replyText);

        $this->assertSame(0, $this->db()->transDepth, 'транзакция обязана закрыться на тихом отказе — глубина 0 после handle()');
        $this->assertTrue(
            $this->db()->transStatus(),
            'resetTransStatus() обязан вернуть флаг успеха после тихого (без исключения) отказа — иначе следующий transStart() того же соединения унаследует чужой сбой'
        );

        $this->assertSame(5, $this->backpackQty($charId, $woodId), 'списание рюкзака откатилось вместе с тихим отказом');

        $log = $this->db()->table('crafted_items_log')->where('id', $logId)->get()->getRowArray();
        $this->assertIsArray($log);
        $this->assertSame(250, (int) $log['durability_count'], 'заряд не тронут — вся транзакция откатилась');

        $storageRow = $this->db()->table('base_storage')
            ->where('character_id', $charId)->where('resource_id', $woodId)->get()->getRowArray();
        $this->assertNull($storageRow, 'доставка на склад откатилась вместе с тихим отказом');

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
