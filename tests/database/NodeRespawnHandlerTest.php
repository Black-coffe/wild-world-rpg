<?php

declare(strict_types=1);

namespace Tests\Database;

use App\TaskHandlers\NPC\NodeRespawnHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * WB5 (ADR-137 «Узлы») — NodeRespawnHandler: killswitch, подъём/гео-эскалация точек,
 * перевод боссов в passive, синхронизация npc_spawns с alive-точками, идемпотентность.
 *
 * @internal
 */
final class NodeRespawnHandlerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = ['game_settings', 'npcs', 'npc_spawns', 'boss_points'];

    /** Резолвится в setUp — id архетипа Шрама (южные точки). */
    private int $scarId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('CREATE TABLE game_settings (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(191), value_type VARCHAR(16) NULL, value_int INT NULL, value_bool TINYINT NULL, value_string TEXT NULL)');
        $db->query("CREATE TABLE npcs (id INT AUTO_INCREMENT PRIMARY KEY, npc_name_en VARCHAR(100), ai_behavior VARCHAR(20) DEFAULT 'aggressive', health DECIMAL(7,2) DEFAULT 100, created_at DATETIME NULL, updated_at DATETIME NULL)");
        $db->query("CREATE TABLE npc_spawns (id INT AUTO_INCREMENT PRIMARY KEY, npc_id INT, cell_number INT, coordinate_x INT, coordinate_y INT, current_health DECIMAL(7,2), spawned_at DATETIME NULL, status VARCHAR(20))");
        $db->query("CREATE TABLE boss_points (id INT AUTO_INCREMENT PRIMARY KEY, cell_number INT, coordinate_x INT, coordinate_y INT, biome_id INT NULL, y_band TINYINT, base_level INT, current_level INT, current_health INT, max_health INT, status VARCHAR(16) DEFAULT 'alive', respawn_at DATETIME NULL, last_killer_character_id INT NULL, kill_count INT DEFAULT 0, current_npc_id INT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)");

        // 3 босса-архетипа ADR-106 (aggressive по умолчанию).
        foreach (['boss_scar_butcher', 'boss_cerberus_prototype', 'boss_pack_patriarch'] as $name) {
            $db->table('npcs')->insert(['npc_name_en' => $name, 'ai_behavior' => 'aggressive', 'health' => 400]);
        }
        $row          = $db->table('npcs')->select('id')->where('npc_name_en', 'boss_scar_butcher')->get()->getRowArray();
        $this->scarId = (int) ($row['id'] ?? 0);
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

    private function enablePointMode(): void
    {
        Database::connect('tests')->table('game_settings')->insert(['setting_key' => 'world.nodes.point_mode_enabled', 'value_type' => 'bool', 'value_bool' => 1]);
        $this->cleanCache();
    }

    /**
     * Засеять точку-узел (паттерн WB4 seed: status='cooldown').
     *
     * @param array<string,mixed> $over
     */
    private function seedPoint(array $over = []): int
    {
        $db   = Database::connect('tests');
        $base = array_merge([
            'cell_number'    => 1000,
            'coordinate_x'   => 250,
            'coordinate_y'   => 850, // юг
            'biome_id'       => 1,
            'y_band'         => 0,
            'base_level'     => 1,
            'current_level'  => 1,
            'current_health' => 108,
            'max_health'     => 108,
            'status'         => 'cooldown',
            'respawn_at'     => null,
            'kill_count'     => 0,
            'current_npc_id' => $this->scarId,
        ], $over);
        $db->table('boss_points')->insert($base);

        return (int) $db->insertID();
    }

    private function point(int $id): array
    {
        return Database::connect('tests')->table('boss_points')->where('id', $id)->get()->getRowArray() ?? [];
    }

    private function aliveSpawns(): int
    {
        return (int) Database::connect('tests')->table('npc_spawns')->where('status', 'alive')->countAllResults();
    }

    // ----------------------------------------------------------------

    public function testDormantNoop(): void
    {
        // killswitch OFF (не сидим point_mode_enabled).
        $id = $this->seedPoint();
        (new NodeRespawnHandler())->run();

        $p = $this->point($id);
        $this->assertSame('cooldown', $p['status'], 'OFF: точка не поднимается');
        $this->assertSame(0, $this->aliveSpawns(), 'OFF: спавны не материализуются');
        $boss = Database::connect('tests')->table('npcs')->where('id', $this->scarId)->get()->getRowArray();
        $this->assertSame('aggressive', $boss['ai_behavior'], 'OFF: боссы остаются aggressive');
    }

    public function testInitialActivationRevivesSeededPoint(): void
    {
        // Свежезасеянная точка (cooldown + respawn_at NULL, kill_count 0) → alive к base_level.
        $id = $this->seedPoint(['current_health' => 0]); // имитируем «недозаполненный» current_health
        $this->enablePointMode();

        (new NodeRespawnHandler())->run();

        $p = $this->point($id);
        $this->assertSame('alive', $p['status']);
        $this->assertSame(1, (int) $p['current_level']);        // base_level
        $this->assertSame(108, (int) $p['max_health']);          // healthForLevel(1) = 100 + 1*8
        $this->assertSame(108, (int) $p['current_health']);      // восстановлен до полного
        $this->assertNull($p['respawn_at']);
        $this->assertSame(1, $this->aliveSpawns(), 'материализован спавн на клетке точки');
    }

    public function testRespawnEscalatesByKillCount(): void
    {
        // Убитая точка, кулдаун истёк, 3 расчистки → L4 (step 1), HP пересчитан.
        $id = $this->seedPoint([
            'kill_count'    => 3,
            'respawn_at'    => date('Y-m-d H:i:s', strtotime('-1 hour')),
            'current_level' => 1,
        ]);
        $this->enablePointMode();

        (new NodeRespawnHandler())->run();

        $p = $this->point($id);
        $this->assertSame('alive', $p['status']);
        $this->assertSame(4, (int) $p['current_level']);    // 1 + 1*3
        $this->assertSame(132, (int) $p['max_health']);      // 100 + 4*8
        $this->assertSame(132, (int) $p['current_health']);
    }

    public function testCooldownNotElapsedStaysDown(): void
    {
        // Кулдаун ещё не истёк (respawn_at в будущем) → точка не поднимается.
        $id = $this->seedPoint(['respawn_at' => date('Y-m-d H:i:s', strtotime('+2 hours'))]);
        $this->enablePointMode();

        (new NodeRespawnHandler())->run();

        $p = $this->point($id);
        $this->assertSame('cooldown', $p['status']);
        $this->assertSame(0, $this->aliveSpawns());
    }

    public function testBossesConvertedToPassive(): void
    {
        $this->seedPoint();
        $this->enablePointMode();

        (new NodeRespawnHandler())->run();

        $db    = Database::connect('tests');
        $count = $db->table('npcs')->whereIn('npc_name_en', ['boss_scar_butcher', 'boss_cerberus_prototype', 'boss_pack_patriarch'])->where('ai_behavior', 'passive')->countAllResults();
        $this->assertSame(3, $count, 'все 3 босса ADR-106 переведены в passive');
    }

    public function testRoamingBossSpawnsPurgedPointSpawnKept(): void
    {
        // Старый кочующий boss-спавн на «случайной» клетке (не точка) + alive-точка.
        $db = Database::connect('tests');
        $db->table('npc_spawns')->insert(['npc_id' => $this->scarId, 'cell_number' => 7777, 'coordinate_x' => 10, 'coordinate_y' => 90, 'current_health' => 400, 'status' => 'alive']);
        $id = $this->seedPoint(['status' => 'alive', 'cell_number' => 1000]);
        $this->enablePointMode();

        (new NodeRespawnHandler())->run();

        // Кочующий снят, на клетке точки — материализован.
        $roaming = $db->table('npc_spawns')->where('cell_number', 7777)->where('status', 'alive')->countAllResults();
        $onPoint = $db->table('npc_spawns')->where('cell_number', 1000)->where('status', 'alive')->countAllResults();
        $this->assertSame(0, $roaming, 'кочующий boss-спавн вне точки снят');
        $this->assertSame(1, $onPoint, 'на клетке alive-точки боссы материализован');
    }

    public function testIdempotentDoubleRun(): void
    {
        $id = $this->seedPoint(['kill_count' => 2, 'respawn_at' => date('Y-m-d H:i:s', strtotime('-1 hour'))]);
        $this->enablePointMode();

        $h = new NodeRespawnHandler();
        $h->run();
        $lvl1   = (int) $this->point($id)['current_level'];
        $spawn1 = $this->aliveSpawns();

        (new NodeRespawnHandler())->run();
        $lvl2   = (int) $this->point($id)['current_level'];
        $spawn2 = $this->aliveSpawns();

        $this->assertSame($lvl1, $lvl2, 'уровень не дрейфует при повторном прогоне (счёт ОТ kill_count)');
        $this->assertSame(3, $lvl1);              // 1 + 1*2
        $this->assertSame(1, $spawn1);
        $this->assertSame($spawn1, $spawn2, 'спавны не дублируются');
    }

    public function testMaterializeSkipsOccupiedCell(): void
    {
        // Чужой живой NPC уже стоит на клетке точки → второго не плодим.
        $db = Database::connect('tests');
        $db->table('npc_spawns')->insert(['npc_id' => 999, 'cell_number' => 1000, 'coordinate_x' => 250, 'coordinate_y' => 850, 'current_health' => 50, 'status' => 'alive']);
        $this->seedPoint(['status' => 'alive', 'cell_number' => 1000]);
        $this->enablePointMode();

        (new NodeRespawnHandler())->run();

        $onCell = $db->table('npc_spawns')->where('cell_number', 1000)->where('status', 'alive')->countAllResults();
        $this->assertSame(1, $onCell, 'не плодим второго NPC на занятой клетке');
    }
}
