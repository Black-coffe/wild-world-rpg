<?php

declare(strict_types=1);

namespace Tests\Database;

use App\TaskHandlers\Drone\DroneRechargeCron;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * Регресс BUILT-BUT-DEAD (2026-06-22): scout-дроны НЕ заряжались с W2, потому что
 * DroneRechargeCron::isCharacterOnBase читал несуществующую колонку
 * `claimed_cells.cell_number` (в таблице только `map_cell_id`) → результат всегда
 * false → на базе зарядка не шла НИКОГДА. Фикс — через ADR-095 `findActiveCell`
 * (`claimed_cells.map_cell_id == characters.cell_number`, status='active').
 *
 * Тест запускает реальный крон против минимальных таблиц и проверяет:
 *  - на своей активной базе → durability_count растёт;
 *  - не на базе → не растёт;
 *  - на чужой/abandoned клетке → не растёт.
 *
 * @internal
 */
final class DroneRechargeCronTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const DRONE_ITEM_ID = 121;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();

        $db = Database::connect('tests');
        foreach (['game_settings', 'characters', 'claimed_cells', 'crafted_items', 'crafted_items_log'] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }

        $db->query('
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
        $db->query('
            CREATE TABLE characters (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cell_number INT NULL,
                name VARCHAR(50) NULL
            )
        ');
        $db->query("
            CREATE TABLE claimed_cells (
                id INT AUTO_INCREMENT PRIMARY KEY,
                character_id INT NULL,
                map_cell_id INT NULL,
                status VARCHAR(16) NULL DEFAULT 'active',
                claimed_at DATETIME NULL
            )
        ");
        $db->query('
            CREATE TABLE crafted_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name_eng VARCHAR(100) NULL
            )
        ');
        $db->query('
            CREATE TABLE crafted_items_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                character_id INT NULL,
                crafted_item_id INT NULL,
                quantity INT NULL DEFAULT 1,
                durability_count INT NULL DEFAULT 0,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');

        $db->table('crafted_items')->insert(['id' => self::DRONE_ITEM_ID, 'name_eng' => 'DroneScout']);
        $this->cleanCache();
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (['game_settings', 'characters', 'claimed_cells', 'crafted_items', 'crafted_items_log'] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
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

    private function seedChar(int $id, int $cell): void
    {
        Database::connect('tests')->table('characters')->insert(['id' => $id, 'cell_number' => $cell, 'name' => "c{$id}"]);
    }

    private function seedClaim(int $charId, int $mapCellId, string $status = 'active'): void
    {
        Database::connect('tests')->table('claimed_cells')->insert([
            'character_id' => $charId, 'map_cell_id' => $mapCellId, 'status' => $status, 'claimed_at' => '2026-01-01 00:00:00',
        ]);
    }

    private function seedDrone(int $charId, int $durability, int $qty = 1): int
    {
        $db = Database::connect('tests');
        $db->table('crafted_items_log')->insert([
            'character_id' => $charId, 'crafted_item_id' => self::DRONE_ITEM_ID,
            'quantity' => $qty, 'durability_count' => $durability,
        ]);
        return (int) $db->insertID();
    }

    private function durability(int $logId): int
    {
        $res = Database::connect('tests')->table('crafted_items_log')->where('id', $logId)->get();
        $row = $res ? $res->getRowArray() : null;
        return (int) ($row['durability_count'] ?? -1);
    }

    public function testRechargesWhenOnOwnActiveBase(): void
    {
        // Чар стоит на клетке 500, у него активная база с map_cell_id=500 → НА БАЗЕ.
        $this->seedChar(1, 500);
        $this->seedClaim(1, 500, 'active');
        $logId = $this->seedDrone(1, 0); // пустой дрон

        (new DroneRechargeCron())->run(1);

        // Дефолтная ставка 100/120≈0.833 → шаг floor=1 → +1 за тик.
        $this->assertSame(1, $this->durability($logId), 'Дрон на своей активной базе обязан заряжаться (регресс BUILT-BUT-DEAD).');
    }

    public function testDoesNotRechargeWhenOffBase(): void
    {
        // Чар стоит на 600, база у него на 999 → НЕ на базе.
        $this->seedChar(2, 600);
        $this->seedClaim(2, 999, 'active');
        $logId = $this->seedDrone(2, 0);

        (new DroneRechargeCron())->run(1);

        $this->assertSame(0, $this->durability($logId), 'Вне базы заряд не должен расти.');
    }

    public function testDoesNotRechargeOnAbandonedClaim(): void
    {
        // Чар стоит на своей клетке, но база abandoned → findActiveCell=null → не заряжается.
        $this->seedChar(3, 700);
        $this->seedClaim(3, 700, 'abandoned');
        $logId = $this->seedDrone(3, 0);

        (new DroneRechargeCron())->run(1);

        $this->assertSame(0, $this->durability($logId), 'Заброшенная база не заряжает дрон.');
    }

    public function testNoOpWhenKillswitchOff(): void
    {
        Database::connect('tests')->table('game_settings')->insert([
            'setting_key' => 'drone.scout.enabled', 'value_type' => 'bool', 'value_bool' => 0,
        ]);
        $this->cleanCache();

        $this->seedChar(4, 800);
        $this->seedClaim(4, 800, 'active');
        $logId = $this->seedDrone(4, 0);

        (new DroneRechargeCron())->run(1);

        $this->assertSame(0, $this->durability($logId), 'При выключенном killswitch scout не заряжается.');
    }
}
