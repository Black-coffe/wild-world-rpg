<?php

declare(strict_types=1);

namespace Tests\Unit\Transport;

use App\Services\Player\Death\LootProcessor;
use App\Services\Player\VehicleActivationService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * transport-09 (ADR-174, поправка владельца «разбивается, но не пропадает») —
 * смерть больше НЕ изымает транспорт: `type='transport'` пропускается в
 * `computeCraftLoss()`, а `breakActiveVehicleOnDeath()` обнуляет износ активной
 * строки и снимает указатель через готовый `VehicleActivationService::breakActive()`
 * (owner — story 03, эта story его не меняет).
 *
 * Изолированная схема (как `VehicleActivationServiceTest`/`LootProcessorTest`) —
 * своя `characters`/`crafted_items_log`/`crafted_items` в `wildworld_tests`.
 *
 * @internal
 */
final class DeathBreaksVehicleTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = ['characters', 'crafted_items_log', 'crafted_items'];

    private \CodeIgniter\Database\BaseConnection $conn;
    private LootProcessor $proc;

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
                active_vehicle_log_id INT NULL,
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
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');

        $vehicleService = new VehicleActivationService($this->conn);
        $this->proc      = new LootProcessor(null, null, null, $vehicleService);
    }

    protected function tearDown(): void
    {
        foreach (self::TABLES as $t) {
            $this->conn->query("DROP TABLE IF EXISTS {$t}");
        }
        parent::tearDown();
    }

    // ── Хелперы сидирования ─────────────────────────────────────────────

    private function seedCharacter(?int $activeLogId = null): int
    {
        $this->conn->table('characters')->insert(['active_vehicle_log_id' => $activeLogId]);

        return (int) $this->conn->insertID();
    }

    private function seedTemplate(?int $durability = 300): int
    {
        $this->conn->table('crafted_items')->insert(['name_eng' => 'cart', 'durability_count' => $durability]);

        return (int) $this->conn->insertID();
    }

    private function seedLog(int $characterId, int $craftedItemId, string $type, ?int $durability, int $quantity): int
    {
        $this->conn->table('crafted_items_log')->insert([
            'character_id'     => $characterId,
            'crafted_item_id'  => $craftedItemId,
            'type'             => $type,
            'durability_count' => $durability,
            'quantity'         => $quantity,
        ]);

        return (int) $this->conn->insertID();
    }

    private function pointerOf(int $characterId): ?int
    {
        $row = $this->conn->table('characters')->select('active_vehicle_log_id')->where('id', $characterId)->get()->getRowArray();

        return isset($row['active_vehicle_log_id']) ? (int) $row['active_vehicle_log_id'] : null;
    }

    // ── computeCraftLoss: транспорт не участвует в расчёте потерь ────────

    public function testTransportRowsNeverLoseQuantityWithBase(): void
    {
        $rows = [
            ['id' => 1, 'crafted_item_id' => 10, 'quantity' => 200, 'type' => 'transport'],
            ['id' => 2, 'crafted_item_id' => 20, 'quantity' => 200, 'type' => 'tool'],
        ];

        // penalty=0.50 ("нет базы") — самый агрессивный штраф.
        $lost = $this->proc->computeCraftLoss($rows, 0.50);

        $logIds = array_map(static fn (array $x) => $x['logId'], $lost);
        $this->assertNotContains(1, $logIds, 'строка транспорта не должна попадать в потери');
        $this->assertContains(2, $logIds, 'остальные предметы теряются как прежде');
    }

    public function testTransportRowsNeverLoseQuantityNoBase(): void
    {
        $rows = [
            ['id' => 1, 'crafted_item_id' => 10, 'quantity' => 1, 'type' => 'transport'],
        ];

        // penalty=0.03 ("с базой") — минимальный штраф, тоже не должен тронуть транспорт.
        $lost = $this->proc->computeCraftLoss($rows, 0.03);

        $this->assertSame([], $lost, 'транспорт не участвует в computeCraftLoss ни в одной ветке');
    }

    public function testNonTransportBehaviourIsByteIdenticalToBefore(): void
    {
        // Без транспорта в списке — числа по остальным предметам не меняются относительно
        // ранее покрытого поведения (см. LootProcessorTest::testComputeCraftLossThreePercent).
        $rows = [
            ['id' => 100, 'crafted_item_id' => 7, 'quantity' => 200, 'type' => 'tool'],
            ['id' => 101, 'crafted_item_id' => 9, 'quantity' => 33, 'type' => 'tool'],
        ];
        $result = $this->proc->computeCraftLoss($rows, 0.03);

        $this->assertCount(1, $result);
        $this->assertSame(['logId' => 100, 'craftedItemId' => 7, 'lossAmount' => 6], $result[0]);
    }

    // ── breakActiveVehicleOnDeath(): «разбивается, но не пропадает» ──────

    public function testDeathWithActiveVehicleZeroesDurabilityClearsPointerKeepsRow(): void
    {
        $char = $this->seedCharacter();
        $item = $this->seedTemplate(300);
        $log  = $this->seedLog($char, $item, 'transport', 250, quantity: 1);

        $vehicleService = new VehicleActivationService($this->conn);
        $vehicleService->activate($char, $log);

        $message = $this->proc->breakActiveVehicleOnDeath($char);

        $this->assertNotNull($message, 'игрок обязан получить сообщение о разбитой машине');
        $this->assertStringContainsString('разбит', $message);

        $this->assertNull($this->pointerOf($char), 'указатель обязан обнулиться — следующий Поход идёт с нейтральным профилем');

        $row = $this->conn->table('crafted_items_log')->where('id', $log)->get()->getRowArray();
        $this->assertNotNull($row, 'строка crafted_items_log не удаляется — это не изъятие');
        $this->assertSame(0, (int) $row['durability_count'], 'износ активной машины обнулён');
        $this->assertSame(1, (int) $row['quantity'], 'quantity не меняется смертью');
    }

    public function testDeathWithoutActiveVehicleIsNoopAndReturnsNull(): void
    {
        $char = $this->seedCharacter();

        $message = $this->proc->breakActiveVehicleOnDeath($char);

        $this->assertNull($message);
        $this->assertNull($this->pointerOf($char));
    }

    public function testDeathWithVehicleOwnedButNotActiveDoesNotTouchIt(): void
    {
        $char = $this->seedCharacter();
        $item = $this->seedTemplate(300);
        // Есть строка транспорта, но она не активирована (указатель пуст).
        $log = $this->seedLog($char, $item, 'transport', 300, quantity: 1);

        $message = $this->proc->breakActiveVehicleOnDeath($char);

        $this->assertNull($message, 'нет активной машины — ломать нечего');

        $row = $this->conn->table('crafted_items_log')->where('id', $log)->get()->getRowArray();
        $this->assertSame(300, (int) $row['durability_count'], 'неактивная строка транспорта не тронута');
    }
}
