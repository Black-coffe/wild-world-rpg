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

    // `game_settings` — только для тестов charges_full (ревью-находка 2026-08-20):
    // своя изолированная таблица, как в RewardServiceTest/LootTableServiceTest,
    // не общая тестовая схема.
    private const TABLES = ['characters', 'crafted_items_log', 'crafted_items', 'map', 'game_settings'];

    private \CodeIgniter\Database\BaseConnection $conn;
    private VehicleActivationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();

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
        // Схема — по образцу CreateGameSettingsTable/RewardServiceTest: те же колонки,
        // которые читает GameSettingsService::get() (тип+value_int тут и достаточны).
        $this->conn->query('
            CREATE TABLE game_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(64) NOT NULL,
                category VARCHAR(32) NOT NULL,
                value_type VARCHAR(16) NOT NULL,
                value_int INT NULL,
                value_float DECIMAL(12,4) NULL,
                value_bool TINYINT(1) NULL,
                value_string VARCHAR(255) NULL,
                default_value_text TEXT NOT NULL,
                rationale_text TEXT NOT NULL,
                effect_text TEXT NOT NULL,
                above_effect_text TEXT NOT NULL,
                below_effect_text TEXT NOT NULL,
                recommended_min VARCHAR(64) NULL,
                recommended_max VARCHAR(64) NULL,
                hard_min VARCHAR(64) NULL,
                hard_max VARCHAR(64) NULL,
                updated_by VARCHAR(128) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY setting_key (setting_key)
            )
        ');

        $this->service = new VehicleActivationService($this->conn);
    }

    protected function tearDown(): void
    {
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

    /** Сеет `world.vehicle.<key>.charges_full` — только int-поля нужны сервису. */
    private function seedChargesFull(string $vehicleKey, int $value): void
    {
        $this->conn->table('game_settings')->insert([
            'setting_key'        => "world.vehicle.{$vehicleKey}.charges_full",
            'category'           => 'world',
            'value_type'         => 'int',
            'value_int'          => $value,
            'default_value_text' => (string) $value,
            'rationale_text'     => 'test',
            'effect_text'        => 'test',
            'above_effect_text'  => 'test',
            'below_effect_text'  => 'test',
        ]);
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

    // ── Ревью-находка 2026-08-20: единственный источник ёмкости — charges_full ──

    /**
     * 🔴 Продовые каталожные числа (не «удобные» 300/400): `crafted_items.durability_count`
     * для этих пяти машин на проде реально 120/150/100/200/80, а НЕ значения GameSettings
     * `charges_full` (300/350/400/400/350) — источники разошлись (ревью-находка). Строка
     * лога несёт исторический остаток 250 — между каталожным (100) и charges_full (400):
     * старый код клэмпил по каталогу → 100, новый обязан клэмпить по charges_full → 250,
     * доказывая, что клэмп больше не читает `crafted_items.durability_count`.
     */
    public function testResolveActiveClampsToChargesFullNotCatalogColumn(): void
    {
        $char = $this->seedCharacter();
        $item = $this->seedTemplate('Snowmobile', 100); // прод: реальная каталожная ёмкость снегохода
        $this->seedChargesFull('snowmobile', 400);       // прод: реальный admin-tunable дефолт
        $log  = $this->seedLog($char, $item, 250);
        $this->service->activate($char, $log);

        $result = $this->service->resolveActive($char);

        $this->assertNotNull($result);
        $this->assertSame(400, $result['charges_full'], 'база обязана быть charges_full, не каталогом (100)');
        $this->assertSame(250, $result['charges'], 'остаток не должен зажиматься к каталожным 100');
    }

    /** Симметричная проверка на `spendCharges()` — то же несоответствие каталог/charges_full. */
    public function testSpendChargesUsesChargesFullNotCatalogBase(): void
    {
        $char = $this->seedCharacter();
        $item = $this->seedTemplate('Snowmobile', 100); // прод: каталог
        $this->seedChargesFull('snowmobile', 400);       // прод: charges_full
        $log  = $this->seedLog($char, $item, 390);
        $this->service->activate($char, $log);

        $remainder = $this->service->spendCharges($char, 5, 2); // 5 клеток × 2 заряда/клетку

        $this->assertSame(380, $remainder, 'при базе-каталоге (100) остаток был бы зажат до старта списания');
    }

    /**
     * Свежескрафченная машина (остаток == charges_full, реальный прод-сценарий LightCart)
     * зажимается ровно к 300 — это база, которую `VehicleAction::savingsMinutes()` берёт
     * как `chargesFull`, поэтому «пройдено клеток» = chargesFull − charges = 0, окупаемость
     * ноль, а не выдуманные клетки от рассинхрона источников.
     */
    public function testResolveActiveFreshVehicleAtFullChargesFullShowsNoImaginaryWear(): void
    {
        $char = $this->seedCharacter();
        $item = $this->seedTemplate('LightCart', 120); // прод: каталог (не совпадает с charges_full)
        $this->seedChargesFull('cart', 300);            // прод: charges_full
        $log  = $this->seedLog($char, $item, 300);      // свежая — заряд равен базе charges_full
        $this->service->activate($char, $log);

        $result = $this->service->resolveActive($char);

        $this->assertNotNull($result);
        $this->assertSame(300, $result['charges_full']);
        $this->assertSame(300, $result['charges'], 'свежая машина обязана показывать полный заряд по charges_full');
        $this->assertSame(0, $result['charges_full'] - $result['charges'], 'ноль пройденных клеток — окупаемость обязана быть 0');
    }

    /** Нетранспортный предмет (нет ключа в NAME_ENG_TO_KEY) — база остаётся каталогом. */
    public function testResolveActiveNonVehicleItemStillClampsToCatalog(): void
    {
        $char = $this->seedCharacter();
        $item = $this->seedTemplate('MedKit', 50); // не входит в VehicleEffectsService::NAME_ENG_TO_KEY
        $log  = $this->seedLog($char, $item, 200); // мусор выше каталожной базы
        $this->service->activate($char, $log);

        $result = $this->service->resolveActive($char);

        $this->assertNotNull($result);
        $this->assertSame(50, $result['charges_full']);
        $this->assertSame(50, $result['charges'], 'без GameSettings-ключа базой остаётся каталог');
    }
}
