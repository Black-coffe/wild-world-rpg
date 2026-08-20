<?php

declare(strict_types=1);

namespace Tests\Unit\Transport;

use App\Controllers\Telegram\Commands\Actions\Craft\Repair\NpcRepairAction;
use App\Services\Player\VehicleActivationService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Telegram;

/**
 * Ремонт ревью-находки (2026-08-20, ADR-174) — разбитая/изношенная машина
 * (`crafted_items.type='transport'`) теперь достижима по существующему пути
 * ремонта (`RepairToolsListAction` список → `NpcRepairAction` кнопки), а не
 * заперта фильтром `type='tool'`.
 *
 * Тест идёт РЕАЛЬНЫМ путём кнопок: конструирует настоящий `CallbackQuery`
 * (как из вебхука) и вызывает `NpcRepairAction::askForRepair()` →
 * `::confirmRepair()` — те самые методы, на которые роутится нажатие кнопок
 * `npc_repair_<id>` / `confirm_npc_repair_<id>` в реальной игре. Никакого
 * прямого `logModel->update()` в качестве проверяемого действия — только как
 * assert над результатом.
 *
 * `PHPUNIT_TESTSUITE` + `Telegram::__construct()` — штатный механизм самой
 * библиотеки longman/telegram-bot: `Request::send()` при определённой
 * константе возвращает фейковый `ServerResponse` без сетевого вызова
 * (vendor/longman/telegram-bot/src/Request.php:698).
 *
 * Изолированная схема (паттерн `DeathBreaksVehicleTest`/`NpcRepairServiceTest`) —
 * своя `characters`/`telegram_users`/`crafted_items`/`crafted_items_log`/
 * `resources`/`game_settings` в `wildworld_tests`, не общая прод-схема.
 *
 * @internal
 */
