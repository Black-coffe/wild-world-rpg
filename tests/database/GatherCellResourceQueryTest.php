<?php

namespace Tests\Database;

use App\Models\BiomeModel;
use App\Models\MapModel;
use App\Models\ResourceModel;
use App\Services\Player\Gather\GatherCellResourceQuery;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * S7 — substring-leak в biome→resource lookup.
 *
 * BEFORE fix: `resourceModel->like('biome_id', '1', 'both')` тянуло
 * ресурс с `biome_id='10'` (LIKE '%1%' матчит "10"). Тихий баг.
 *
 * AFTER fix: `FIND_IN_SET($id, biome_id) > 0` — exact CSV-item match.
 *
 * @internal
 */
final class GatherCellResourceQueryTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private GatherCellResourceQuery $svc;

    protected function setUp(): void
    {
        parent::setUp();

        $db = Database::connect('tests');
        $db->query('DROP TABLE IF EXISTS map');
        $db->query('DROP TABLE IF EXISTS biomes');
        $db->query('DROP TABLE IF EXISTS resources');
        $db->query('DROP TABLE IF EXISTS game_settings');

        $db->query('CREATE TABLE game_settings (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(191), value_type VARCHAR(16) NULL, value_int INT NULL, value_bool TINYINT NULL, value_string TEXT NULL)');
        $db->query('
            CREATE TABLE map (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cell_number INT NOT NULL UNIQUE,
                coordinate_x INT NULL,
                coordinate_y INT NULL,
                biome_id INT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        $db->query('
            CREATE TABLE biomes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                description TEXT NULL,
                biome_type VARCHAR(50) NULL,
                survival_difficulty INT NULL,
                danger_level INT NULL,
                occurrence_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        $db->query('
            CREATE TABLE resources (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL UNIQUE,
                name_en VARCHAR(255) NULL,
                description TEXT NULL,
                biome_id MEDIUMTEXT NULL,
                is_tradeable TINYINT(1) NOT NULL DEFAULT 1,
                type MEDIUMTEXT NULL,
                price INT NOT NULL DEFAULT 0,
                buy_price DECIMAL(10,2) NULL,
                sell_price DECIMAL(10,2) NULL,
                rarity INT NULL,
                level_required INT NOT NULL DEFAULT 1,
                min_y INT NULL,
                max_y INT NULL,
                initial_quantity INT NOT NULL DEFAULT 100,
                keyword VARCHAR(100) NULL,
                icon_text VARCHAR(255) NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');

        $db->table('biomes')->insertBatch([
            ['id' => 1, 'name' => 'Леса',          'occurrence_rate' => 1, 'biome_type' => 'wet'],
            ['id' => 2, 'name' => 'Горы',          'occurrence_rate' => 1, 'biome_type' => 'cold'],
            ['id' => 10, 'name' => 'Тестовый-10',  'occurrence_rate' => 1, 'biome_type' => 'plain'],
            ['id' => 11, 'name' => 'Тестовый-11',  'occurrence_rate' => 1, 'biome_type' => 'plain'],
        ]);

        $db->table('map')->insertBatch([
            ['cell_number' => 1001, 'biome_id' => 1],
            ['cell_number' => 1002, 'biome_id' => 10],
            ['cell_number' => 1003, 'biome_id' => 11],
        ]);

        $db->table('resources')->insertBatch([
            ['name' => 'Древесина',   'name_en' => 'Wood',     'biome_id' => '1',     'price' => 1, 'level_required' => 1, 'is_tradeable' => 1],
            ['name' => 'Кора',        'name_en' => 'Bark',     'biome_id' => '1,2,5', 'price' => 1, 'level_required' => 1, 'is_tradeable' => 1],
            ['name' => 'РудаТест10',  'name_en' => 'OreTen',   'biome_id' => '10',    'price' => 1, 'level_required' => 1, 'is_tradeable' => 1],
            ['name' => 'ШлакТест11',  'name_en' => 'SlagEle',  'biome_id' => '11',    'price' => 1, 'level_required' => 1, 'is_tradeable' => 1],
            ['name' => 'СмесьМногих', 'name_en' => 'Mixed',    'biome_id' => '2,11',  'price' => 1, 'level_required' => 1, 'is_tradeable' => 1],
        ]);

        $this->svc = new GatherCellResourceQuery(
            new MapModel(),
            new BiomeModel(),
            new ResourceModel()
        );
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        $db->query('DROP TABLE IF EXISTS map');
        $db->query('DROP TABLE IF EXISTS biomes');
        $db->query('DROP TABLE IF EXISTS resources');
        $db->query('DROP TABLE IF EXISTS game_settings');
        parent::tearDown();
    }

    /**
     * Substring leak: при `like('biome_id', '1', 'both')` ресурсы
     * с biome_id='10','11','1,2,5','2,11' матчатся подстрокой.
     * После fix FIND_IN_SET — только точные совпадения CSV-item.
     */
    public function testBiome1DoesNotLeakBiome10Or11Resources(): void
    {
        $ctx = $this->svc->loadCellContext(1001, 50);

        $names = array_map(static fn ($r): string => (string) $r->name_en, $ctx['resources']);
        sort($names);

        $this->assertSame(['Bark', 'Wood'], $names, 'Биом 1: только Wood (biome_id=1) + Bark (1,2,5); НЕ должно быть OreTen/SlagEle/Mixed');
    }

    public function testBiome10ReturnsOnlyExactCsvItemMatch(): void
    {
        $ctx = $this->svc->loadCellContext(1002, 50);

        $names = array_map(static fn ($r): string => (string) $r->name_en, $ctx['resources']);
        sort($names);

        $this->assertSame(['OreTen'], $names, 'Биом 10: только OreTen (biome_id=10); НЕ должно быть Mixed (biome_id=2,11) или Wood (biome_id=1)');
    }

    public function testBiome11ReturnsExactAndCsvMember(): void
    {
        $ctx = $this->svc->loadCellContext(1003, 50);

        $names = array_map(static fn ($r): string => (string) $r->name_en, $ctx['resources']);
        sort($names);

        $this->assertSame(['Mixed', 'SlagEle'], $names, 'Биом 11: SlagEle (biome_id=11) + Mixed (biome_id=2,11)');
    }

    public function testLevelRequiredFilterStillApplied(): void
    {
        $db = Database::connect('tests');
        $db->table('resources')->insert([
            'name'           => 'HighLevel',
            'name_en'        => 'HighLevel',
            'biome_id'       => '1',
            'price'          => 1,
            'level_required' => 100,
            'is_tradeable'   => 1,
        ]);

        $ctx = $this->svc->loadCellContext(1001, 5);

        $names = array_map(static fn ($r): string => (string) $r->name_en, $ctx['resources']);
        $this->assertNotContains('HighLevel', $names, 'Ресурс с level_required=100 не доступен char level=5');
    }
}
