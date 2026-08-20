<?php

declare(strict_types=1);

namespace Tests\Database\Transport;

use App\TaskHandlers\Drone\DroneRechargeCron;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * Story transport-17 (ADR-174) — «зависимость от энергии» Инженеров: транспортный
 * `AutonomousDrone` заряжается на своей базе через тот же `DroneRechargeCron`, что
 * scout/cargo/repair/combat, а не только чинится (RepairToolsListAction остаётся живым
 * параллельным путём — non-goal этой story).
 *
 * Проверяет пятый тип по контракту: killswitch = `world.vehicle.enabled`, max =
 * `world.vehicle.drone_auto.charges_full`, rate = новый `world.vehicle.drone_auto.
 * charge_per_minute`.
 *
 * @internal
 */
final class DroneAutoRechargeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const DRONE_ITEM_ID = 777;

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

        $db->table('crafted_items')->insert(['id' => self::DRONE_ITEM_ID, 'name_eng' => 'AutonomousDrone']);
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

    /** @param array<string, int|float|bool|string> $values */
    private function seedSettings(array $values): void
    {
        $db = Database::connect('tests');
        foreach ($values as $key => $value) {
            $row = ['setting_key' => $key];
            if (is_bool($value)) {
                $row['value_type'] = 'bool';
                $row['value_bool'] = $value ? 1 : 0;
            } elseif (is_int($value)) {
                $row['value_type'] = 'int';
                $row['value_int']  = $value;
            } elseif (is_float($value)) {
                $row['value_type']  = 'float';
                $row['value_float'] = $value;
            } else {
                $row['value_type']   = 'string';
                $row['value_string'] = (string) $value;
            }
            $db->table('game_settings')->insert($row);
        }
        $this->cleanCache();
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
        $this->seedSettings([
            'world.vehicle.enabled'                          => true,
            'world.vehicle.drone_auto.charges_full'           => 350,
            'world.vehicle.drone_auto.charge_per_minute'      => 5.0,
        ]);

        $this->seedChar(1, 500);
        $this->seedClaim(1, 500, 'active');
        $logId = $this->seedDrone(1, 0);

        (new DroneRechargeCron())->run(10);

        $this->assertSame(50, $this->durability($logId), 'На своей активной базе транспортный дрон обязан заряжаться rate × intervalMinutes (ADR-174).');
    }

    public function testClampsToChargesFullCeiling(): void
    {
        $this->seedSettings([
            'world.vehicle.enabled'                          => true,
            'world.vehicle.drone_auto.charges_full'           => 350,
            'world.vehicle.drone_auto.charge_per_minute'      => 5.0,
        ]);

        $this->seedChar(2, 500);
        $this->seedClaim(2, 500, 'active');
        $logId = $this->seedDrone(2, 345);

        (new DroneRechargeCron())->run(10); // 5.0 × 10 = 50 без клипа дало бы 395

        $this->assertSame(350, $this->durability($logId), 'Заряд не должен превышать charges_full.');
    }

    public function testDoesNotRechargeWhenOffBase(): void
    {
        $this->seedSettings([
            'world.vehicle.enabled'                          => true,
            'world.vehicle.drone_auto.charges_full'           => 350,
            'world.vehicle.drone_auto.charge_per_minute'      => 5.0,
        ]);

        $this->seedChar(3, 600);
        $this->seedClaim(3, 999, 'active'); // база в другом месте
        $logId = $this->seedDrone(3, 0);

        (new DroneRechargeCron())->run(10);

        $this->assertSame(0, $this->durability($logId), 'Вне своей базы транспортный дрон не заряжается (полевая зарядка — отдельная механика других дронов, не эта story).');
    }

    public function testNoOpWhenVehicleKillswitchOff(): void
    {
        $this->seedSettings([
            'world.vehicle.enabled'                          => false,
            'world.vehicle.drone_auto.charges_full'           => 350,
            'world.vehicle.drone_auto.charge_per_minute'      => 5.0,
        ]);

        $this->seedChar(4, 700);
        $this->seedClaim(4, 700, 'active');
        $logId = $this->seedDrone(4, 0);

        (new DroneRechargeCron())->run(10);

        $this->assertSame(0, $this->durability($logId), 'world.vehicle.enabled=false обязан выключать зарядку транспортного дрона целиком (роль killswitch для этой story).');
    }

    public function testSkippedWhenRateIsZero(): void
    {
        $this->seedSettings([
            'world.vehicle.enabled'                          => true,
            'world.vehicle.drone_auto.charges_full'           => 350,
            'world.vehicle.drone_auto.charge_per_minute'      => 0.0,
        ]);

        $this->seedChar(5, 800);
        $this->seedClaim(5, 800, 'active');
        $logId = $this->seedDrone(5, 0);

        (new DroneRechargeCron())->run(10);

        $this->assertSame(0, $this->durability($logId), 'Ставка ≤ 0 обязана пропускать тип, как и у остальных дронов (существующий гейт).');
    }
}
