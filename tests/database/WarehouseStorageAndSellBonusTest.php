<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Player\WarehouseSellBonusService;
use App\Services\Player\WeightCapacityService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * ADR-085 — Склад как источник ёмкости (WeightCapacityService::warehouseBonusKg)
 * + мягкий бонус продажи (WarehouseSellBonusService::sellPriceMultiplier).
 *
 * Зеркало DroneServiceTest: ручные test-таблицы game_settings/buildings/character_buildings.
 *
 * @internal
 */
final class WarehouseStorageAndSellBonusTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const WAREHOUSE_ID = 3;
    private const CHAR_ID      = 491;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();
        $db = Database::connect('tests');
        $db->query('DROP TABLE IF EXISTS game_settings');
        $db->query('DROP TABLE IF EXISTS buildings');
        $db->query('DROP TABLE IF EXISTS character_buildings');
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
            CREATE TABLE buildings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name_en VARCHAR(64) NOT NULL
            )
        ');
        $db->query('
            CREATE TABLE character_buildings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                character_id INT NOT NULL,
                building_id INT NOT NULL,
                level INT NOT NULL DEFAULT 1
            )
        ');
        $db->table('buildings')->insert(['id' => self::WAREHOUSE_ID, 'name_en' => 'Warehouse']);
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        $db->query('DROP TABLE IF EXISTS game_settings');
        $db->query('DROP TABLE IF EXISTS buildings');
        $db->query('DROP TABLE IF EXISTS character_buildings');
        $this->cleanCache();
        parent::tearDown();
    }

    private function seedBool(string $key, int $bool): void
    {
        Database::connect('tests')->table('game_settings')->insert([
            'setting_key' => $key, 'value_type' => 'bool', 'value_bool' => $bool,
        ]);
        $this->cleanCache();
    }

    private function seedInt(string $key, int $int): void
    {
        Database::connect('tests')->table('game_settings')->insert([
            'setting_key' => $key, 'value_type' => 'int', 'value_int' => $int,
        ]);
        $this->cleanCache();
    }

    private function seedFloat(string $key, float $float): void
    {
        Database::connect('tests')->table('game_settings')->insert([
            'setting_key' => $key, 'value_type' => 'float', 'value_float' => $float,
        ]);
        $this->cleanCache();
    }

    private function giveWarehouse(int $level): void
    {
        Database::connect('tests')->table('character_buildings')->insert([
            'character_id' => self::CHAR_ID, 'building_id' => self::WAREHOUSE_ID, 'level' => $level,
        ]);
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

    // ---- WeightCapacityService::warehouseBonusKg (ADR-085) ----

    public function testWarehouseBonusZeroWithoutWarehouse(): void
    {
        $this->seedInt('building.warehouse.l1.storage_bonus_kg', 200);
        $svc = new WeightCapacityService();
        $this->assertSame(0, $svc->warehouseBonusKg(self::CHAR_ID), 'нет Склада → 0 бонуса');
    }

    public function testWarehouseBonusL1(): void
    {
        $this->seedInt('building.warehouse.l1.storage_bonus_kg', 200);
        $this->giveWarehouse(1);
        $svc = new WeightCapacityService();
        $this->assertSame(200, $svc->warehouseBonusKg(self::CHAR_ID));
    }

    public function testWarehouseBonusCascadesFromL3DownToL1(): void
    {
        // Склад L2, но задан только L1-ключ → cascade вниз находит L1.
        $this->seedInt('building.warehouse.l1.storage_bonus_kg', 200);
        $this->giveWarehouse(2);
        $svc = new WeightCapacityService();
        $this->assertSame(200, $svc->warehouseBonusKg(self::CHAR_ID), 'cascade L2→L1');
    }

    public function testWarehouseBonusPrefersHigherLevelKey(): void
    {
        $this->seedInt('building.warehouse.l1.storage_bonus_kg', 200);
        $this->seedInt('building.warehouse.l2.storage_bonus_kg', 400);
        $this->giveWarehouse(2);
        $svc = new WeightCapacityService();
        $this->assertSame(400, $svc->warehouseBonusKg(self::CHAR_ID), 'L2-ключ имеет приоритет');
    }

    public function testRemainingCapacityDormantIgnoresWarehouse(): void
    {
        // killswitch weight-cap OFF → PHP_INT_MAX независимо от Склада.
        $this->seedInt('building.warehouse.l1.storage_bonus_kg', 200);
        $this->giveWarehouse(3);
        $svc = new WeightCapacityService();
        $this->assertSame((float) PHP_INT_MAX, $svc->getRemainingCapacity(self::CHAR_ID, 1, 100));
    }

    // ---- WarehouseSellBonusService::sellPriceMultiplier (ADR-085) ----

    public function testSellBonusDormantReturnsOne(): void
    {
        // killswitch не задан → false → 1.0
        $this->giveWarehouse(2);
        $this->seedFloat('economy.sell.warehouse_bonus_pct', 0.10);
        $svc = new WarehouseSellBonusService();
        $this->assertSame(1.0, $svc->sellPriceMultiplier(self::CHAR_ID));
    }

    public function testSellBonusEnabledButNoWarehouseReturnsOne(): void
    {
        $this->seedBool('economy.sell.warehouse_bonus.enabled', 1);
        $this->seedFloat('economy.sell.warehouse_bonus_pct', 0.10);
        $svc = new WarehouseSellBonusService();
        $this->assertSame(1.0, $svc->sellPriceMultiplier(self::CHAR_ID), 'нет Склада → бонуса нет');
    }

    public function testSellBonusEnabledWithWarehouse(): void
    {
        $this->seedBool('economy.sell.warehouse_bonus.enabled', 1);
        $this->seedFloat('economy.sell.warehouse_bonus_pct', 0.10);
        $this->giveWarehouse(1);
        $svc = new WarehouseSellBonusService();
        $this->assertSame(1.10, $svc->sellPriceMultiplier(self::CHAR_ID));
    }

    public function testSellBonusPctClampedToOne(): void
    {
        $this->seedBool('economy.sell.warehouse_bonus.enabled', 1);
        $this->seedFloat('economy.sell.warehouse_bonus_pct', 5.0); // абсурд → клампится в 1.0
        $this->giveWarehouse(1);
        $svc = new WarehouseSellBonusService();
        $this->assertSame(2.0, $svc->sellPriceMultiplier(self::CHAR_ID), 'pct clamp [0,1] → макс ×2.0');
    }
}
