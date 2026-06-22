<?php

declare(strict_types=1);

namespace Tests\Database;

use App\TaskHandlers\NPC\NodeHealthRegenHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * WB7 (ADR-137 «Узлы») — NodeHealthRegenHandler: реген-гейт. Лечит alive-узлы вне активного боя
 * (updated_at-пауза + исключение active-encounter), к max_health; killswitch + cooldown-точки не трогает.
 *
 * @internal
 */
final class NodeHealthRegenHandlerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = ['game_settings', 'boss_points', 'boss_encounters'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('CREATE TABLE game_settings (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(191), value_type VARCHAR(16) NULL, value_int INT NULL, value_bool TINYINT NULL, value_float DOUBLE NULL, value_string TEXT NULL)');
        $db->query("CREATE TABLE boss_points (id INT AUTO_INCREMENT PRIMARY KEY, cell_number INT, current_health INT, max_health INT, status VARCHAR(16), updated_at DATETIME NULL)");
        $db->query("CREATE TABLE boss_encounters (id INT AUTO_INCREMENT PRIMARY KEY, boss_point_id INT, character_id INT, status VARCHAR(16))");
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

    private function enable(): void
    {
        Database::connect('tests')->table('game_settings')->insert(['setting_key' => 'world.nodes.point_mode_enabled', 'value_type' => 'bool', 'value_bool' => 1]);
        $this->cleanCache();
    }

    /** @param array<string,mixed> $over */
    private function seedPoint(array $over = []): int
    {
        $db = Database::connect('tests');
        $db->table('boss_points')->insert(array_merge([
            'cell_number'    => 1,
            'current_health' => 100,
            'max_health'     => 140,
            'status'         => 'alive',
            'updated_at'     => date('Y-m-d H:i:s', strtotime('-30 minutes')), // давно не бит
        ], $over));

        return (int) $db->insertID();
    }

    private function hp(int $id): int
    {
        return (int) Database::connect('tests')->table('boss_points')->where('id', $id)->get()->getRowArray()['current_health'];
    }

    // ----------------------------------------------------------------

    public function testDormantNoRegen(): void
    {
        $id = $this->seedPoint(); // point_mode OFF
        (new NodeHealthRegenHandler())->run();
        $this->assertSame(100, $this->hp($id));
    }

    public function testRegensAlivePointTowardMax(): void
    {
        $this->enable();
        $id = $this->seedPoint(['current_health' => 100, 'max_health' => 140]);
        (new NodeHealthRegenHandler())->run();
        $this->assertSame(120, $this->hp($id)); // +hp_regen_per_minute default 20
    }

    public function testCapsAtMaxHealth(): void
    {
        $this->enable();
        $id = $this->seedPoint(['current_health' => 135, 'max_health' => 140]);
        (new NodeHealthRegenHandler())->run();
        $this->assertSame(140, $this->hp($id)); // LEAST(max, +20)
    }

    public function testSkipsRecentlyDamaged(): void
    {
        $this->enable();
        // updated_at в пределах regen_pause_minutes (default 3) → реген не идёт
        $id = $this->seedPoint(['current_health' => 100, 'updated_at' => date('Y-m-d H:i:s', strtotime('-1 minute'))]);
        (new NodeHealthRegenHandler())->run();
        $this->assertSame(100, $this->hp($id));
    }

    public function testSkipsPointWithActiveEncounter(): void
    {
        $this->enable();
        $id = $this->seedPoint(['current_health' => 100]);
        Database::connect('tests')->table('boss_encounters')->insert(['boss_point_id' => $id, 'character_id' => 7, 'status' => 'active']);
        (new NodeHealthRegenHandler())->run();
        $this->assertSame(100, $this->hp($id), 'узел в активном бою не регенерирует');
    }

    public function testFledEncounterDoesNotBlockRegen(): void
    {
        $this->enable();
        $id = $this->seedPoint(['current_health' => 100]);
        Database::connect('tests')->table('boss_encounters')->insert(['boss_point_id' => $id, 'character_id' => 7, 'status' => 'fled']);
        (new NodeHealthRegenHandler())->run();
        $this->assertSame(120, $this->hp($id), 'после отступления (fled) реген затягивает раны');
    }

    public function testCooldownPointNotRegen(): void
    {
        $this->enable();
        $id = $this->seedPoint(['status' => 'cooldown', 'current_health' => 0, 'max_health' => 140]);
        (new NodeHealthRegenHandler())->run();
        $this->assertSame(0, $this->hp($id), 'cooldown-узел реген не трогает (его поднимает NodeRespawnHandler)');
    }
}