final class VehicleRepairTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = [
        'characters', 'telegram_users', 'crafted_items', 'crafted_items_log',
        'resources', 'game_settings',
    ];

    private \CodeIgniter\Database\BaseConnection $conn;

    protected function setUp(): void
    {
        parent::setUp();

        if (! defined('PHPUNIT_TESTSUITE')) {
            define('PHPUNIT_TESTSUITE', true);
        }
        // Штатная фейковая инициализация Request — без неё Request::send() падает
        // на null Telegram-инстансе (см. докблок класса).
        new Telegram('123456:TEST-fake-token-for-tests', 'test_bot');

        $this->conn = Database::connect('tests');

        foreach (self::TABLES as $t) {
            $this->conn->query("DROP TABLE IF EXISTS {$t}");
        }

        $this->conn->query('
            CREATE TABLE telegram_users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                telegram_id BIGINT NOT NULL,
                username VARCHAR(191) NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        $this->conn->query('
            CREATE TABLE characters (
                id INT AUTO_INCREMENT PRIMARY KEY,
                telegram_user_id INT NOT NULL,
                name VARCHAR(191) NULL,
                level INT NOT NULL DEFAULT 1,
                gold INT NOT NULL DEFAULT 1000,
                active_vehicle_log_id INT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        $this->conn->query('
            CREATE TABLE crafted_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name_rus VARCHAR(191) NULL,
                name_eng VARCHAR(150) NULL,
                type VARCHAR(100) NULL,
                durability_count INT NULL
            )
        ');
        $this->conn->query('
            CREATE TABLE crafted_items_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                character_id INT NOT NULL,
                crafted_item_id INT NOT NULL,
                type VARCHAR(100) NULL,
                durability_count INT NOT NULL DEFAULT 0,
                quantity INT NOT NULL DEFAULT 1,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        $this->conn->query('
            CREATE TABLE resources (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(191) NULL,
                price INT NULL
            )
        ');
        $this->conn->query('
            CREATE TABLE game_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(191) NOT NULL,
                category VARCHAR(64) NULL,
                value_type VARCHAR(16) NULL,
                value_int INT NULL,
                value_float DECIMAL(15,5) NULL,
                value_bool TINYINT NULL,
                value_string TEXT NULL,
                hard_min VARCHAR(32) NULL,
                hard_max VARCHAR(32) NULL
            )
        ');
        // Пусто: NpcRepairService/GameSettingsService падают на дефолты
        // (npc.repair.cost_fraction=0.5, markup=1.5, min_cost_gold=10, enabled=true) —
        // те же дефолты, что и на проде до первой ручной правки в admin UI.

        $this->conn->table('resources')->insert(['name' => 'Древесина', 'price' => 5]);
    }

    protected function tearDown(): void
    {
        foreach (self::TABLES as $t) {
            $this->conn->query("DROP TABLE IF EXISTS {$t}");
        }
        parent::tearDown();
    }

    // ── Хелперы сидирования / кнопки ─────────────────────────────────────

    private function seedCharacter(int $tgId, int $gold = 1000): int
    {
        $this->conn->table('telegram_users')->insert(['telegram_id' => $tgId]);
        $tgUserId = (int) $this->conn->insertID();

        $this->conn->table('characters')->insert([
            'telegram_user_id' => $tgUserId,
            'name'              => 'Тест',
            'gold'              => $gold,
        ]);

        return (int) $this->conn->insertID();
    }

    /** LightCart: каталог синхронизирован твин-миграцией на charges_full=300. */
    private function seedLightCartTemplate(): int
    {
        $this->conn->table('crafted_items')->insert([
            'name_rus'         => 'Лёгкая повозка',
            'name_eng'         => 'LightCart',
            'type'             => 'transport',
            'durability_count' => 300,
        ]);

        return (int) $this->conn->insertID();
    }

    private function seedVehicleLog(int $charId, int $craftedItemId, int $durability): int
    {
        $this->conn->table('crafted_items_log')->insert([
            'character_id'     => $charId,
            'crafted_item_id'  => $craftedItemId,
            'type'             => 'transport',
            'durability_count' => $durability,
            'quantity'         => 1,
        ]);

        return (int) $this->conn->insertID();
    }

    /** Настоящий CallbackQuery — как из реального вебхука клика по кнопке. */
    private function callbackQuery(int $tgId, string $data): CallbackQuery
    {
        return new CallbackQuery([
            'id'   => 'cbq_1',
            'from' => ['id' => $tgId, 'is_bot' => false, 'first_name' => 'Тест'],
            'message' => [
                'message_id' => 1,
                'date'       => time(),
                'chat'       => ['id' => $tgId, 'type' => 'private'],
                'text'       => 'placeholder',
            ],
            'chat_instance' => 'ci_1',
            'data'          => $data,
        ]);
    }

    private function durabilityOf(int $logId): int
    {
        $row = $this->conn->table('crafted_items_log')->where('id', $logId)->get()->getRowArray();

        return (int) ($row['durability_count'] ?? -1);
    }

    // ── Разбитая машина (заряд 0) — путь кнопок ───────────────────────────

    public function testBrokenVehicleIsRepairedThroughNpcRepairButtonPath(): void
    {
        $tgId  = 111001;
        $char  = $this->seedCharacter($tgId);
        $item  = $this->seedLightCartTemplate();
        $log   = $this->seedVehicleLog($char, $item, durability: 0); // разбита насмерть

        // 1. Экран NPC-ремонта (кнопка «🛠 NPC» из RepairToolsListAction).
        $ask = new NpcRepairAction($this->callbackQuery($tgId, "npc_repair_{$log}"));
        $askResponse = $ask->askForRepair();
        $this->assertTrue($askResponse->isOk(), 'экран запроса ремонта обязан открыться для разбитой машины');

        // 2. Подтверждение (кнопка «✅ Заплатить N 🪙»).
        $confirm = new NpcRepairAction($this->callbackQuery($tgId, "confirm_npc_repair_{$log}"));
        $confirmResponse = $confirm->confirmRepair();
        $this->assertTrue($confirmResponse->isOk(), 'подтверждение ремонта обязано пройти');

        $this->assertSame(300, $this->durabilityOf($log), 'заряд разбитой машины восстановлен до полного (charges_full=300)');

        // 3. После ремонта машина снова активируется и снова даёт бонус.
        $vehicleService = new VehicleActivationService($this->conn);
        $this->assertTrue($vehicleService->activate($char, $log), 'отремонтированная машина обязана активироваться');

        $active = $vehicleService->resolveActive($char);
        $this->assertNotNull($active);
        $this->assertSame(300, $active['charges'], 'после ремонта активная машина снова несёт полный заряд — бонус вернулся');
        $this->assertSame(300, $active['charges_full']);
    }

    // ── Изношенная машина (заряд > 0, но мало) — тот же путь ──────────────

    public function testWornVehicleIsRepairedThroughNpcRepairButtonPath(): void
    {
        $tgId = 111002;
        $char = $this->seedCharacter($tgId);
        $item = $this->seedLightCartTemplate();
        $log  = $this->seedVehicleLog($char, $item, durability: 40); // изношена, не разбита

        $ask = new NpcRepairAction($this->callbackQuery($tgId, "npc_repair_{$log}"));
        $this->assertTrue($ask->askForRepair()->isOk());

        $confirm = new NpcRepairAction($this->callbackQuery($tgId, "confirm_npc_repair_{$log}"));
        $this->assertTrue($confirm->confirmRepair()->isOk());

        $this->assertSame(300, $this->durabilityOf($log), 'изношенная машина тоже чинится до полного заряда');
    }

    // ── Полная машина — ремонт недоступен (нет смысла) ────────────────────

    public function testFullVehicleHasNothingToRepair(): void
    {
        $tgId = 111003;
        $char = $this->seedCharacter($tgId);
        $item = $this->seedLightCartTemplate();
        $log  = $this->seedVehicleLog($char, $item, durability: 300); // полный заряд

        $ask = new NpcRepairAction($this->callbackQuery($tgId, "npc_repair_{$log}"));
        $ask->askForRepair();

        // buildContext() возвращает null при cur>=max — durability не тронут.
        $this->assertSame(300, $this->durabilityOf($log));
    }
}
