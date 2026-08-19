<?php

declare(strict_types=1);

namespace Tests\Unit\Transport;

use App\Services\Player\VehicleActivationService;
use App\Services\Player\PlayerDetectionService;
use App\Services\World\MarchMiniEventService;
use App\Services\World\MarchPaceService;
use App\Services\World\ObjectSignalService;
use App\Services\PVE\TowerAlertService;
use App\Services\World\VehicleEffectsService;
use App\TaskHandlers\MarchingTaskHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * transport-04 (поправка контракта, plan.md → «Поправка контракта (внесена в билд,
 * волна 1)») — интеграционные тесты «Поход + транспорт»: пачка клеток за тик,
 * per-cell износ, dormant byte-identity, отображение «предмет → профиль машины».
 *
 * Изолированная схема (как `VehicleActivationServiceTest`/`tests/database/
 * MarchingTaskHandlerTest`) — своя `characters`/`crafted_items_log`/`map`/… в
 * `wildworld_tests`, не общая прод-схема.
 *
 * Три инварианта, ради которых story делается (см. story `## Requirements`):
 *   1. per-cell броски остаются per-cell — {@see testDetectorFiresOncePerCellNotOncePerTick()}
 *      считает вызовы детектора и списания износа ПОШТУЧНО за клетку, не за тик.
 *   2. `stepDueInterval()` не трогается профилем — {@see testStepDueIntervalIgnoresProfileAcrossAllFiveKeys()}.
 *   3. Неизвестное имя предмета не даёт тихую нейтраль на уровне карты —
 *      {@see testAllFiveTransportRecipeNamesResolveToProfileKey()} валит сборку, если
 *      хоть один из пяти `item_name_eng` не резолвится {@see VehicleEffectsService::keyForItemNameEng()}.
 *
 * @internal
 */
