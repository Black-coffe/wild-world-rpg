<?php

declare(strict_types=1);

namespace Tests\Database;

use App\TaskHandlers\NPC\EliteSpawnHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * E15 (ADR-115) — EliteSpawnHandler: killswitch + population_each на тир + север-смещение + рефилл.
 *
 * @internal
 */
final class EliteSpawnHandlerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = ['game_settings', 'npcs', 'npc_spawns', 'map'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('CREATE TABLE game_settings (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(191), value_type VARCHAR(16) NULL, value_int INT NULL, value_bool TINYINT NULL, value_string TEXT NULL)');
        $db->query('CREATE TABLE npcs (id INT AUTO_INCREMENT PRIMARY KEY, npc_name_en VARCHAR(100), health DECIMAL(7,2) DEFAULT 100)');
        $db->query('CREATE TABLE npc_spawns (id INT AUTO_INCREMENT PRIMARY KEY, npc_id INT, cell_number INT, coordinate_x INT, coordinate_y INT, current_health DECIMAL(7,2), spawned_at DATETIME NULL, status VARCHAR(20))');
        $db->query('CREATE TABLE map (id INT AUTO_INCREMENT PRIMARY KEY, cell_number INT, coordinate_x INT, coordinate_y INT, biome_id INT)');

        // 3 элиты.
        foreach (['elite_armored_marauder' => 320, 'elite_void_raider' => 420, 'elite_steel_reaper' => 560] as $key => $hp) {
            $db->table('npcs')->insert(['npc_name_en' => $key, 'health' => $hp]);
        }
        // Карта: по 40 клеток в каждой Y-сотне пояса 300-599 (biome 1), cell_number уникален.
        $cn = 1;
        $batch = [];
        for ($y = 300; $y < 600; $y += 10) {
            for ($k = 0; $k < 5; $k++) {
                $batch[] = ['cell_number' => $cn, 'coordinate_x' => $cn % 1000, 'coordinate_y' => $y, 'biome_id' => 1];
                $cn++;
            }
        }
        $db->table('map')->insertBatch($batch);

        $this->seedInt('world.elites.y_min', 300);
        $this->seedInt('world.elites.y_max', 600);
        $this->seedInt('world.elites.population_each', 2);
        $this->seedInt('world.elites.max_adjust_per_tick', 30);
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

    private function seedBool(string $k, int $v): void
    {
        Database::connect('tests')->table('game_settings')->insert(['setting_key' => $k, 'value_type' => 'bool', 'value_bool' => $v]);
        $this->cleanCache();
    }

    private function seedInt(string $k, int $v): void
    {
        Database::connect('tests')->table('game_settings')->insert(['setting_key' => $k, 'value_type' => 'int', 'value_int' => $v]);
        $this->cleanCache();
    }

    private function aliveCount(): int
    {
        return (int) Database::connect('tests')->table('npc_spawns')->where('status', 'alive')->countAllResults();
    }

    public function testDormantNoSpawn(): void
    {
        // killswitch off (не сидим world.elites.enabled).
        (new EliteSpawnHandler())->run();
        $this->assertSame(0, $this->aliveCount());
    }

    public function testSpawnsPopulationEachPerTier(): void
    {
        $this->seedBool('world.elites.enabled', 1);
        (new EliteSpawnHandler())->run();
        // 3 тира × population_each 2 = 6 (карта достаточно велика).
        $this->assertSame(6, $this->aliveCount());
        // Все в поясе [300,600).
        $outOfBelt = Database::connect('tests')->table('npc_spawns')
            ->groupStart()->where('coordinate_y <', 300)->orWhere('coordinate_y >=', 600)->groupEnd()
            ->countAllResults();
        $this->assertSame(0, $outOfBelt);
    }

    public function testNorthBiasReaperStaysNorth(): void
    {
        $this->seedBool('world.elites.enabled', 1);
        (new EliteSpawnHandler())->run();
        $db = Database::connect('tests');
        // Жнец (L50, north_cut 0.67): пояс 300-600, span 300 → tierYMax = 600 - round(300*0.67)=600-201=399.
        $reaperId = (int) $db->table('npcs')->select('id')->where('npc_name_en', 'elite_steel_reaper')->get()->getRowArray()['id'];
        $south = $db->table('npc_spawns')->where('npc_id', $reaperId)->where('coordinate_y >=', 399)->countAllResults();
        $this->assertSame(0, $south, 'Жнец не должен спавниться южнее ~399 (север ценнее)');
        $total = $db->table('npc_spawns')->where('npc_id', $reaperId)->countAllResults();
        $this->assertSame(2, $total);
    }

    public function testRefillAfterKill(): void
    {
        $this->seedBool('world.elites.enabled', 1);
        $h = new EliteSpawnHandler();
        $h->run();
        $this->assertSame(6, $this->aliveCount());

        // «Убиваем» одну элиту (AutoPveHandler удаляет spawn).
        $db  = Database::connect('tests');
        $one = $db->table('npc_spawns')->get(1)->getRowArray();
        $db->table('npc_spawns')->where('id', $one['id'])->delete();
        $this->assertSame(5, $this->aliveCount());

        // Следующий тик доливает обратно до 6 (в НОВОЙ клетке).
        $h2 = new EliteSpawnHandler();
        $h2->run();
        $this->assertSame(6, $this->aliveCount());
    }

    public function testKillswitchOffMidwayStops(): void
    {
        $this->seedBool('world.elites.enabled', 1);
        (new EliteSpawnHandler())->run();
        $this->assertSame(6, $this->aliveCount());
        // Выключаем — повторный тик не доливает и не трогает существующих.
        Database::connect('tests')->table('game_settings')->where('setting_key', 'world.elites.enabled')->update(['value_bool' => 0]);
        $this->cleanCache();
        (new EliteSpawnHandler())->run();
        $this->assertSame(6, $this->aliveCount());
    }
}
