<?php

namespace Tests\Database;

use App\TaskHandlers\MarchingTaskHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * ADR-019 Step 3 — integration-тесты «Похода» (`MarchingTaskHandler`, цепочка
 * 1-клеточных задач).
 *
 * Покрывает:
 *   - happy step: шаг по курсу → персонаж сдвинут на 1 клетку, списаны cost,
 *     прибавлены xp/стат, раскрыто 3×3 (explored_cells), заспавнена СЛЕДУЮЩАЯ
 *     Marching-задача (in_work, end_time = now + marchMinutesPerCell, steps_done++);
 *   - last step → finish: steps_planned достигнут → следующая задача НЕ спавнится;
 *   - стопы (никакого move, никакого next-task): край мира / вода впереди /
 *     чужой активный лагерь впереди / пол выносливости;
 *   - danger-биом: доп. расход здоровья (marchDangerHealthSurcharge);
 *   - pause-on-detection: рядом другой персонаж → новый row status='paused'
 *     (steps_remaining, paused_reason='player_detected'), in_work не спавнится.
 *
 * Telegram-доставка (`deliverMarchMessage`) застаблена в TestableMarchingTaskHandler
 * (override → no-op + запись текста). `discoverObjectsAtPlayerPosition` бежит против
 * пустых biome_world_object_map/world_objects (no-op). `detectNearbyPlayers` бежит
 * по-настоящему (Telegram-send падает в TelegramException → caught, т.к. Request не
 * инициализирован).
 *
 * Примечание: idempotency-гард — это atomic-claim в `Worker` (он метит задачу
 * 'completed' ДО handle()); повторный handle() на той же задаче = двойной шаг —
 * этого Worker не допускает (flock). На уровне handler'а идемпотентности нет.
 *
 * @internal
 */