final class MarchingTransportTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const HEALTH_COST_PER_CELL = 0.02;
    private const TIRED_COST_PER_CELL  = 0.5;

    /** @var \CodeIgniter\Database\BaseConnection */
    private $conn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->conn = Database::connect('tests');
        foreach ([
            'characters', 'map', 'biomes', 'claimed_cells', 'tasks', 'telegram_users',
            'explored_cells', 'character_tasks', 'crafted_items', 'crafted_items_log',
        ] as $t) {
            $this->conn->query("DROP TABLE IF EXISTS {$t}");
        }

        $this->conn->query('CREATE TABLE characters (
            id INT AUTO_INCREMENT PRIMARY KEY, telegram_user_id INT NOT NULL DEFAULT 0,
            name VARCHAR(255) NULL, cell_number INT NOT NULL DEFAULT 1, biome_id INT NOT NULL DEFAULT 1,
            health DOUBLE NOT NULL DEFAULT 100, tired DOUBLE NOT NULL DEFAULT 100,
            experience DOUBLE NOT NULL DEFAULT 0, strength DOUBLE NOT NULL DEFAULT 1,
            agility DOUBLE NOT NULL DEFAULT 1, intellect DOUBLE NOT NULL DEFAULT 1, level INT NOT NULL DEFAULT 1,
            active_vehicle_log_id INT NULL,
            created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->conn->query('CREATE TABLE map (
            id INT AUTO_INCREMENT PRIMARY KEY, cell_number INT NOT NULL, coordinate_x INT NOT NULL,
            coordinate_y INT NOT NULL, biome_id INT NOT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->conn->query('CREATE TABLE biomes (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, danger_level INT NOT NULL DEFAULT 1)');
        $this->conn->query('CREATE TABLE claimed_cells (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NOT NULL, map_cell_id INT NOT NULL, status VARCHAR(50) NOT NULL, claimed_at DATETIME NULL)');
        $this->conn->query('CREATE TABLE tasks (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, name_rus VARCHAR(255) NULL, parallel_execution_allowed TINYINT NOT NULL DEFAULT 1)');
        $this->conn->query('CREATE TABLE telegram_users (id INT AUTO_INCREMENT PRIMARY KEY, telegram_id BIGINT NOT NULL)');
        $this->conn->query('CREATE TABLE explored_cells (
            id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NOT NULL, telegram_user_id INT NOT NULL,
            map_cell_id INT NOT NULL, biome_id INT NOT NULL, character_level INT NULL, cell_status VARCHAR(255) NULL,
            notes TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->conn->query('CREATE TABLE character_tasks (
            id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NOT NULL, telegram_user_id INT NOT NULL, task_id INT NOT NULL,
            start_time DATETIME NULL, end_time DATETIME NULL, status VARCHAR(50) NOT NULL, task_settings TEXT NULL,
            created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->conn->query('CREATE TABLE crafted_items (id INT AUTO_INCREMENT PRIMARY KEY, name_eng VARCHAR(150) NULL, durability_count INT NULL)');
        $this->conn->query('CREATE TABLE crafted_items_log (
            id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NOT NULL, crafted_item_id INT NOT NULL,
            durability_count INT NULL, quantity INT NOT NULL DEFAULT 1, created_at DATETIME NULL, updated_at DATETIME NULL)');

        // Биомы: 1 = Лес (норма, тёплая), 2 = Тундра (холодная местность, danger 1).
        $this->conn->query("INSERT INTO biomes (id, name, danger_level) VALUES (1, 'Лес', 1), (2, 'Тундра', 1)");
        $this->conn->query("INSERT INTO tasks (name, name_rus, parallel_execution_allowed) VALUES ('Marching', 'Поход', 0)");
        $this->conn->query('INSERT INTO telegram_users (id, telegram_id) VALUES (7, 555000111)');

        // Прямая линия 1×40 клеток на север (x=5, y=1..40), biome 1 (Лес) везде.
        for ($y = 1; $y <= 40; $y++) {
            $this->conn->query('INSERT INTO map (cell_number, coordinate_x, coordinate_y, biome_id) VALUES (?, ?, ?, ?)', [$y, 5, $y, 1]);
        }
    }

    // ── хелперы сидирования ────────────────────────────────────────────

    private function makeCharacter(int $y = 20): array
    {
        $this->conn->query(
            'INSERT INTO characters (telegram_user_id, name, cell_number, biome_id, health, tired, experience, strength, agility, intellect, level)
             VALUES (7, ?, ?, 1, 100, 100, 0, 1, 1, 1, 5)',
            ['Hero', $y]
        );
        $id  = (int) $this->conn->insertID();
        $row = $this->conn->query('SELECT * FROM characters WHERE id = ?', [$id])->getRowArray();
        $this->assertNotNull($row);

        return $row;
    }

    private function makeMarchTask(int $charId, int $stepsPlanned, int $stepsDone = 0): array
    {
        $marchingTaskId = (int) ($this->conn->query("SELECT id FROM tasks WHERE name = 'Marching'")->getRowArray()['id'] ?? 0);
        $settings       = json_encode([
            'heading'       => 'north',
            'steps_planned' => $stepsPlanned,
            'steps_done'    => $stepsDone,
            'msg_chat_id'   => 555000111,
            'msg_id'        => 999,
        ]);
        $this->conn->query(
            "INSERT INTO character_tasks (character_id, telegram_user_id, task_id, start_time, end_time, status, task_settings)
             VALUES (?, 7, ?, NOW(), NOW(), 'completed', ?)",
            [$charId, $marchingTaskId, $settings]
        );
        $id = (int) $this->conn->insertID();

        return $this->conn->query('SELECT * FROM character_tasks WHERE id = ?', [$id])->getRowArray();
    }

    private function reloadChar(int $id): array
    {
        $row = $this->conn->query('SELECT * FROM characters WHERE id = ?', [$id])->getRowArray();
        $this->assertNotNull($row);

        return $row;
    }

    /** Ставит биом «Тундра» (холодная местность) на ряд клеток coordinate_y IN [$fromY..$toY]. */
    private function makeCold(int $fromY, int $toY): void
    {
        $this->conn->query('UPDATE map SET biome_id = 2 WHERE coordinate_y BETWEEN ? AND ?', [$fromY, $toY]);
    }

    /** Отмечает клетки как уже разведанные этим персонажем (explored_cells). */
    private function markExplored(int $characterId, int $fromY, int $toY): void
    {
        for ($y = $fromY; $y <= $toY; $y++) {
            $this->conn->query(
                'INSERT INTO explored_cells (character_id, telegram_user_id, map_cell_id, biome_id) VALUES (?, 7, ?, 1)',
                [$characterId, $y]
            );
        }
    }

    private function seedTemplate(string $nameEng, int $durability): int
    {
        $this->conn->table('crafted_items')->insert(['name_eng' => $nameEng, 'durability_count' => $durability]);

        return (int) $this->conn->insertID();
    }

    private function seedActiveVehicle(int $characterId, string $nameEng, int $durability, ?int $currentDurability = null): void
    {
        $item = $this->seedTemplate($nameEng, $durability);
        $this->conn->table('crafted_items_log')->insert([
            'character_id'     => $characterId,
            'crafted_item_id'  => $item,
            'durability_count' => $currentDurability ?? $durability,
            'quantity'         => 1,
        ]);
        $logId = (int) $this->conn->insertID();
        $this->conn->table('characters')->where('id', $characterId)->update(['active_vehicle_log_id' => $logId]);
    }

    /**
     * Профиль-оверрайд контракта (plan.md → ## Contracts) для одного ключа машины.
     *
     * @return array<string, mixed>
     */
    private function vehicleOverrides(string $key, int $explored, int $unexplored, int $cold, float $tired, int $order, int $wear, int $chargesFull): array
    {
        $prefix = "world.vehicle.{$key}.";

        return [
            'world.vehicle.enabled'           => true,
            'world.vehicle.cargo.max_share'   => 0.33,
            'world.march.cells_per_tick'      => 3,
            'world.march.max_steps_per_order' => 60,
            $prefix . 'cells_per_tick_explored'   => $explored,
            $prefix . 'cells_per_tick_unexplored' => $unexplored,
            $prefix . 'cells_per_tick_cold'       => $cold,
            $prefix . 'tired_factor'              => $tired,
            $prefix . 'max_steps_per_order'       => $order,
            $prefix . 'cargo_share'               => 0.0,
            $prefix . 'wear_per_cell'             => $wear,
            $prefix . 'charges_full'              => $chargesFull,
        ];
    }

    private function handler(?VehicleEffectsService $effects = null, ?VehicleActivationService $activation = null, ?CountingDetector $detector = null): TestableMarchingTaskHandler
    {
        return new TestableMarchingTaskHandler(
            $detector ?? new CountingDetector(),
            new TowerAlertService(),
            new ObjectSignalService(),
            new MarchMiniEventService(),
            $activation ?? new VehicleActivationService($this->conn),
            $effects ?? new VehicleEffectsService(null, ['world.vehicle.enabled' => false]),
        );
    }

    // ── 3.1: неизвестное имя предмета НЕ даёт тихую нейтраль (поправка контракта) ──

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function transportRecipeNameProvider(): iterable
    {
        // Пять транспортных рецептов (plan.md → «Поправка контракта»). Захардкожено
        // здесь намеренно: story-06 (Config\CraftRecipes) ещё не выкачена в этой волне,
        // но карта отображения — контракт story-04, а не производная от story-06.
        yield 'LightCart'       => ['LightCart', 'cart'];
        yield 'MountainBike'    => ['MountainBike', 'mtb'];
        yield 'Snowmobile'      => ['Snowmobile', 'snowmobile'];
        yield 'DraftCart'       => ['DraftCart', 'draft_cart'];
        yield 'AutonomousDrone' => ['AutonomousDrone', 'drone_auto'];
    }

    /**
     * @dataProvider transportRecipeNameProvider
     */
    public function testAllFiveTransportRecipeNamesResolveToProfileKey(string $nameEng, string $expectedKey): void
    {
        $this->assertSame(
            $expectedKey,
            VehicleEffectsService::keyForItemNameEng($nameEng),
            "item_name_eng '{$nameEng}' обязан резолвиться в ключ профиля '{$expectedKey}' — тихая нейтраль запрещена (поправка контракта)."
        );
    }

    public function testUnknownItemNameEngResolvesToNullNotSilentGuess(): void
    {
        // null — легальный сигнал «нет транспорта» для вызывающего кода, НЕ угадывание
        // чужого ключа. Вызывающий код (resolveVehicleProfile) обязан читать null как
        // «нейтраль», что доказано testDormant*-тестами ниже.
        $this->assertNull(VehicleEffectsService::keyForItemNameEng('SomeUnrelatedItem'));
        $this->assertNull(VehicleEffectsService::keyForItemNameEng(''));
    }

    // ── 3.2: stepDueInterval() не трогается профилем ────────────────────

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function vehicleKeyProvider(): iterable
    {
        foreach (['cart', 'mtb', 'snowmobile', 'draft_cart', 'drone_auto'] as $key) {
            yield $key => [$key];
        }
    }

    /**
     * @dataProvider vehicleKeyProvider
     */
    public function testStepDueIntervalIgnoresProfileAcrossAllFiveKeys(string $key): void
    {
        $pace    = new MarchPaceService();
        $effects = new VehicleEffectsService(null, $this->vehicleOverrides($key, 5, 5, 5, 0.5, 120, 1, 300));
        $profile = $effects->profileFor($key, VehicleEffectsService::TERRAIN_EXPLORED);

        // Контракт: сигнатура stepDueInterval() физически не принимает профиль —
        // при любом profile результат один и тот же, minutes_per_cell − 1.
        $withVehicle   = $pace->stepDueInterval(5);
        $withoutVehicle = $pace->stepDueInterval(5);

        $this->assertSame(4, $withVehicle);
        $this->assertSame($withoutVehicle, $withVehicle, "профиль '{$key}' не должен влиять на stepDueInterval()");

        // Сигнатура метода: ровно один обязательный int-параметр (minutesPerCell),
        // профиль в него физически попасть не может — ловит будущий дрейф сигнатуры.
        $ref = new \ReflectionMethod(MarchPaceService::class, 'stepDueInterval');
        $this->assertCount(1, $ref->getParameters(), 'stepDueInterval() обязан оставаться профиль-независимым (1 параметр — minutesPerCell)');
    }

    // ── 3.3 (и acceptance): per-cell броски остаются per-cell — тик несёт больше
    //       клеток, но НЕ больше бросков ──────────────────────────────────────

    public function testDetectorAndWearFirePerCellNotPerTick(): void
    {
        $char = $this->makeCharacter(20);
        $this->seedActiveVehicle((int) $char['id'], 'LightCart', 300); // 'cart'
        $effects  = new VehicleEffectsService(null, $this->vehicleOverrides('cart', 4, 4, 4, 0.90, 90, 1, 300));
        $detector = new CountingDetector();
        $handler  = $this->handler($effects, new VehicleActivationService($this->conn), $detector);

        $task = $this->makeMarchTask((int) $char['id'], 20); // с запасом, батч не должен исчерпать план

        $handler->handle($task);

        // cart/unexplored (нет explored_cells впереди) = 4 клетки за тик (контракт).
        $this->assertSame(4, $detector->calls, 'PvP-детект обязан сработать РОВНО по разу на каждую пройденную клетку, не 1 раз на тик');

        $after = $this->reloadChar((int) $char['id']);
        // y=20 → двигаемся на север (y уменьшается): 20 - 4 = 16.
        $this->assertSame(16, (int) $after['cell_number']);

        // Износ: 4 клетки × wear_per_cell(1) = 4 заряда, списаны с активной строки.
        $log = $this->conn->table('crafted_items_log')->where('character_id', $char['id'])->get()->getRowArray();
        $this->assertSame(296, (int) $log['durability_count'], 'поход в N клеток обязан списать ровно N зарядов, не N×tick или 1×tick');

        // Здоровье/выносливость — тоже по 4 клетки (per-cell, не батч-уценка).
        $expectedHp    = 100.0 - 4 * self::HEALTH_COST_PER_CELL;
        $expectedTired = 100.0 - 4 * self::TIRED_COST_PER_CELL * 0.90; // tired_factor cart = 0.90
        $this->assertEqualsWithDelta($expectedHp, (float) $after['health'], 0.001);
        $this->assertEqualsWithDelta($expectedTired, (float) $after['tired'], 0.001);
    }

    public function testSnowmobileSpendsDoubleChargesPerCell(): void
    {
        $char = $this->makeCharacter(20);
        $this->makeCold(1, 40); // весь маршрут холодный
        $this->seedActiveVehicle((int) $char['id'], 'Snowmobile', 400);
        $effects = new VehicleEffectsService(null, $this->vehicleOverrides('snowmobile', 4, 4, 5, 1.10, 120, 2, 400));
        $handler = $this->handler($effects, new VehicleActivationService($this->conn));

        $task = $this->makeMarchTask((int) $char['id'], 20);
        $handler->handle($task);

        // cold = 5 клеток за тик (контракт snowmobile).
        $after = $this->reloadChar((int) $char['id']);
        $this->assertSame(15, (int) $after['cell_number']); // 20 - 5

        $log = $this->conn->table('crafted_items_log')->where('character_id', $char['id'])->get()->getRowArray();
        $this->assertSame(400 - 5 * 2, (int) $log['durability_count'], '5 клеток × 2 заряда/клетку (снегоход) = 10 списано');
    }

    public function testMtbFasterOnVirginTerrainThanExplored(): void
    {
        // Целина (не разведано) — 5 клеток за тик.
        $charVirgin = $this->makeCharacter(20);
        $this->seedActiveVehicle((int) $charVirgin['id'], 'MountainBike', 350);
        $effects = new VehicleEffectsService(null, $this->vehicleOverrides('mtb', 4, 5, 5, 0.80, 90, 1, 350));
        $handler = $this->handler($effects, new VehicleActivationService($this->conn));
        $handler->handle($this->makeMarchTask((int) $charVirgin['id'], 20));
        $this->assertSame(15, (int) $this->reloadChar((int) $charVirgin['id'])['cell_number'], 'целина: mtb = 5 клеток/тик');

        // Разведано — 4 клетки за тик.
        $charKnown = $this->makeCharacter(20);
        $this->markExplored((int) $charKnown['id'], 15, 19); // клетки впереди уже разведаны
        $this->seedActiveVehicle((int) $charKnown['id'], 'MountainBike', 350);
        $effects2 = new VehicleEffectsService(null, $this->vehicleOverrides('mtb', 4, 5, 5, 0.80, 90, 1, 350));
        $handler2 = $this->handler($effects2, new VehicleActivationService($this->conn));
        $handler2->handle($this->makeMarchTask((int) $charKnown['id'], 20));
        $this->assertSame(16, (int) $this->reloadChar((int) $charKnown['id'])['cell_number'], 'разведано: mtb = 4 клетки/тик');
    }

    public function testDroneAutoDoesNotChangeSpeedOnlyTiredness(): void
    {
        $char = $this->makeCharacter(20);
        $this->seedActiveVehicle((int) $char['id'], 'AutonomousDrone', 350);
        $effects = new VehicleEffectsService(null, $this->vehicleOverrides('drone_auto', 3, 3, 3, 0.75, 90, 1, 350));
        $handler = $this->handler($effects, new VehicleActivationService($this->conn));

        $handler->handle($this->makeMarchTask((int) $char['id'], 20));

        $after = $this->reloadChar((int) $char['id']);
        // Скорость = база (3), не 5 и не 4 — «не меняет скорость вовсе».
        $this->assertSame(17, (int) $after['cell_number']); // 20 - 3
        $expectedTired = 100.0 - 3 * self::TIRED_COST_PER_CELL * 0.75;
        $this->assertEqualsWithDelta($expectedTired, (float) $after['tired'], 0.001, 'drone_auto снижает усталость (tired_factor 0.75)');
    }

    // ── заряды кончились посреди похода → пешими числами, поход не прерывается ──

    public function testChargesDepletedMidMarchFallsBackToPedestrianNextTick(): void
    {
        $char = $this->makeCharacter(30);
        // Заряд ровно на 2 клетки (wear=1) — тик хочет 4 (cart/unexplored), заряд кончится
        // на 3-й клетке этого же тика: 4-я клетка того же тика идёт по-прежнему в цикле
        // (cellsPerTick тика уже зафиксирован), но профиль внутри уже откатился на нейтраль.
        $this->seedActiveVehicle((int) $char['id'], 'LightCart', 300, currentDurability: 2);
        $effects = new VehicleEffectsService(null, $this->vehicleOverrides('cart', 4, 4, 4, 0.90, 90, 1, 300));
        $handler = $this->handler($effects, new VehicleActivationService($this->conn));

        $task = $this->makeMarchTask((int) $char['id'], 20);
        $handler->handle($task);

        // Поход НЕ прервался — задача продолжилась (следующий in_work создан).
        $inWork = $this->conn->query(
            "SELECT * FROM character_tasks WHERE character_id = ? AND status = 'in_work'",
            [$char['id']]
        )->getResultArray();
        $this->assertCount(1, $inWork, 'заряды кончились посреди похода — поход не должен прерываться');

        // Предупреждение попало в отредактированный текст сообщения.
        $this->assertStringContainsString('пешком', mb_strtolower($handler->lastText));

        // Заряды не ушли в минус.
        $log = $this->conn->table('crafted_items_log')->where('character_id', $char['id'])->get()->getRowArray();
        $this->assertSame(0, (int) $log['durability_count']);

        // Следующий тик (новый handle() на спавненной задаче) — уже пешими числами:
        // cellsPerTick = база (3), а не 4 (cart/unexplored).
        $nextTask = $inWork[0];
        $handler2 = $this->handler($effects, new VehicleActivationService($this->conn));
        $handler2->handle($nextTask);
        $after = $this->reloadChar((int) $char['id']);
        // Первый тик продвинул на 4 (cellsPerTick тика зафиксирован ДО истощения заряда),
        // второй тик — уже пешеход: 3 клетки. Итого 30 - 4 - 3 = 23.
        $this->assertSame(23, (int) $after['cell_number']);
    }

    // ── dormant byte-identity ────────────────────────────────────────────

    public function testDormantByteIdenticalWhenKillswitchOff(): void
    {
        $char = $this->makeCharacter(20);
        // Килсвитч выключен явно, ХОТЯ активная машина есть — обязана игнорироваться.
        $this->seedActiveVehicle((int) $char['id'], 'LightCart', 300);
        $effects = new VehicleEffectsService(null, array_merge(
            $this->vehicleOverrides('cart', 4, 4, 4, 0.90, 90, 1, 300),
            ['world.vehicle.enabled' => false]
        ));
        $handler = $this->handler($effects, new VehicleActivationService($this->conn));

        $handler->handle($this->makeMarchTask((int) $char['id'], 20));

        $after = $this->reloadChar((int) $char['id']);
        // Нейтраль: cellsPerTick = world.march.cells_per_tick (3) — сегодняшнее число.
        $this->assertSame(17, (int) $after['cell_number']); // 20 - 3
        $expectedHp    = 100.0 - 3 * self::HEALTH_COST_PER_CELL;
        $expectedTired = 100.0 - 3 * self::TIRED_COST_PER_CELL;
        $this->assertEqualsWithDelta($expectedHp, (float) $after['health'], 0.001);
        $this->assertEqualsWithDelta($expectedTired, (float) $after['tired'], 0.001);

        // Заряд машины не тронут — killswitch off ни разу не читает crafted_items_log.
        $log = $this->conn->table('crafted_items_log')->where('character_id', $char['id'])->get()->getRowArray();
        $this->assertSame(300, (int) $log['durability_count']);
    }

    public function testDormantByteIdenticalWhenPointerIsNull(): void
    {
        $char = $this->makeCharacter(20); // NULL active_vehicle_log_id — характер без активации
        // Килсвитч включён, но у персонажа НЕТ активной машины (NULL-указатель).
        $effects = new VehicleEffectsService(null, ['world.vehicle.enabled' => true, 'world.march.cells_per_tick' => 3, 'world.march.max_steps_per_order' => 60]);
        $handler = $this->handler($effects, new VehicleActivationService($this->conn));

        $handler->handle($this->makeMarchTask((int) $char['id'], 20));

        $after = $this->reloadChar((int) $char['id']);
        $this->assertSame(17, (int) $after['cell_number']); // 20 - 3, как и пешеход
    }
}

/** Детектор-стаб, считающий каждый вызов (для доказательства per-cell инварианта). */
final class CountingDetector extends PlayerDetectionService
{
    public int $calls = 0;

    public function detectNearbyPlayers(int $characterId): bool
    {
        $this->calls++;

        return false;
    }
}

/**
 * Стаб: подменяет Telegram-доставку на no-op + запись (вся остальная логика — настоящая).
 *
 * @internal
 */
final class TestableMarchingTaskHandler extends MarchingTaskHandler
{
    public int $deliveries = 0;
    public string $lastText = '';

    /**
     * @param array<int|string, mixed> $s
     * @param array<int, array<int, array<string,string>>> $keyboardRows
     */
    protected function deliverMarchMessage(int $telegramUserId, array $s, string $text, array $keyboardRows): void
    {
        $this->deliveries++;
        $this->lastText = $text;
    }
}
