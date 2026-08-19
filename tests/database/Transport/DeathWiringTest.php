<?php

declare(strict_types=1);

namespace Tests\Database\Transport;

use App\Services\Player\DeathService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * transport-14 — закрывает BUILT-BUT-DEAD: `LootProcessor::breakActiveVehicleOnDeath()`
 * (story 09, покрыт `DeathBreaksVehicleTest`) был написан, но не вызывался ниоткуда.
 * Здесь проверяется реальный путь `DeathService::handlePlayerDeathAndReward()`, а не
 * прямой вызов метода LootProcessor.
 *
 * Единственный подменённый шов — сеть Telegram (`sendVehicleBrokenMessage`), по образцу
 * `FakeLevelUpNotifier`: поиск чата (`chatIdFor` → `telegram_users`) и вся DB-логика смерти
 * (страховка/штраф/лут/транспорт/respawn) выполняются по-настоящему на изолированной схеме.
 *
 * @internal
 */
final class DeathWiringTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = [
        'characters', 'character_resources', 'crafted_items_log', 'crafted_items',
        'claimed_cells', 'map', 'events', 'active_events', 'telegram_users',
    ];

    private BaseConnection $conn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->conn = Database::connect('tests');

        foreach (self::TABLES as $t) {
            $this->conn->query("DROP TABLE IF EXISTS {$t}");
        }

        $this->conn->query('
            CREATE TABLE characters (
                id INT AUTO_INCREMENT PRIMARY KEY,
                telegram_user_id INT NULL,
                gold INT NOT NULL DEFAULT 0,
                level INT NOT NULL DEFAULT 1,
                insurance TINYINT NOT NULL DEFAULT 0,
                active_vehicle_log_id INT NULL,
                cell_number INT NOT NULL DEFAULT 1,
                last_respawn_at DATETIME NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        $this->conn->query('
            CREATE TABLE character_resources (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_characters INT NOT NULL,
                id_resources INT NOT NULL,
                quantity INT NOT NULL DEFAULT 0,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        $this->conn->query('
            CREATE TABLE crafted_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name_eng VARCHAR(150) NULL,
                durability_count INT NULL
            )
        ');
        $this->conn->query('
            CREATE TABLE crafted_items_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                character_id INT NOT NULL,
                crafted_item_id INT NOT NULL,
                type VARCHAR(100) NULL,
                durability_count INT NULL,
                quantity INT NOT NULL DEFAULT 1,
                insured TINYINT NULL DEFAULT 0,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        $this->conn->query('
            CREATE TABLE claimed_cells (
                id INT AUTO_INCREMENT PRIMARY KEY,
                character_id INT NOT NULL,
                map_cell_id INT NOT NULL,
                status VARCHAR(50) NOT NULL,
                claimed_at DATETIME NULL
            )
        ');
        $this->conn->query('
            CREATE TABLE map (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cell_number INT NOT NULL,
                coordinate_x INT NOT NULL,
                coordinate_y INT NOT NULL,
                biome_id INT NOT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        $this->conn->query('
            CREATE TABLE events (
                event_id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NULL,
                name_english VARCHAR(255) NULL,
                effect_type VARCHAR(50) NULL,
                effect_value INT NULL,
                biome_ids TEXT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        $this->conn->query('
            CREATE TABLE active_events (
                id INT AUTO_INCREMENT PRIMARY KEY,
                event_id INT NULL,
                start_time DATETIME NULL,
                end_time DATETIME NULL,
                status VARCHAR(50) NULL,
                effect_applied TINYINT NULL,
                effect_log TEXT NULL,
                notified_users TEXT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        $this->conn->query('
            CREATE TABLE telegram_users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                telegram_id BIGINT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
    }

    protected function tearDown(): void
    {
        foreach (self::TABLES as $t) {
            $this->conn->query("DROP TABLE IF EXISTS {$t}");
        }
        parent::tearDown();
    }

    // ── Хелперы сидирования ─────────────────────────────────────────────

    private function seedTelegramUser(int $chatId): int
    {
        $this->conn->table('telegram_users')->insert(['telegram_id' => $chatId]);

        return (int) $this->conn->insertID();
    }

    private function seedCharacter(int $telegramUserId, bool $insured = false): int
    {
        $this->conn->table('characters')->insert([
            'telegram_user_id' => $telegramUserId,
            'gold'              => 0,
            'level'             => 5,
            'insurance'         => $insured ? 1 : 0,
        ]);

        return (int) $this->conn->insertID();
    }

    private function seedTemplate(int $durability = 300): int
    {
        $this->conn->table('crafted_items')->insert(['name_eng' => 'cart', 'durability_count' => $durability]);

        return (int) $this->conn->insertID();
    }

    private function seedVehicleLog(int $characterId, int $craftedItemId, ?int $durability, int $quantity = 1): int
    {
        $this->conn->table('crafted_items_log')->insert([
            'character_id'     => $characterId,
            'crafted_item_id'  => $craftedItemId,
            'type'             => 'transport',
            'durability_count' => $durability,
            'quantity'         => $quantity,
        ]);

        return (int) $this->conn->insertID();
    }

    private function activate(int $characterId, int $logId): void
    {
        $this->conn->table('characters')->where('id', $characterId)->update(['active_vehicle_log_id' => $logId]);
    }

    private function pointerOf(int $characterId): ?int
    {
        $row = $this->conn->table('characters')->select('active_vehicle_log_id')->where('id', $characterId)->get()->getRowArray();

        return isset($row['active_vehicle_log_id']) && $row['active_vehicle_log_id'] !== null ? (int) $row['active_vehicle_log_id'] : null;
    }

    /** @return array<string,mixed>|null */
    private function vehicleRow(int $logId): ?array
    {
        $row = $this->conn->table('crafted_items_log')->where('id', $logId)->get()->getRowArray();

        return $row ?: null;
    }

    // ── Acceptance ────────────────────────────────────────────────────

    public function testRealDeathBreaksActiveVehicleAndNotifiesPlayer(): void
    {
        $tgUser = $this->seedTelegramUser(555111);
        $char   = $this->seedCharacter($tgUser);
        $item   = $this->seedTemplate(300);
        $log    = $this->seedVehicleLog($char, $item, durability: 250);
        $this->activate($char, $log);

        $svc    = new TestableDeathService();
        $result = $svc->handlePlayerDeathAndReward($char, null);

        $this->assertTrue($result['success']);

        $row = $this->vehicleRow($log);
        $this->assertNotNull($row, 'строка транспорта не удаляется — разбивается, а не пропадает');
        $this->assertSame(0, (int) $row['durability_count'], 'износ активной машины обнулён');
        $this->assertSame(1, (int) $row['quantity'], 'quantity строки не меняется смертью');
        $this->assertNull($this->pointerOf($char), 'указатель активной машины снят');

        $this->assertCount(1, $svc->sent, 'игрок получил ровно одно уведомление о машине');
        [$chatId, $text] = $svc->sent[0];
        $this->assertSame(555111, $chatId, 'сообщение ушло в чат владельца');
        $this->assertStringContainsString('разбит', $text);
        $this->assertStringContainsString('почини', $text);
        $this->assertLessThanOrEqual(1024, mb_strlen($text), 'самодостаточно, ≤1024 знаков вместе с остальным сообщением о смерти');
        $this->assertSame(0, substr_count($text, '*') % 2, 'markdown-safe: парные *');
        $this->assertSame(0, substr_count($text, '_') % 2, 'markdown-safe: парные _');
    }

    public function testDeathWithoutActiveVehicleTouchesNothingAndSendsNoVehicleMessage(): void
    {
        $tgUser = $this->seedTelegramUser(555222);
        $char   = $this->seedCharacter($tgUser);

        $svc    = new TestableDeathService();
        $result = $svc->handlePlayerDeathAndReward($char, null);

        $this->assertTrue($result['success']);
        $this->assertSame([], $svc->sent, 'без активной машины — ни одного уведомления о разбитой машине');
        $this->assertNull($this->pointerOf($char));
    }

    public function testDeathWithVehicleOwnedButNotActiveDoesNotTouchItOrNotify(): void
    {
        $tgUser = $this->seedTelegramUser(555333);
        $char   = $this->seedCharacter($tgUser);
        $item   = $this->seedTemplate(300);
        $log    = $this->seedVehicleLog($char, $item, durability: 300);
        // НЕ активируем — указатель пуст.

        $svc    = new TestableDeathService();
        $result = $svc->handlePlayerDeathAndReward($char, null);

        $this->assertTrue($result['success']);
        $this->assertSame([], $svc->sent);

        $row = $this->vehicleRow($log);
        $this->assertSame(300, (int) $row['durability_count'], 'неактивная строка транспорта не тронута');
    }

    public function testRepeatedDeathWithAlreadyZeroDurabilityIsIdempotent(): void
    {
        $tgUser = $this->seedTelegramUser(555444);
        $char   = $this->seedCharacter($tgUser);
        $item   = $this->seedTemplate(300);
        $log    = $this->seedVehicleLog($char, $item, durability: 0);
        $this->activate($char, $log);

        $svc    = new TestableDeathService();
        $result = $svc->handlePlayerDeathAndReward($char, null);

        $this->assertTrue($result['success']);
        $row = $this->vehicleRow($log);
        $this->assertSame(0, (int) $row['durability_count']);
        $this->assertSame(1, (int) $row['quantity']);
        $this->assertNull($this->pointerOf($char));
        $this->assertCount(1, $svc->sent, 'первая смерть с уже нулевым износом всё ещё разбивает активную машину и уведомляет');

        // Вторая смерть подряд: активной машины больше нет (указатель снят предыдущим
        // вызовом) — ничего не падает и повторного уведомления о машине нет.
        $svc2    = new TestableDeathService();
        $result2 = $svc2->handlePlayerDeathAndReward($char, null);

        $this->assertTrue($result2['success']);
        $this->assertSame([], $svc2->sent, 'повторная смерть без активной машины не шлёт сообщение снова');

        $row2 = $this->vehicleRow($log);
        $this->assertSame(0, (int) $row2['durability_count'], 'износ остаётся 0 — вторая смерть ничего не ломает');
        $this->assertSame(1, (int) $row2['quantity']);
    }

    public function testExistingResourceLossBehaviourUnaffectedByVehicleWiring(): void
    {
        // −3%/−50% и дробный бросок ADR-172 не тронуты этой story: смерть без базы
        // (penalty 0.50) списывает ресурсы как прежде, машина в расчёт не входит.
        $tgUser = $this->seedTelegramUser(555555);
        $char   = $this->seedCharacter($tgUser);
        $this->conn->table('character_resources')->insert([
            'id_characters' => $char,
            'id_resources'  => 7,
            'quantity'      => 200,
        ]);

        $svc    = new TestableDeathService();
        $result = $svc->handlePlayerDeathAndReward($char, null);

        $this->assertTrue($result['success']);
        $this->assertSame(0.50, $result['penalty'], 'без базы и без newbie-конфига — штраф прежний, 50%');

        $row = $this->conn->table('character_resources')->where('id_characters', $char)->get()->getRowArray();
        $this->assertSame(100, (int) $row['quantity'], 'floor(200*0.5)=100 списано — поведение ресурсов byte-identical');
    }
}

/**
 * Test-double: единственный подменённый шов — реальная отправка в Telegram
 * (по образцу `FakeLevelUpNotifier`). Поиск чата и вся DB-логика смерти боевые.
 *
 * @internal
 */
final class TestableDeathService extends DeathService
{
    /** @var list<array{0: int, 1: string}> */
    public array $sent = [];

    protected function sendVehicleBrokenMessage(int $chatId, string $text): void
    {
        $this->sent[] = [$chatId, $text];
    }
}
