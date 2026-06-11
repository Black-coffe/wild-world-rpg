<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Player\Gather\GatherCellResourceQuery;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * E16 (ADR-116) — широтный гейт ресурсов пояса в GatherCellResourceQuery.
 * Dormant → byte-identical (широтные ресурсы скрыты); ON → добываются только в [min_y,max_y).
 *
 * @internal
 */
final class GatherBeltResourceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = ['game_settings', 'map', 'biomes', 'resources'];

    private int $beltCell = 0;
    private int $southCell = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('CREATE TABLE game_settings (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(191), value_type VARCHAR(16) NULL, value_int INT NULL, value_bool TINYINT NULL, value_string TEXT NULL)');
        $db->query('CREATE TABLE map (id INT AUTO_INCREMENT PRIMARY KEY, cell_number INT, coordinate_x INT, coordinate_y INT, biome_id INT)');
        $db->query('CREATE TABLE biomes (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64))');
        $db->query('CREATE TABLE resources (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100), name_en VARCHAR(100), biome_id VARCHAR(40), level_required INT, min_y INT NULL, max_y INT NULL)');

        $db->table('biomes')->insert(['id' => 1, 'name' => 'Леса']);
        // Клетка в поясе (Y400) и южнее (Y800), оба биом 1.
        $db->table('map')->insert(['cell_number' => 4001, 'coordinate_x' => 10, 'coordinate_y' => 400, 'biome_id' => 1]);
        $db->table('map')->insert(['cell_number' => 8001, 'coordinate_x' => 10, 'coordinate_y' => 800, 'biome_id' => 1]);
        $this->beltCell  = 4001;
        $this->southCell = 8001;
        // Обычный ресурс (без широтного гейта) + поясной (min_y=300/max_y=600).
        $db->table('resources')->insert(['name' => 'Вода', 'name_en' => 'Water', 'biome_id' => '1', 'level_required' => 1, 'min_y' => null, 'max_y' => null]);
        $db->table('resources')->insert(['name' => 'Пепел Предтеч', 'name_en' => 'ForerunnerAsh', 'biome_id' => '1', 'level_required' => 25, 'min_y' => 300, 'max_y' => 600]);
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
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

    private function enableBelt(): void
    {
        Database::connect('tests')->table('game_settings')->insert(['setting_key' => 'world.belt_resources.enabled', 'value_type' => 'bool', 'value_bool' => 1]);
        $this->cleanCache();
    }

    /** @return list<string> name_en добываемых ресурсов на клетке для уровня 30. */
    private function names(int $cell): array
    {
        $ctx = (new GatherCellResourceQuery())->loadCellContext($cell, 30);
        return array_map(static fn ($r) => (string) ($r['name_en'] ?? ''), $ctx['resources']);
    }

    public function testDormantHidesBeltResourceEverywhere(): void
    {
        // killswitch off → поясной ресурс скрыт даже в поясе (byte-identical: только обычные).
        $this->assertSame(['Water'], $this->names($this->beltCell));
        $this->assertSame(['Water'], $this->names($this->southCell));
    }

    public function testEnabledBeltResourceOnlyInBelt(): void
    {
        $this->enableBelt();
        // В поясе (Y400): обычный + поясной.
        $belt = $this->names($this->beltCell);
        sort($belt);
        $this->assertSame(['ForerunnerAsh', 'Water'], $belt);
        // Южнее (Y800, вне [300,600)): только обычный.
        $this->assertSame(['Water'], $this->names($this->southCell));
    }
}
