<?php

declare(strict_types=1);

namespace Tests\Unit\Transport;

use App\Services\Admin\WipeService;
use App\Services\Player\VehicleActivationService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Config\WipeManifest;

/**
 * transport-03 (ADR-174, план `docs/specs/transport-system/plan.md` → Contracts) —
 * `VehicleActivationService`: жизненный цикл `characters.active_vehicle_log_id`.
 *
 * Изолированная схема (как `LootProcessorTest`/`RewardServiceTest`) — своя
 * `characters`/`crafted_items_log`/`crafted_items` в `wildworld_tests`, не общая
 * прод-схема (локальная база поднимается дампом, миграции с нуля не идут).
 *
 * @internal
 */
final class VehicleActivationServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = ['characters', 'crafted_items_log', 'crafted_items', 'map'];

    private \CodeIgniter\Database\BaseConnection $conn;
    private VehicleActivationService $service;

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
                level INT NOT NULL DEFAULT 1,
                active_vehicle_log_id INT NULL,
                cell_number INT NULL,
                biome_id INT NULL,
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
                durability_count INT NULL,
                quantity INT NOT NULL DEFAULT 1,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        // wildworld_tests не несёт `map` (мир не рендерится в PHPUnit) — фикстура нужна
        // только потому, что WipeService::resetCharacter() безусловно зовёт spawnCells().
        $this->conn->query('
            CREATE TABLE map (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cell_number INT NOT NULL,
                coordinate_x INT NOT NULL,
                coordinate_y INT NOT NULL,
                biome_id INT NULL
            )
        ');

        $this->service = new VehicleActivationService($this->conn);
    }

    // ── Хелперы сидирования ─────────────────────────────────────────────

    private function seedCharacter(?int $activeLogId = null): int
    {
        $this->conn->table('characters')->insert(['active_vehicle_log_id' => $activeLogId]);

        return (int) $this->conn->insertID();
    }

    private function seedTemplate(string $nameEng, ?int $durability): int
    {
        $this->conn->table('crafted_items')->insert(['name_eng' => $nameEng, 'durability_count' => $durability]);

        return (int) $this->conn->insertID();
    }

    private function seedLog(int $characterId, int $craftedItemId, ?int $durabilityCount, int $quantity = 1): int
    {
        $this->conn->table('crafted_items_log')->insert([
            'character_id'     => $characterId,
            'crafted_item_id'  => $craftedItemId,
            'durability_count' => $durabilityCount,
            'quantity'         => $quantity,
        ]);

        return (int) $this->conn->insertID();
    }

    private function pointerOf(int $characterId): ?int
    {
        $row = $this->conn->table('characters')->select('active_vehicle_log_id')->where('id', $characterId)->get()->getRowArray();

        return isset($row['active_vehicle_log_id']) ? (int) $row['active_vehicle_log_id'] : null;
    }

    // ── activate(): IDOR ─────────────────────────────────────────────────

    public function testActivateWithForeignLogIdReturnsFalseAndDoesNotWrite(): void
    {
        $owner  = $this->seedCharacter();
        $victim = $this->seedCharacter();
        $item   = $this->seedTemplate('cart', 300);
        $log    = $this->seedLog($owner, $item, 300);

        $result = $this->service->activate($victim, $log);

        $this->assertFalse($result);
        $this->assertNull($this->pointerOf($victim));
    }

    public function testActivateWithNonExistentLogIdReturnsFalse(): void
    {
        $char = $this->seedCharacter();

        $this->assertFalse($this->service->activate($char, 999999));
        $this->assertNull($this->pointerOf($char));
    }

    // ── Переактивация переставляет указатель ────────────────────────────

    public function testReactivatingWithAnotherOwnedRowMovesPointer(): void
    {
        $char  = $this->seedCharacter();
        $item  = $this->seedTemplate('cart', 300);
        $log1  = $this->seedLog($char, $item, 300);
        $log2  = $this->seedLog($char, $item, 300);

        $this->assertTrue($this->service->activate($char, $log1));
        $this->assertSame($log1, $this->pointerOf($char));

        $this->assertTrue($this->service->activate($char, $log2));
        $this->assertSame($log2, $this->pointerOf($char), 'после второй активации указатель должен смотреть на вторую строку');

        $active = $this->service->resolveActive($char);
        $this->assertNotNull($active);
        $this->assertSame($log2, $active['log_id']);
        $this->assertNotSame($log1, $active['log_id'], 'первая строка более не активна');
    }

    // ── Висячий указатель — самолечение ──────────────────────────────────

    public function testResolveActiveSelfHealsWhenLogRowWasDeleted(): void
    {
        $char = $this->seedCharacter();
        $item = $this->seedTemplate('cart', 300);
        $log  = $this->seedLog($char, $item, 300);

        $this->service->activate($char, $log);
        $this->conn->table('crafted_items_log')->where('id', $log)->delete();

        $result = $this->service->resolveActive($char);

        $this->assertNull($result);
        $this->assertNull($this->pointerOf($char), 'указатель обязан обнулиться при висячей ссылке');
    }

    public function testResolveActiveReturnsNullWhenPointerIsNull(): void
    {
        $char = $this->seedCharacter();

        $this->assertNull($this->service->resolveActive($char));
    }

    // ── spendCharges(): адресное списание по id ──────────────────────────

    public function testSpendChargesDeductsExactlyCellsTimesWearFromActiveRowOnly(): void
    {
        $char = $this->seedCharacter();
        $item = $this->seedTemplate('cart', 300);
        $log1 = $this->seedLog($char, $item, 300);
        $log2 = $this->seedLog($char, $item, 300); // соседняя строка того же предмета

        $this->service->activate($char, $log1);
        $remainder = $this->service->spendCharges($char, 10, 1);

        $this->assertSame(290, $remainder);

        $row1 = $this->conn->table('crafted_items_log')->where('id', $log1)->get()->getRowArray();
        $row2 = $this->conn->table('crafted_items_log')->where('id', $log2)->get()->getRowArray();
        $this->assertSame(290, (int) $row1['durability_count']);
        $this->assertSame(300, (int) $row2['durability_count'], 'соседняя строка того же предмета не должна быть тронута');
    }

    public function testSpendChargesRespectsWearPerCellMultiplier(): void
    {
        $char = $this->seedCharacter();
        $item = $this->seedTemplate('snowmobile', 400);
        $log  = $this->seedLog($char, $item, 400);
        $this->service->activate($char, $log);

        $remainder = $this->service->spendCharges($char, 5, 2); // 5 клеток × 2 заряда/клетку

        $this->assertSame(390, $remainder);
    }

    /**
     * Исторический мусор (durability_count больше базы шаблона) не даёт остаток
     * выше базы, а отрицательный мусор не уходит в минус — зажим `min(dur, base)`.
     */
    public function testSpendChargesClampsGarbageDurabilityToTemplateBase(): void
    {
        $char = $this->seedCharacter();
        $item = $this->seedTemplate('cart', 50); // база меньше исторического мусора
        $log  = $this->seedLog($char, $item, 100); // мусор 100 > базы 50
        $this->service->activate($char, $log);

        $remainder = $this->service->spendCharges($char, 0, 1); // не тратим — читаем зажатый остаток

        $this->assertSame(50, $remainder, 'зажатый остаток не должен превышать базу шаблона');
    }

    public function testSpendChargesNeverGoesNegative(): void
    {
        $char = $this->seedCharacter();
        $item = $this->seedTemplate('cart', 300);
        $log  = $this->seedLog($char, $item, 300);
        $this->service->activate($char, $log);

        $remainder = $this->service->spendCharges($char, 1000, 1); // спишет намного больше остатка

        $this->assertSame(0, $remainder);
        $this->assertGreaterThanOrEqual(0, $remainder);
    }

    // ── Ноль зарядов — поход не блокируется ──────────────────────────────

    public function testResolveActiveOnZeroChargesReturnsRowWithZeroChargesNoException(): void
    {
        $char = $this->seedCharacter();
        $item = $this->seedTemplate('cart', 300);
        $log  = $this->seedLog($char, $item, 0); // полностью изношена
        $this->service->activate($char, $log);

        $result = $this->service->resolveActive($char);

        $this->assertNotNull($result, 'изношенная машина остаётся активной строкой, поход не блокируется');
        $this->assertSame(0, $result['charges']);
    }

    // ── breakActive(): «разбивается, но не пропадает» ────────────────────

    public function testBreakActiveZeroesDurabilityClearsPointerKeepsRow(): void
    {
        $char = $this->seedCharacter();
        $item = $this->seedTemplate('cart', 300);
        $log  = $this->seedLog($char, $item, 250, quantity: 3);
        $this->service->activate($char, $log);

        $this->service->breakActive($char);

        $this->assertNull($this->pointerOf($char));

        $row = $this->conn->table('crafted_items_log')->where('id', $log)->get()->getRowArray();
        $this->assertNotNull($row, 'строка crafted_items_log не должна удаляться — «разбивается, но не пропадает»');
        $this->assertSame(0, (int) $row['durability_count']);
        $this->assertSame(3, (int) $row['quantity'], 'quantity не меняется — это не изъятие');
    }

    public function testBreakActiveWithNoActiveVehicleIsNoop(): void
    {
        $char = $this->seedCharacter();

        $this->service->breakActive($char);

        $this->assertNull($this->pointerOf($char));
    }

    // ── deactivate(): из любого состояния ────────────────────────────────

    public function testDeactivateWorksWhenAlreadyNull(): void
    {
        $char = $this->seedCharacter();

        $this->service->deactivate($char);

        $this->assertNull($this->pointerOf($char));
    }

    public function testDeactivateClearsAnActivePointer(): void
    {
        $char = $this->seedCharacter();
        $item = $this->seedTemplate('cart', 300);
        $log  = $this->seedLog($char, $item, 300);
        $this->service->activate($char, $log);

        $this->service->deactivate($char);

        $this->assertNull($this->pointerOf($char));
    }

    // ── WipeManifest / WipeService: CHARACTER_RESET обнуляет указатель ────

    public function testCharacterResetNullsActiveVehiclePointer(): void
    {
        $char = $this->seedCharacter();
        $item = $this->seedTemplate('cart', 300);
        $log  = $this->seedLog($char, $item, 300);
        $this->service->activate($char, $log);
        $this->assertSame($log, $this->pointerOf($char));

        $manifest = new class () extends WipeManifest {
            public function __construct()
            {
                $this->tables = [
                    'characters' => ['strategy' => self::CHARACTER_RESET, 'note' => 'test'],
                ];
                $this->characterResetValues = [
                    'level'                  => 1,
                    'active_vehicle_log_id'  => null,
                ];
                // min_y недостижимо высокий — гарантированно 0 кандидатов на респавн,
                // без пустого массива в whereIn() (edge-case билдера).
                $this->characterRespawn = ['min_y' => 999999999, 'biomes' => [1]];
            }
        };

        $wipe = new WipeService($manifest, $this->conn);
        $wipe->resetCharacter($char);

        $this->assertNull($this->pointerOf($char), 'указатель активного транспорта обязан стать NULL после сброса персонажа');
    }
}
