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
        if (! $this->db()->tableExists($table)) {
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

    /** @return array{0:int,1:int} [telegram_id, character_id] */
    private function seedCharacter(): array
    {
        $tgId = random_int(730_000_000, 739_999_999);
        $this->db()->table('telegram_users')->insert(['telegram_id' => $tgId]);
        $tgUid = (int) $this->db()->insertID();

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
}