final class MarchingTaskHandlerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    // march-баланс уехал в admin GameSettings `world.march.*` (ADR-024). Тест-БД —
    // table-less (migrate=false, нет game_settings), поэтому хендлер через
    // gs()->get() отдаёт fallback-числа gsFloat/gsInt-вызовов (defensive degradation).
    // Эти консты = те же fallback-дефолты → ожидаемые значения шага.
    private const HEALTH_COST_PER_CELL = 0.02;
    private const TIRED_COST_PER_CELL  = 0.5;
    private const DANGER_SURCHARGE     = 1.0;
    private const XP_PER_CELL          = 0.03;
    private const STAT_PER_CELL        = 0.02;

    private TestableMarchingTaskHandler $handler;
    /** @var \CodeIgniter\Database\BaseConnection */
    private $conn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->conn = Database::connect('tests');
        foreach ([
            'characters', 'map', 'biomes', 'claimed_cells', 'tasks', 'telegram_users',
            'explored_cells', 'character_tasks', 'player_detection_history',
            'biome_world_object_map', 'world_objects', 'npc_spawns',
        ] as $t) {
            $this->conn->query("DROP TABLE IF EXISTS {$t}");
        }

        $this->conn->query('CREATE TABLE characters (
            id INT AUTO_INCREMENT PRIMARY KEY, telegram_user_id INT NOT NULL DEFAULT 0,
            name VARCHAR(255) NULL, cell_number INT NOT NULL DEFAULT 1, biome_id INT NOT NULL DEFAULT 1,
            health DOUBLE NOT NULL DEFAULT 100, tired DOUBLE NOT NULL DEFAULT 100,
            experience DOUBLE NOT NULL DEFAULT 0, strength DOUBLE NOT NULL DEFAULT 1,
            agility DOUBLE NOT NULL DEFAULT 1, intellect DOUBLE NOT NULL DEFAULT 1, level INT NOT NULL DEFAULT 1,
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
        $this->conn->query('CREATE TABLE player_detection_history (id INT AUTO_INCREMENT PRIMARY KEY, detector_player_id INT NOT NULL, detected_player_id INT NOT NULL, detected_at DATETIME NULL)');
        $this->conn->query('CREATE TABLE biome_world_object_map (id INT AUTO_INCREMENT PRIMARY KEY, map_id INT NOT NULL, biome_id INT NOT NULL, world_object_id INT NOT NULL, status VARCHAR(50) NOT NULL)');
        $this->conn->query('CREATE TABLE world_objects (id INT AUTO_INCREMENT PRIMARY KEY, name_en VARCHAR(255) NOT NULL)');
        $this->conn->query('CREATE TABLE npc_spawns (id INT AUTO_INCREMENT PRIMARY KEY, coordinate_x INT NOT NULL, coordinate_y INT NOT NULL, status VARCHAR(50) NOT NULL)');

        // Биомы: 1 = Лес (norm), 4 = Реки (вода), 8 = Вулкан (danger>=8).
        $this->conn->query("INSERT INTO biomes (id, name, danger_level) VALUES (1, 'Лес', 1), (4, 'Реки', 1), (8, 'Вулкан', 9)");
        // Marching task row.
        $this->conn->query("INSERT INTO tasks (name, name_rus, parallel_execution_allowed) VALUES ('Marching', 'Поход', 0)");
        // telegram_users.
        $this->conn->query('INSERT INTO telegram_users (id, telegram_id) VALUES (7, 555000111)');

        // Сетка 10×10 (cell_number = (y-1)*10 + x), biome 1 (Лес) везде.
        for ($y = 1; $y <= 10; $y++) {
            for ($x = 1; $x <= 10; $x++) {
                $this->conn->query('INSERT INTO map (cell_number, coordinate_x, coordinate_y, biome_id) VALUES (?, ?, ?, ?)', [($y - 1) * 10 + $x, $x, $y, 1]);
            }
        }

        // Детектор-стаб (по умолчанию никого не обнаруживает) — реальный
        // PlayerDetectionService на CI падает на Request не инициализирован при send.
        // cellsPerTickOverride=1 по умолчанию — per-cell тесты проверяют механику ОДНОЙ
        // клетки; батч покрыт testBatch* (ставят override). GameSettings в тестах не трогаем.
        $this->handler = new TestableMarchingTaskHandler(new StubDetector(), new StubTowerAlerts(), new StubObjectSignal());
    }

    protected function tearDown(): void
    {
        foreach ([
            'characters', 'map', 'biomes', 'claimed_cells', 'tasks', 'telegram_users',
            'explored_cells', 'character_tasks', 'player_detection_history',
            'biome_world_object_map', 'world_objects', 'npc_spawns',
        ] as $t) {
            $this->conn->query("DROP TABLE IF EXISTS {$t}");
        }
        parent::tearDown();
    }

    /** @return array<string,mixed> id => row для созданного персонажа. */
    private function makeCharacter(int $x, int $y, float $health = 100.0, float $tired = 100.0): array
    {
        $cellNumber = ($y - 1) * 10 + $x;
        $this->conn->query(
            'INSERT INTO characters (telegram_user_id, name, cell_number, biome_id, health, tired, experience, strength, agility, intellect, level)
             VALUES (7, ?, ?, 1, ?, ?, 0, 1, 1, 1, 5)',
            ['Hero', $cellNumber, $health, $tired]
        );
        $id  = (int) $this->conn->insertID();
        $row = $this->conn->query('SELECT * FROM characters WHERE id = ?', [$id])->getRowArray();
        $this->assertNotNull($row);
        return $row;
    }

    /**
     * Создаёт Marching-задачу (1-клеточную) для персонажа.
     *
     * @return array<string,mixed> запись character_tasks (status уже 'completed' —
     *                             Worker метит до handle()).
     */
    private function makeMarchTask(int $charId, string $heading, int $stepsPlanned, int $stepsDone = 0): array
    {
        $taskRow = $this->conn->query("SELECT id FROM tasks WHERE name = 'Marching'")->getRowArray();
        $marchingTaskId = (int) ($taskRow['id'] ?? 0);
        $settings = json_encode([
            'heading'       => $heading,
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
        $id  = (int) $this->conn->insertID();
        $row = $this->conn->query('SELECT * FROM character_tasks WHERE id = ?', [$id])->getRowArray();
        $this->assertNotNull($row);
        return $row;
    }

    private function reloadChar(int $id): array
    {
        $row = $this->conn->query('SELECT * FROM characters WHERE id = ?', [$id])->getRowArray();
        $this->assertNotNull($row);
        return $row;
    }

    private function countExplored(int $charId): int
    {
        return (int) ($this->conn->query('SELECT COUNT(*) AS c FROM explored_cells WHERE character_id = ?', [$charId])->getRowArray()['c'] ?? 0);
    }

    /** @return array<int, array<string,mixed>> новые character_tasks этого персонажа со статусом $status (кроме переданной задачи). */
    private function newTasks(int $charId, int $excludeTaskId, string $status): array
    {
        return $this->conn->query(
            'SELECT * FROM character_tasks WHERE character_id = ? AND id <> ? AND status = ? ORDER BY id ASC',
            [$charId, $excludeTaskId, $status]
        )->getResultArray();
    }

    // ---- happy step ----

    public function testHappyStepMovesCharRevealsHaloSpawnsNextTask(): void
    {
        $char = $this->makeCharacter(5, 5);          // cell_number = 45
        $task = $this->makeMarchTask((int) $char['id'], 'north', 5, 0);

        $this->handler->handle($task);

        // Сдвинулся на 1 клетку на север (y 5 → 4): cell_number = (4-1)*10 + 5 = 35.
        $after = $this->reloadChar((int) $char['id']);
        $this->assertSame(35, (int) $after['cell_number']);
        $this->assertEqualsWithDelta(100.0 - self::HEALTH_COST_PER_CELL, (float) $after['health'], 0.001);
        $this->assertEqualsWithDelta(100.0 - self::TIRED_COST_PER_CELL, (float) $after['tired'], 0.001);
        $this->assertEqualsWithDelta(self::XP_PER_CELL, (float) $after['experience'], 0.001);
        // stat-ротация: первый шаг (steps_done=0 % 3) → strength.
        $this->assertEqualsWithDelta(1.0 + self::STAT_PER_CELL, (float) $after['strength'], 0.001);

        // 3×3 вокруг (5,4): 9 клеток раскрыто.
        $this->assertSame(9, $this->countExplored((int) $char['id']));

        // Заспавнена следующая Marching-задача (in_work, steps_done=1).
        $next = $this->newTasks((int) $char['id'], (int) $task['id'], 'in_work');
        $this->assertCount(1, $next);
        $ns = json_decode((string) $next[0]['task_settings'], true);
        $this->assertSame(1, $ns['steps_done']);
        $this->assertSame('north', $ns['heading']);
        $this->assertGreaterThan(strtotime((string) $next[0]['start_time']) - 1, strtotime((string) $next[0]['end_time']));

        // deliverMarchMessage вызван (промежуточный экран).
        $this->assertSame(1, $this->handler->deliveries);
        // …и в нём отрисована мини-карта (маркер игрока 🙎). Регрессия: fetchCharacter
        // не выбирал `id` → TextMapService::buildMapOnly бросал "Undefined array key 'id'",
        // renderMap молча возвращал '' → марш-сообщение шло без карты (testbot v0.51.146-155).
        $this->assertStringContainsString('🙎', $this->handler->lastText);

        // Переданная задача осталась 'completed' (handler её не трогает).
        $orig = $this->conn->query('SELECT status FROM character_tasks WHERE id = ?', [$task['id']])->getRowArray();
        $this->assertSame('completed', $orig['status']);
    }

    public function testStatRotationSecondStepIsAgility(): void
    {
        $char = $this->makeCharacter(5, 5);
        $task = $this->makeMarchTask((int) $char['id'], 'south', 5, 1); // steps_done=1 → agility

        $this->handler->handle($task);

        $after = $this->reloadChar((int) $char['id']);
        $this->assertEqualsWithDelta(1.0 + self::STAT_PER_CELL, (float) $after['agility'], 0.001);
        $this->assertEqualsWithDelta(1.0, (float) $after['strength'], 0.001);
    }

    // ---- батч: несколько клеток за один тик ----

    public function testBatchAdvancesMultipleCellsPerTick(): void
    {
        $this->handler->cellsPerTickOverride = 3; // батч: 3 клетки за один handle()
        $char = $this->makeCharacter(5, 5);              // cell_number = 45
        $task = $this->makeMarchTask((int) $char['id'], 'east', 10, 0);

        $this->handler->handle($task);

        // Прошёл 3 клетки на восток (x 5→8, y=5): cell_number = (5-1)*10 + 8 = 48.
        $after = $this->reloadChar((int) $char['id']);
        $this->assertSame(48, (int) $after['cell_number']);
        // Стоимость/прирост ×3 клетки. experience ×3 доказывает батч-инвариант:
        // in-memory experience освежается между клетками (иначе 2-я затёрла бы 1-ю).
        $this->assertEqualsWithDelta(100.0 - 3 * self::HEALTH_COST_PER_CELL, (float) $after['health'], 0.001);
        $this->assertEqualsWithDelta(100.0 - 3 * self::TIRED_COST_PER_CELL, (float) $after['tired'], 0.001);
        $this->assertEqualsWithDelta(3 * self::XP_PER_CELL, (float) $after['experience'], 0.001);

        // Заспавнена следующая пачка (in_work, steps_done=3), поход не завершён (planned=10).
        $next = $this->newTasks((int) $char['id'], (int) $task['id'], 'in_work');
        $this->assertCount(1, $next);
        $ns = json_decode((string) $next[0]['task_settings'], true);
        $this->assertSame(3, $ns['steps_done']);

        // Ровно ОДНО сообщение прогресса на всю пачку (не по одному на клетку).
        $this->assertSame(1, $this->handler->deliveries);
    }

    public function testBatchStopsMidBatchOnWaterWithoutOvershoot(): void
    {
        $this->handler->cellsPerTickOverride = 5;
        // Ставим воду (biome 4) на 3-й клетке к востоку от (2,2): (5,2). Стоп ДО неё на (4,2).
        $this->conn->query('UPDATE map SET biome_id = 4 WHERE coordinate_x = 5 AND coordinate_y = 2');
        $char = $this->makeCharacter(2, 2);              // cell_number = 12
        $task = $this->makeMarchTask((int) $char['id'], 'east', 10, 0);

        $this->handler->handle($task);

        // Прошёл 2 клетки (x 2→4), уперся в реку на (5,2): cell_number = (2-1)*10 + 4 = 14.
        $after = $this->reloadChar((int) $char['id']);
        $this->assertSame(14, (int) $after['cell_number']);
        // Стоп → следующая пачка НЕ спавнится (поход окончен).
        $this->assertCount(0, $this->newTasks((int) $char['id'], (int) $task['id'], 'in_work'));
    }

    // ---- last step → finish ----

    public function testLastStepFinishesWithoutSpawningNext(): void
    {
        $char = $this->makeCharacter(5, 5);
        $task = $this->makeMarchTask((int) $char['id'], 'north', 1, 0); // planned=1, после шага done=1 == planned

        $this->handler->handle($task);

        $after = $this->reloadChar((int) $char['id']);
        $this->assertSame(35, (int) $after['cell_number']); // всё-таки сдвинулся
        $this->assertSame([], $this->newTasks((int) $char['id'], (int) $task['id'], 'in_work'));
        $this->assertSame(1, $this->handler->deliveries);
        $this->assertStringContainsString('окончен', mb_strtolower($this->handler->lastText));
    }

    // ---- стопы ----

    public function testEdgeOfWorldStopsWithoutMoving(): void
    {
        // E2: реальная карта 0..999. Toy-сетка фикстуры 1..10 живёт глубоко внутри
        // bounds-гейта, поэтому шаг на x=0 (нет клетки в сетке) ловит вторичный
        // null-cell гейт. Оба стоп-гейта — валидный край известной карты; на реальных
        // кромках (0/999) шаг наружу даёт консистентное «край мира». Инвариант теста:
        // поход останавливается БЕЗ движения и без списания cost.
        $char = $this->makeCharacter(1, 5);          // x=1 → west = x=0 (за toy-сеткой)
        $task = $this->makeMarchTask((int) $char['id'], 'west', 5, 0);

        $this->handler->handle($task);

        $after = $this->reloadChar((int) $char['id']);
        $this->assertSame((int) $char['cell_number'], (int) $after['cell_number']); // не сдвинулся
        $this->assertEqualsWithDelta(100.0, (float) $after['health'], 0.001);        // cost не списан
        $this->assertSame([], $this->newTasks((int) $char['id'], (int) $task['id'], 'in_work'));
        $txt = mb_strtolower($this->handler->lastText);
        $this->assertStringContainsString('окончен', $txt);                          // поход завершён
        $this->assertTrue(
            str_contains($txt, 'край мира') || str_contains($txt, 'дальше нет клетки'),
            'ожидали стоп на краю известного мира (bounds-гейт или null-cell гейт)'
        );
    }

    public function testWaterAheadStopsWithoutMoving(): void
    {
        $char = $this->makeCharacter(5, 5);          // cell 45
        // Делаем клетку на север (5,4 → cell 35) водой (biome 4 = Реки).
        $this->conn->query('UPDATE map SET biome_id = 4 WHERE coordinate_x = 5 AND coordinate_y = 4');
        $task = $this->makeMarchTask((int) $char['id'], 'north', 5, 0);

        $this->handler->handle($task);

        $after = $this->reloadChar((int) $char['id']);
        $this->assertSame(45, (int) $after['cell_number']); // не сдвинулся
        $this->assertSame([], $this->newTasks((int) $char['id'], (int) $task['id'], 'in_work'));
        $this->assertStringContainsString('река', mb_strtolower($this->handler->lastText));
    }

    public function testForeignActiveCampAheadStopsWithoutMoving(): void
    {
        $char = $this->makeCharacter(5, 5);
        // Клетка на север = cell_number 35; ставим там активный лагерь ДРУГОГО персонажа (id 999).
        $this->conn->query("INSERT INTO claimed_cells (character_id, map_cell_id, status) VALUES (999, 35, 'active')");
        $task = $this->makeMarchTask((int) $char['id'], 'north', 5, 0);

        $this->handler->handle($task);

        $after = $this->reloadChar((int) $char['id']);
        $this->assertSame(45, (int) $after['cell_number']);
        $this->assertSame([], $this->newTasks((int) $char['id'], (int) $task['id'], 'in_work'));
        $this->assertStringContainsString('лагерь', mb_strtolower($this->handler->lastText));
    }

    public function testOwnActiveCampAheadDoesNotBlock(): void
    {
        $char = $this->makeCharacter(5, 5);
        $this->conn->query("INSERT INTO claimed_cells (character_id, map_cell_id, status) VALUES (?, 35, 'active')", [$char['id']]);
        $task = $this->makeMarchTask((int) $char['id'], 'north', 5, 0);

        $this->handler->handle($task);

        $after = $this->reloadChar((int) $char['id']);
        $this->assertSame(35, (int) $after['cell_number']); // на свой лагерь — можно
    }

    public function testFatigueFloorStopsWithoutMoving(): void
    {
        $char = $this->makeCharacter(5, 5, 100.0, 0.3); // tired 0.3 < world.march.tired_cost_per_cell (0.5)
        $task = $this->makeMarchTask((int) $char['id'], 'north', 5, 0);

        $this->handler->handle($task);

        $after = $this->reloadChar((int) $char['id']);
        $this->assertSame(45, (int) $after['cell_number']);
        $this->assertEqualsWithDelta(0.3, (float) $after['tired'], 0.001);
        $this->assertSame([], $this->newTasks((int) $char['id'], (int) $task['id'], 'in_work'));
        $this->assertStringContainsString('сил', mb_strtolower($this->handler->lastText));
    }

    // ---- danger biome ----

    public function testDangerBiomeAppliesHealthSurcharge(): void
    {
        $char = $this->makeCharacter(5, 5);
        $this->conn->query('UPDATE map SET biome_id = 8 WHERE coordinate_x = 5 AND coordinate_y = 4'); // Вулкан, danger 9
        $task = $this->makeMarchTask((int) $char['id'], 'north', 5, 0);

        $this->handler->handle($task);

        $after = $this->reloadChar((int) $char['id']);
        $this->assertSame(35, (int) $after['cell_number']); // сдвинулся
        $expectedHp = 100.0 - (self::HEALTH_COST_PER_CELL + self::DANGER_SURCHARGE);
        $this->assertEqualsWithDelta($expectedHp, (float) $after['health'], 0.001);
    }

    // ---- pause-on-detection ----

    public function testPlayerDetectedAfterStepPausesMarch(): void
    {
        // Детектор-стаб возвращает true → после шага поход обязан встать на паузу.
        $handler = new TestableMarchingTaskHandler(new StubDetector(true), new StubTowerAlerts(), new StubObjectSignal());
        $char = $this->makeCharacter(5, 5);
        $task = $this->makeMarchTask((int) $char['id'], 'north', 5, 0);

        $handler->handle($task);

        $after = $this->reloadChar((int) $char['id']);
        $this->assertSame(35, (int) $after['cell_number']); // шаг всё-таки сделан

        // НЕ заспавнена in_work-задача; ВМЕСТО неё — paused-row.
        $this->assertSame([], $this->newTasks((int) $char['id'], (int) $task['id'], 'in_work'));
        $paused = $this->newTasks((int) $char['id'], (int) $task['id'], 'paused');
        $this->assertCount(1, $paused);
        $ps = json_decode((string) $paused[0]['task_settings'], true);
        $this->assertSame('player_detected', $ps['paused_reason']);
        $this->assertSame(4, (int) $ps['steps_remaining']); // planned 5 - done 1
        $this->assertSame(1, (int) $ps['steps_done']);
        $this->assertStringContainsString('поблизости', mb_strtolower($handler->lastText));
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
    /** Клеток за тик (seam вместо GameSettings — тесты не трогают game_settings). */
    public int $cellsPerTickOverride = 1;

    protected function cellsPerTick(): int
    {
        return $this->cellsPerTickOverride;
    }

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

/**
 * Стаб PlayerDetectionService: detectNearbyPlayers возвращает фиксированный
 * результат, не трогая Telegram (на CI Request не инициализирован → реальный
 * send падает Error'ом). Логика реакции марша на обнаружение тестируется тут;
 * сама детекция — отдельно (PlayerDetectionService).
 *
 * @internal
 */
final class StubDetector extends \App\Services\Player\PlayerDetectionService
{
    public function __construct(private bool $result = false)
    {
        parent::__construct();
    }

    public function detectNearbyPlayers(int $characterId): bool
    {
        return $this->result;
    }
}

/**
 * Стаб TowerAlertService: notifyTowersNear no-op (S26b). На CI Request не
 * инициализирован и схема character_buildings/buildings отсутствует — реальный
 * сервис всё равно вернул бы 0, но стаб убирает лишние DB-запросы/логи в марш-тестах.
 *
 * @internal
 */
final class StubTowerAlerts extends \App\Services\PVE\TowerAlertService
{
    public function __construct()
    {
        parent::__construct();
    }

    public function notifyTowersNear(int $moverId, int $newX, int $newY): int
    {
        return 0;
    }
}

/**
 * Стаб ObjectSignalService: signalNearbyObjects no-op (ADR-098). На CI Request
 * не инициализирован — стаб убирает лишние DB-запросы/Telegram-init в марш-тестах
 * (сам сервис покрыт ObjectSignalServiceTest).
 *
 * @internal
 */
final class StubObjectSignal extends \App\Services\World\ObjectSignalService
{
    public function __construct()
    {
        parent::__construct();
    }

    public function signalNearbyObjects(int $moverId, int $newX, int $newY): int
    {
        return 0;
    }
}
