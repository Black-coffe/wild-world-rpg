<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Tasks\ActiveTasksService;
use App\Services\World\MarchService;
use App\TaskHandlers\MarchingTaskHandler;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * s1-atomicity-05 — поле (Поход) пишет 🔒-строки `character_tasks` через ядро слота:
 * вставка — `insertExclusiveTaskRow()`, возобновление `paused` — `moveToInWork()`.
 *
 * Гонка: старт Похода прошёл гейт ADR-167 (слот пуст), а второе соединение успевает поставить
 * свою 🔒-задачу до вставки строки Похода. БД (`UNIQUE uq_ct_exclusive_slot`) пускает одну;
 * проигравший получает текст отказа ADR-167, а не исключение и не вторую строку `in_work`.
 * Колонки и индекс S1 — вызовом `up()` миграции story 01, базовые таблицы — рукописные.
 *
 * @internal
 */
final class FieldTaskExclusiveSlotTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const RED_REASON = 'field exclusive task slot is not held on the march path';

    private const MIGRATION = '2026-12-25-100000_S1CharacterTasksExclusiveSlot';

    private const LOCK_CACHE_KEY = 'game_settings_craft_exclusive_lock_enabled';

    private const TABLES = [
        'characters', 'map', 'biomes', 'claimed_cells', 'tasks', 'telegram_users', 'explored_cells',
        'character_tasks', 'player_detection_history', 'biome_world_object_map', 'world_objects', 'npc_spawns',
    ];

    private BaseConnection $conn;

    private int $charId        = 0;
    private int $gatherTaskId  = 0;
    private int $marchTaskId   = 0;

    /** @var (callable(object): void)|null */
    private $listener = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->conn = Database::connect();
        $this->dropTables();

        $this->conn->query('CREATE TABLE characters (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, telegram_user_id INT NOT NULL DEFAULT 0,
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
        $this->conn->query('CREATE TABLE tasks (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, name_rus VARCHAR(255) NULL,
            type VARCHAR(50) NULL, parallel_execution_allowed TINYINT NOT NULL DEFAULT 1)');
        $this->conn->query('CREATE TABLE telegram_users (id INT AUTO_INCREMENT PRIMARY KEY, telegram_id BIGINT NOT NULL)');
        $this->conn->query('CREATE TABLE explored_cells (
            id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NOT NULL, telegram_user_id INT NULL,
            map_cell_id INT NOT NULL, biome_id INT NOT NULL, character_level INT NULL, cell_status VARCHAR(255) NULL,
            notes TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->conn->query("CREATE TABLE character_tasks (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            character_id INT UNSIGNED NOT NULL,
            telegram_user_id BIGINT NULL,
            task_id INT UNSIGNED NOT NULL,
            start_time DATETIME NOT NULL, end_time DATETIME NULL,
            status ENUM('in_work','completed','interrupted','queued','paused') NOT NULL DEFAULT 'in_work',
            task_settings TEXT NULL,
            created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)");
        $this->conn->query('CREATE TABLE player_detection_history (id INT AUTO_INCREMENT PRIMARY KEY, detector_player_id INT NOT NULL, detected_player_id INT NOT NULL, detected_at DATETIME NULL)');
        $this->conn->query('CREATE TABLE biome_world_object_map (id INT AUTO_INCREMENT PRIMARY KEY, map_id INT NOT NULL, biome_id INT NOT NULL, world_object_id INT NOT NULL, status VARCHAR(50) NOT NULL)');
        $this->conn->query('CREATE TABLE world_objects (id INT AUTO_INCREMENT PRIMARY KEY, name_en VARCHAR(255) NOT NULL)');
        $this->conn->query('CREATE TABLE npc_spawns (id INT AUTO_INCREMENT PRIMARY KEY, coordinate_x INT NOT NULL, coordinate_y INT NOT NULL, status VARCHAR(50) NOT NULL)');

        $this->conn->query("INSERT INTO biomes (id, name, danger_level) VALUES (1, 'Лес', 1)");
        $this->conn->query('INSERT INTO telegram_users (id, telegram_id) VALUES (7, 555000111)');
        $this->conn->table('tasks')->insert(['name' => 'Gather', 'name_rus' => 'Добыча ресурсов', 'type' => 'optionally', 'parallel_execution_allowed' => 0]);
        $this->gatherTaskId = (int) $this->conn->insertID();
        $this->conn->table('tasks')->insert(['name' => 'Marching', 'name_rus' => 'Поход', 'type' => 'optionally', 'parallel_execution_allowed' => 0]);
        $this->marchTaskId = (int) $this->conn->insertID();

        // Сетка 10×10 (cell_number = (y-1)*10 + x), биом 1 везде.
        for ($y = 1; $y <= 10; $y++) {
            for ($x = 1; $x <= 10; $x++) {
                $this->conn->query('INSERT INTO map (cell_number, coordinate_x, coordinate_y, biome_id) VALUES (?, ?, ?, 1)', [($y - 1) * 10 + $x, $x, $y]);
            }
        }
        // Персонаж на (5, 5) → cell_number 45.
        $this->conn->query("INSERT INTO characters (telegram_user_id, name, cell_number, biome_id, level) VALUES (7, 'Hero', 45, 1, 5)");
        $this->charId = (int) $this->conn->insertID();

        service('cache')->save(self::LOCK_CACHE_KEY, ['v' => true, 't' => 'bool'], 60);
        $this->migration()->up();
        $this->conn->resetDataCache();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->listener !== null) {
                Events::removeListener('DBQuery', $this->listener);
                $this->listener = null;
            }
            service('cache')->delete(self::LOCK_CACHE_KEY);
            $this->dropTables();
        } finally {
            parent::tearDown();
        }
    }

    public function testTwoConcurrentMarchStartsLetExactlyOneThrough(): void
    {
        $other = Database::connect('tests', false);
        $this->assertInstanceOf(BaseConnection::class, $other);
        $second    = new ActiveTasksService($other);
        $secondRow = $this->row($this->gatherTaskId, 'in_work');

        // Старт Похода уже прошёл гейт ADR-167; второе соединение встаёт между гейтом и вставкой.
        $fired          = false;
        $secondOutcome  = null;
        $this->listener = static function (object $query) use (&$fired, &$secondOutcome, $second, $secondRow): void {
            if ($fired || ! $query instanceof Query || ! str_contains($query->getQuery(), "name = 'Marching'")) {
                return;
            }
            $fired         = true;
            $secondOutcome = $second->insertExclusiveTaskRow($secondRow);
        };
        Events::on('DBQuery', $this->listener);

        try {
            $out = (new MarchService())->start($this->charId, 'north', 3);
        } catch (\Throwable $e) {
            $this->fail(self::RED_REASON . ': start threw ' . $e->getMessage());
        }

        $this->assertTrue($fired, self::RED_REASON . ': the second start never interleaved');
        $this->assertFalse($out['ok'], self::RED_REASON . ': the losing march start reported success');
        $this->assertSame(MarchService::BUSY, $out['code'], self::RED_REASON);
        $this->assertStringContainsString('Сейчас занят: Добыча ресурсов', $out['message'], self::RED_REASON);
        $this->assertSame(1, $this->countRows('in_work'), self::RED_REASON);
        $this->assertSame(0, $this->countMarch('in_work'), self::RED_REASON);
    }

    public function testResumeOfPausedMarchIsRefusedWhileAnotherExclusiveTaskWorks(): void
    {
        $paused = $this->insertRaw($this->marchTaskId, 'paused');
        $this->insertRaw($this->gatherTaskId, 'in_work');

        try {
            $out = (new MarchService())->resume($this->charId);
        } catch (\Throwable $e) {
            $this->fail(self::RED_REASON . ': resume threw ' . $e->getMessage());
        }

        $this->assertFalse($out['ok'], self::RED_REASON . ': paused march resumed over a busy slot');
        $this->assertSame(MarchService::BUSY, $out['code'], self::RED_REASON);
        $this->assertStringContainsString('Сейчас занят: Добыча ресурсов', $out['message'], self::RED_REASON);
        $this->assertSame('paused', $this->taskStatus($paused), self::RED_REASON);
    }

    public function testResumeWithoutBlockerStillWorks(): void
    {
        $paused = $this->insertRaw($this->marchTaskId, 'paused');

        $out = (new MarchService())->resume($this->charId);

        $this->assertTrue($out['ok']);
        $this->assertSame(MarchService::RESUMED, $out['code']);
        $this->assertSame('in_work', $this->taskStatus($paused));
    }

    public function testNextMarchStepIsNotCreatedWhileSlotIsTaken(): void
    {
        $this->insertRaw($this->gatherTaskId, 'in_work');
        $taskId = $this->insertRaw($this->marchTaskId, 'completed', json_encode([
            'heading' => 'north', 'steps_planned' => 5, 'steps_done' => 0, 'msg_chat_id' => 555000111, 'msg_id' => 999,
        ]) ?: '{}');
        $task = $this->conn->query('SELECT * FROM character_tasks WHERE id = ?', [$taskId])->getRowArray();
        $this->assertIsArray($task);

        $handler = new FieldSlotMarchingHandler(new FieldSlotNoDetector(), new FieldSlotNoTowerAlerts(), new FieldSlotNoObjectSignal());
        try {
            $handler->handle($task);
        } catch (\Throwable $e) {
            $this->fail(self::RED_REASON . ': handler threw ' . $e->getMessage());
        }

        $this->assertSame(0, $this->countMarch('in_work'), self::RED_REASON . ': next march step created over a busy slot');
        $this->assertSame(1, $this->countRows('in_work'), self::RED_REASON);
        $this->assertSame(1, $handler->deliveries, self::RED_REASON);
        $this->assertStringContainsString('Сейчас занят: Добыча ресурсов', $handler->lastText, self::RED_REASON);
    }

    public function testNextMarchStepIsCreatedWhenSlotIsFree(): void
    {
        $taskId = $this->insertRaw($this->marchTaskId, 'completed', json_encode([
            'heading' => 'north', 'steps_planned' => 5, 'steps_done' => 0,
        ]) ?: '{}');
        $task = $this->conn->query('SELECT * FROM character_tasks WHERE id = ?', [$taskId])->getRowArray();
        $this->assertIsArray($task);

        $handler = new FieldSlotMarchingHandler(new FieldSlotNoDetector(), new FieldSlotNoTowerAlerts(), new FieldSlotNoObjectSignal());
        $handler->handle($task);

        $this->assertSame(1, $this->countMarch('in_work'));
        $row = $this->conn->query("SELECT is_exclusive FROM character_tasks WHERE task_id = ? AND status = 'in_work'", [$this->marchTaskId])->getRowArray();
        $this->assertSame(1, (int) ($row['is_exclusive'] ?? 0), self::RED_REASON . ': next march step does not take the slot');
    }

    private function migration(): Migration
    {
        require_once APPPATH . 'Database/Migrations/' . self::MIGRATION . '.php';
        $class = 'App\\Database\\Migrations\\' . substr(self::MIGRATION, 18);
        $m     = new $class(Database::forge());
        $this->assertInstanceOf(Migration::class, $m);

        return $m;
    }

    /** @return array<string, mixed> */
    private function row(int $taskId, string $status): array
    {
        return [
            'character_id'     => $this->charId,
            'telegram_user_id' => 7,
            'task_id'          => $taskId,
            'start_time'       => date('Y-m-d H:i:s'),
            'end_time'         => date('Y-m-d H:i:s', time() + 600),
            'status'           => $status,
        ];
    }

    /** Строка с флагом 🔒 как у прода после миграции — мимо проверяемого кода. */
    private function insertRaw(int $taskId, string $status, ?string $settings = null): int
    {
        $this->conn->query(
            'INSERT INTO character_tasks (character_id, telegram_user_id, task_id, start_time, end_time, status, task_settings, is_exclusive, created_at, updated_at)
             VALUES (?, 7, ?, NOW(), DATE_ADD(NOW(), INTERVAL 10 MINUTE), ?, ?, 1, NOW(), NOW())',
            [$this->charId, $taskId, $status, $settings]
        );

        return (int) $this->conn->insertID();
    }

    private function countRows(string $status): int
    {
        $row = $this->conn->query('SELECT COUNT(*) AS c FROM character_tasks WHERE character_id = ? AND status = ?', [$this->charId, $status])->getRowArray();

        return (int) ($row['c'] ?? 0);
    }

    private function countMarch(string $status): int
    {
        $row = $this->conn->query(
            'SELECT COUNT(*) AS c FROM character_tasks WHERE character_id = ? AND task_id = ? AND status = ?',
            [$this->charId, $this->marchTaskId, $status]
        )->getRowArray();

        return (int) ($row['c'] ?? 0);
    }

    private function taskStatus(int $id): string
    {
        $row = $this->conn->query('SELECT status FROM character_tasks WHERE id = ?', [$id])->getRowArray();

        return is_array($row) && is_string($row['status'] ?? null) ? $row['status'] : '';
    }

    private function dropTables(): void
    {
        foreach (self::TABLES as $t) {
            $this->conn->query("DROP TABLE IF EXISTS `{$t}`");
        }
        $this->conn->resetDataCache();
    }
}

/**
 * Доставка Telegram подменена записью; остальная логика шага — настоящая, одна клетка за тик.
 *
 * @internal
 */
final class FieldSlotMarchingHandler extends MarchingTaskHandler
{
    public int $deliveries = 0;
    public string $lastText = '';

    /** @param array<string, mixed> $vehicleProfile */
    protected function cellsPerTick(array $vehicleProfile = []): int
    {
        return 1;
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

/** @internal */
final class FieldSlotNoDetector extends \App\Services\Player\PlayerDetectionService
{
    public function detectNearbyPlayers(int $characterId): bool
    {
        return false;
    }
}

/** @internal */
final class FieldSlotNoTowerAlerts extends \App\Services\PVE\TowerAlertService
{
    public function notifyTowersNear(int $moverId, int $newX, int $newY): int
    {
        return 0;
    }
}

/** @internal */
final class FieldSlotNoObjectSignal extends \App\Services\World\ObjectSignalService
{
    public function signalNearbyObjects(int $moverId, int $newX, int $newY): int
    {
        return 0;
    }
}
