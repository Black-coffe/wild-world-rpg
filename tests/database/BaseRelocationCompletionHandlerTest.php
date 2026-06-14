<?php

namespace Tests\Database;

use App\TaskHandlers\Built\BaseFullRelocationCompletionHandler;
use App\TaskHandlers\Built\BaseRelocationCompletionHandler;
use App\Entities\CharacterEntity;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * Регресс на «мёртвый переезд базы»: Worker (F0.3 atomic-claim, 2026-05-04)
 * переводит character_task в 'completed' ДО вызова completion-handler'а, а оба
 * relocation-handler'а перезапрашивали строку со status='in_work' и молча
 * выходили — база НЕ переезжала/НЕ сносилась, но кулдаун 10 дней вставал.
 *
 * Сценарий теста воспроизводит ровно это состояние: в БД задача уже 'completed',
 * хендлеру передаётся та строка, что воркер прочитал до клейма. До фикса оба
 * теста падали (база на месте), после — работают.
 *
 * Игрок-репортёр: Ярик (база застряла на X=946,Y=901 при переезде в X=843,Y=699).
 *
 * @internal
 */
final class BaseRelocationCompletionHandlerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = ['characters', 'claimed_cells', 'character_buildings', 'character_tasks', 'map', 'biomes', 'teleport_beacons'];

    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }

        $db->query('CREATE TABLE characters (
            id INT AUTO_INCREMENT PRIMARY KEY,
            telegram_user_id INT NULL,
            cell_number INT NULL,
            biome_id INT NULL,
            level INT NULL DEFAULT 1,
            created_at DATETIME NULL,
            updated_at DATETIME NULL
        )');
        $db->query('CREATE TABLE claimed_cells (
            id INT AUTO_INCREMENT PRIMARY KEY,
            character_id INT NULL,
            map_cell_id INT NULL,
            claimed_at DATETIME NULL,
            last_visited_at DATETIME NULL,
            last_warned_at DATETIME NULL,
            status VARCHAR(16) NULL DEFAULT "active"
        )');
        $db->query('CREATE TABLE character_buildings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            character_id INT NULL,
            building_id INT NULL,
            map_cell_id INT NULL,
            amount INT NULL DEFAULT 1,
            created_at DATETIME NULL,
            updated_at DATETIME NULL
        )');
        $db->query('CREATE TABLE character_tasks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            character_id INT NULL,
            telegram_user_id INT NULL,
            task_id INT NULL,
            start_time DATETIME NULL,
            end_time DATETIME NULL,
            status VARCHAR(16) NULL,
            task_settings TEXT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL
        )');
        $db->query('CREATE TABLE map (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cell_number INT NULL,
            coordinate_x INT NULL,
            coordinate_y INT NULL,
            biome_id INT NULL
        )');
        $db->query('CREATE TABLE biomes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(64) NULL,
            description VARCHAR(255) NULL,
            danger_level INT NULL,
            survival_difficulty INT NULL
        )');
        $db->query('CREATE TABLE teleport_beacons (
            id INT AUTO_INCREMENT PRIMARY KEY,
            character_id INT NULL,
            map_cell_id INT NULL
        )');
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        parent::tearDown();
    }

    /**
     * Число строк $table с character_id=$charId (+опц. map_cell_id) — типобезопасно (int).
     */
    private function rowCount(string $table, int $charId, ?int $cell = null): int
    {
        $b = Database::connect('tests')->table($table)->where('character_id', $charId);
        if ($cell !== null) {
            $b->where('map_cell_id', $cell);
        }
        $n = $b->countAllResults();
        return is_numeric($n) ? (int) $n : 0;
    }

    /**
     * Полный переезд: персонаж + база (claimed_cell) + постройки должны
     * переехать с исходной клетки 100 (X=946,Y=901) на целевую 200 (X=843,Y=699).
     */
    public function testFullRelocationMovesBaseDespiteWorkerPreCompletingTask(): void
    {
        $db = Database::connect('tests');
        // map: исходная (100) + целевая (200) клетки; id == cell_number (инвариант карты).
        $db->table('biomes')->insertBatch([
            ['id' => 1, 'name' => 'Вулканические территории', 'description' => '...', 'danger_level' => 5, 'survival_difficulty' => 5],
            ['id' => 2, 'name' => 'Пустоши', 'description' => '...', 'danger_level' => 3, 'survival_difficulty' => 3],
        ]);
        $db->table('map')->insertBatch([
            ['id' => 100, 'cell_number' => 100, 'coordinate_x' => 946, 'coordinate_y' => 901, 'biome_id' => 1],
            ['id' => 200, 'cell_number' => 200, 'coordinate_x' => 843, 'coordinate_y' => 699, 'biome_id' => 2],
        ]);
        $db->table('characters')->insert([
            'id' => 5, 'telegram_user_id' => 9, 'cell_number' => 100, 'biome_id' => 1, 'level' => 12,
        ]);
        $db->table('claimed_cells')->insert([
            'character_id' => 5, 'map_cell_id' => 100, 'claimed_at' => date('Y-m-d H:i:s'), 'status' => 'active',
        ]);
        $db->table('character_buildings')->insertBatch([
            ['character_id' => 5, 'building_id' => 1, 'map_cell_id' => 100, 'amount' => 1],
            ['character_id' => 5, 'building_id' => 2, 'map_cell_id' => 100, 'amount' => 1],
        ]);

        // Воркер уже атомарно перевёл задачу в 'completed' (F0.3) ДО хендлера.
        $settings = json_encode(['new_map_cell_id' => 200, 'source_map_cell_id' => 100, 'note' => 'X=843,Y=699']);
        $db->table('character_tasks')->insert([
            'id' => 1, 'character_id' => 5, 'telegram_user_id' => 9, 'task_id' => 42,
            'start_time' => date('Y-m-d H:i:s', time() - 90000),
            'end_time'   => date('Y-m-d H:i:s', time() - 60),
            'status'     => 'completed',
            'task_settings' => $settings,
        ]);

        // $task — строка, которую воркер прочитал ДО клейма (status='in_work').
        $task = [
            'id' => 1, 'character_id' => 5, 'telegram_user_id' => 9, 'task_id' => 42,
            'end_time' => date('Y-m-d H:i:s', time() - 60),
            'status' => 'in_work', 'task_settings' => $settings,
        ];

        (new class extends BaseFullRelocationCompletionHandler {
            /** @param array<string,mixed>|CharacterEntity $character */
            protected function notifyUser(array|CharacterEntity $character, string $msg, ?string $photoPath = null): void {}
        })->handle($task);

        $movedChar = Database::connect('tests')->table('characters')->where('id', 5)->where('cell_number', 200)->countAllResults();
        $stayedChar = Database::connect('tests')->table('characters')->where('id', 5)->where('cell_number', 100)->countAllResults();
        $this->assertSame(1, is_numeric($movedChar) ? (int) $movedChar : -1, 'Персонаж переехал на клетку 200');
        $this->assertSame(0, is_numeric($stayedChar) ? (int) $stayedChar : -1, 'Персонаж не остался на 100');

        $this->assertSame(1, $this->rowCount('claimed_cells', 5), 'По-прежнему одна база (фантом не создан)');
        $this->assertSame(1, $this->rowCount('claimed_cells', 5, 200), 'База переехала на 200');
        $this->assertSame(0, $this->rowCount('claimed_cells', 5, 100), 'На старой клетке базы нет');

        $this->assertSame(0, $this->rowCount('character_buildings', 5, 100), 'На старой клетке построек не осталось');
        $this->assertSame(2, $this->rowCount('character_buildings', 5, 200), 'Все постройки переехали на новую клетку');
    }

    /**
     * Плановый снос (BaseRelocation): база + постройки указанной клетки должны
     * быть удалены, несмотря на pre-complete воркером.
     */
    public function testPlannedDemolitionRemovesBaseDespiteWorkerPreCompletingTask(): void
    {
        $db = Database::connect('tests');
        $db->table('characters')->insert([
            'id' => 7, 'telegram_user_id' => 9, 'cell_number' => 300, 'level' => 8,
        ]);
        $db->table('claimed_cells')->insert([
            'character_id' => 7, 'map_cell_id' => 300, 'claimed_at' => date('Y-m-d H:i:s'), 'status' => 'active',
        ]);
        $db->table('character_buildings')->insertBatch([
            ['character_id' => 7, 'building_id' => 1, 'map_cell_id' => 300, 'amount' => 1],
            ['character_id' => 7, 'building_id' => 2, 'map_cell_id' => 300, 'amount' => 1],
        ]);

        $settings = json_encode(['base_cell' => 300]);
        $db->table('character_tasks')->insert([
            'id' => 2, 'character_id' => 7, 'telegram_user_id' => 9, 'task_id' => 43,
            'start_time' => date('Y-m-d H:i:s', time() - 90000),
            'end_time'   => date('Y-m-d H:i:s', time() - 60),
            'status'     => 'completed',
            'task_settings' => $settings,
        ]);

        $task = [
            'id' => 2, 'character_id' => 7, 'telegram_user_id' => 9, 'task_id' => 43,
            'end_time' => date('Y-m-d H:i:s', time() - 60),
            'status' => 'in_work', 'task_settings' => $settings,
        ];

        (new class extends BaseRelocationCompletionHandler {
            /** @param array<string,mixed>|CharacterEntity $character */
            protected function notifyUser(array|CharacterEntity $character): void {}
        })->handle($task);

        $this->assertSame(0, $this->rowCount('claimed_cells', 7), 'База снесена (claimed_cells пуст)');
        $this->assertSame(0, $this->rowCount('character_buildings', 7), 'Постройки снесены');
    }
}
