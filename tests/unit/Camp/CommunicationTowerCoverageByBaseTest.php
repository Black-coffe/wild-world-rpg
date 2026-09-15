<?php

declare(strict_types=1);

namespace Tests\Unit\Camp;

use App\Services\Coverage\CommunicationTowerCoverageService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * story multibase-picker-01 — покрытие Вышки связи по КАЖДОЙ активной базе.
 *
 * Своя схема под приватным префиксом `mbpc_` (паттерн `BuildingCardBaseScopeTest`):
 * реальные модели и `GameSettingsService` читают `mbpc_*` через `setPrefix()`,
 * общие таблицы тест-БД не трогаются.
 *
 * @internal
 */
final class CommunicationTowerCoverageByBaseTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const PREFIX = 'mbpc_';

    /** @var list<string> */
    private const TABLES = ['characters', 'buildings', 'character_buildings', 'claimed_cells', 'map', 'game_settings'];

    private string $origPrefix = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->origPrefix = $this->db()->getPrefix();
        $this->db()->setPrefix(self::PREFIX);

        $ddl = [
            'characters' => 'id INT AUTO_INCREMENT PRIMARY KEY, telegram_user_id INT NULL, cell_number INT NULL, created_at DATETIME NULL, updated_at DATETIME NULL',
            'buildings' => 'id INT AUTO_INCREMENT PRIMARY KEY, name_ru VARCHAR(255) NULL, name_en VARCHAR(255) NULL',
            'character_buildings' => 'id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NULL, building_id INT NULL, map_cell_id INT NULL, level INT NULL DEFAULT 1, created_at DATETIME NULL, updated_at DATETIME NULL',
            'claimed_cells' => "id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NOT NULL, map_cell_id INT NOT NULL, claimed_at DATETIME NULL, status ENUM('active','abandoned') NOT NULL DEFAULT 'active', camp_name VARCHAR(64) NULL",
            'map' => 'id INT PRIMARY KEY, cell_number INT NOT NULL, coordinate_x INT NOT NULL, coordinate_y INT NOT NULL',
            'game_settings' => 'id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(128) NOT NULL, value_type VARCHAR(16) NOT NULL, value_int INT NULL, value_float DOUBLE NULL, value_bool TINYINT NULL, value_string VARCHAR(255) NULL, created_at DATETIME NULL, updated_at DATETIME NULL',
        ];
        foreach ($ddl as $table => $cols) {
            $this->db()->query('DROP TABLE IF EXISTS ' . self::PREFIX . $table);
            $this->db()->query('CREATE TABLE ' . self::PREFIX . $table . " ({$cols}) ENGINE=InnoDB");
        }

        $this->cleanCache();
    }

    protected function tearDown(): void
    {
        try {
            foreach (self::TABLES as $table) {
                $this->db()->query('DROP TABLE IF EXISTS ' . self::PREFIX . $table);
            }
        } finally {
            $this->db()->setPrefix($this->origPrefix);
            $this->cleanCache();
        }

        parent::tearDown();
    }

    private function db(): BaseConnection
    {
        return Database::connect('tests');
    }

    private function cleanCache(): void
    {
        $cache = service('cache');
        if (is_object($cache) && method_exists($cache, 'clean')) {
            $cache->clean();
        }
    }

    /** Линия клеток: клетка N — координаты (N, 0), `map.id = cell_number`. */
    private function seedMapLine(int ...$cells): void
    {
        foreach ($cells as $cell) {
            $this->db()->table('map')->insert(['id' => $cell, 'cell_number' => $cell, 'coordinate_x' => $cell, 'coordinate_y' => 0]);
        }
    }

    private function seedCharacter(int $cell): int
    {
        $this->db()->table('characters')->insert(['telegram_user_id' => 1, 'cell_number' => $cell]);

        return (int) $this->db()->insertID();
    }

    private function seedBase(int $charId, int $cell, string $status = 'active', ?string $name = null): int
    {
        $this->db()->table('claimed_cells')->insert([
            'character_id' => $charId, 'map_cell_id' => $cell, 'claimed_at' => date('Y-m-d H:i:s'),
            'status' => $status, 'camp_name' => $name,
        ]);

        return (int) $this->db()->insertID();
    }

    private function seedTowerBuilding(): int
    {
        $this->db()->table('buildings')->insert(['name_ru' => 'Вышка связи', 'name_en' => 'CommunicationTower']);

        return (int) $this->db()->insertID();
    }

    private function seedTower(int $charId, int $towerBuildingId, int $cell, int $level): void
    {
        $this->db()->table('character_buildings')->insert([
            'character_id' => $charId, 'building_id' => $towerBuildingId, 'map_cell_id' => $cell, 'level' => $level,
        ]);
    }

    private function setPerLevel(int $value): void
    {
        $this->db()->table('game_settings')->insert([
            'setting_key' => CommunicationTowerCoverageService::SETTING_COVERAGE_PER_LEVEL,
            'value_type' => 'int', 'value_int' => $value,
        ]);
    }

    public function testSecondBaseCoveredByOwnTowerFirstIsNot(): void
    {
        $this->seedMapLine(0, 300, 1000);
        $towerId = $this->seedTowerBuilding();
        $charId  = $this->seedCharacter(300);

        $first  = $this->seedBase($charId, 0, 'active', 'Первая');
        $second = $this->seedBase($charId, 1000, 'active', 'Вторая');
        $this->seedTower($charId, $towerId, 1000, 7); // 7 × 100 = 700 ≥ 700 до второй базы

        $rows = (new CommunicationTowerCoverageService())->coverageByBase($charId, 300);

        $this->assertSame([$first, $second], array_column($rows, 'base_id'), 'порядок по claimed_cells.id');
        $this->assertFalse($rows[0]['isCovered'], 'у первой базы нет Вышки');
        $this->assertSame(0, $rows[0]['towerLevel']);
        $this->assertSame(0, $rows[0]['maxCoverage']);
        $this->assertSame(300, $rows[0]['distance']);
        $this->assertTrue($rows[1]['isCovered'], 'вторую покрывает её собственная Вышка');
        $this->assertSame(7, $rows[1]['towerLevel']);
        $this->assertSame(700, $rows[1]['maxCoverage']);
        $this->assertSame(700, $rows[1]['distance']);
        $this->assertSame(['cell' => 1000, 'name' => 'Вторая', 'x' => 1000, 'y' => 0], [
            'cell' => $rows[1]['cell'], 'name' => $rows[1]['name'], 'x' => $rows[1]['x'], 'y' => $rows[1]['y'],
        ]);
    }

    public function testAbandonedBaseIsNotListed(): void
    {
        $this->seedMapLine(0, 50);
        $towerId = $this->seedTowerBuilding();
        $charId  = $this->seedCharacter(0);

        $this->seedBase($charId, 50, 'abandoned');
        $active = $this->seedBase($charId, 0);
        $this->seedTower($charId, $towerId, 50, 5);

        $rows = (new CommunicationTowerCoverageService())->coverageByBase($charId, 0);

        $this->assertSame([$active], array_column($rows, 'base_id'));
    }

    public function testRadiusFollowsGameSettingsKey(): void
    {
        $this->seedMapLine(0, 250);
        $towerId = $this->seedTowerBuilding();
        $charId  = $this->seedCharacter(250);
        $this->seedBase($charId, 0);
        $this->seedTower($charId, $towerId, 0, 2);

        // Нет строки ключа → дефолт 100 = прежнее `towerLevel * 100`.
        $default = (new CommunicationTowerCoverageService())->coverageByBase($charId, 250);
        $this->assertSame(200, $default[0]['maxCoverage']);
        $this->assertFalse($default[0]['isCovered']);
        $this->assertSame(200, (new CommunicationTowerCoverageService())->checkCoverage($charId)['maxCoverage']);

        $this->setPerLevel(150);
        $this->cleanCache();

        $tuned = (new CommunicationTowerCoverageService())->coverageByBase($charId, 250);
        $this->assertSame(300, $tuned[0]['maxCoverage']);
        $this->assertTrue($tuned[0]['isCovered']);
    }

    public function testCheckCoverageKeepsShapeAndPicksCoveringBase(): void
    {
        $this->seedMapLine(0, 300, 1000);
        $towerId = $this->seedTowerBuilding();
        $charId  = $this->seedCharacter(300);
        $this->seedBase($charId, 0);
        $this->seedBase($charId, 1000);
        $this->seedTower($charId, $towerId, 0, 1);    // первая: 100 < 300 — не покрывает
        $this->seedTower($charId, $towerId, 1000, 7); // вторая: 700 ≥ 700 — покрывает

        $service = new CommunicationTowerCoverageService();
        $result  = $service->checkCoverage($charId);

        $this->assertSame(['hasTower', 'towerLevel', 'distanceToBase', 'maxCoverage', 'isCovered', 'message'], array_keys($result));
        $this->assertTrue($result['isCovered']);
        $this->assertSame(7, $result['towerLevel']);
        $this->assertSame(700, $result['distanceToBase']);
        $this->assertSame($result, $service->checkCoverage($charId), 'детерминирован между вызовами');
    }

    public function testCheckCoverageNoBaseAndNoTowerMessagesUnchanged(): void
    {
        $this->seedMapLine(0);
        $this->seedTowerBuilding();
        $charId = $this->seedCharacter(0);

        $service = new CommunicationTowerCoverageService();
        $this->assertSame('У игрока нет базы, следовательно нет вышки связи.', $service->checkCoverage($charId)['message']);

        $this->seedBase($charId, 0);
        $noTower = $service->checkCoverage($charId);
        $this->assertFalse($noTower['hasTower']);
        $this->assertSame('У игрока нет здания "Вышка связи".', $noTower['message']);
    }
}
