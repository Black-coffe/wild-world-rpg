<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Settlement\SettlementTeleportService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * ADR-101 Фаза 5 — SettlementTeleportService: открытые назначения (open=explored, не руины,
 * не текущее, enabled) + readers (cost/cooldown) + write-path teleportTo (move+gold+туман+кулдаун).
 *
 * @internal
 */
final class SettlementTeleportServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = ['game_settings', 'settlements', 'explored_cells', 'characters', 'map', 'biomes'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('CREATE TABLE game_settings (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(64) NOT NULL, category VARCHAR(32) NULL, value_type VARCHAR(16) NULL, value_int INT NULL, value_bool TINYINT NULL, value_string TEXT NULL)');
        $db->query('CREATE TABLE settlements (id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(64), name_ru VARCHAR(120) NULL, type VARCHAR(16) NULL, anchor_cell INT NULL, coordinate_x INT NULL, coordinate_y INT NULL, faction_id INT NULL, zone_policy VARCHAR(16) DEFAULT \'neutral\', enter_min_standing INT NULL, icon VARCHAR(16) NULL, description_ru TEXT NULL, enabled TINYINT DEFAULT 0, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $db->query('CREATE TABLE explored_cells (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NULL, telegram_user_id INT NULL, map_cell_id INT NULL, biome_id INT NULL, character_level INT NULL, cell_status VARCHAR(32) NULL, notes TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $db->query('CREATE TABLE characters (id INT AUTO_INCREMENT PRIMARY KEY, telegram_user_id INT NULL, gold INT NULL, cell_number INT NULL, biome_id INT NULL, level INT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $db->query('CREATE TABLE map (id INT AUTO_INCREMENT PRIMARY KEY, cell_number INT NULL, coordinate_x INT NULL, coordinate_y INT NULL, biome_id INT NULL)');
        $db->query('CREATE TABLE biomes (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NULL)');
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

    private function setBool(string $key, int $val): void
    {
        Database::connect('tests')->table('game_settings')->insert([
            'setting_key' => $key, 'category' => 'world', 'value_type' => 'bool', 'value_bool' => $val,
        ]);
    }

    private function setInt(string $key, int $val): void
    {
        Database::connect('tests')->table('game_settings')->insert([
            'setting_key' => $key, 'category' => 'world', 'value_type' => 'int', 'value_int' => $val,
        ]);
    }

    private function seedSettlement(string $code, int $id, int $anchor, string $type = 'outpost', int $enabled = 1, int $x = 10, int $y = 10): void
    {
        Database::connect('tests')->table('settlements')->insert([
            'id' => $id, 'code' => $code, 'name_ru' => $code, 'type' => $type, 'anchor_cell' => $anchor,
            'coordinate_x' => $x, 'coordinate_y' => $y, 'zone_policy' => 'neutral', 'enabled' => $enabled,
        ]);
    }

    private function seedExplored(int $charId, int $cell): void
    {
        Database::connect('tests')->table('explored_cells')->insert([
            'character_id' => $charId, 'telegram_user_id' => 1, 'map_cell_id' => $cell, 'biome_id' => 1,
        ]);
    }

    private function svc(): SettlementTeleportService
    {
        return new SettlementTeleportService();
    }

    public function testReadersDefaultsWhenUnset(): void
    {
        $this->assertFalse($this->svc()->enabled());
        $this->assertSame(SettlementTeleportService::DEFAULT_COST, $this->svc()->cost());
        $this->assertSame(SettlementTeleportService::DEFAULT_COOLDOWN_MINUTES, $this->svc()->cooldownMinutes());
    }

    public function testReadersFromSettings(): void
    {
        $this->setBool('settlements.teleport.enabled', 1);
        $this->setInt('settlements.teleport.cost', 250);
        $this->setInt('settlements.teleport.cooldown_minutes', 30);
        $this->assertTrue($this->svc()->enabled());
        $this->assertSame(250, $this->svc()->cost());
        $this->assertSame(30, $this->svc()->cooldownMinutes());
    }

    public function testOpenDestinationsExcludesRuinsCurrentDisabledAndUnexplored(): void
    {
        $charId = 7;
        // id, anchor
        $this->seedSettlement('outpost', 1, 1001, 'outpost');   // open + current (excluded as current)
        $this->seedSettlement('faction', 2, 1002, 'faction');   // open → INCLUDED
        $this->seedSettlement('den',     3, 1003, 'bandit');    // open hostile → INCLUDED (owner: all except ruins)
        $this->seedSettlement('ruin',    4, 1004, 'ruins');     // open but RUINS → excluded
        $this->seedSettlement('faraway', 5, 1005, 'faction');   // NOT explored → excluded
        $this->seedSettlement('offline', 6, 1006, 'outpost', 0); // disabled → excluded

        foreach ([1001, 1002, 1003, 1004, 1006] as $cell) {
            $this->seedExplored($charId, $cell); // 1005 intentionally NOT explored
        }

        // exclude current settlement id=1 (the outpost we stand in)
        $dest = $this->svc()->openDestinations($charId, 1);
        $ids  = array_map(static fn (array $s): int => (int) $s['id'], $dest);
        sort($ids);

        $this->assertSame([2, 3], $ids, 'только открытые не-руины, кроме текущего/disabled/неисследованных');
    }

    public function testIsOpenForCharacter(): void
    {
        $charId = 9;
        $this->seedExplored($charId, 2002);
        $open = ['id' => 1, 'anchor_cell' => 2002, 'type' => 'faction'];
        $ruin = ['id' => 2, 'anchor_cell' => 2002, 'type' => 'ruins'];   // explored but ruins
        $far  = ['id' => 3, 'anchor_cell' => 3003, 'type' => 'faction']; // not explored

        $this->assertTrue($this->svc()->isOpenForCharacter($charId, $open));
        $this->assertFalse($this->svc()->isOpenForCharacter($charId, $ruin));
        $this->assertFalse($this->svc()->isOpenForCharacter($charId, $far));
    }

    public function testTeleportToMovesChargesAndSetsCooldown(): void
    {
        $this->setBool('settlements.teleport.enabled', 1);
        $this->setInt('settlements.teleport.cost', 200);
        $this->setInt('settlements.teleport.cooldown_minutes', 60);

        $db = Database::connect('tests');
        $db->table('biomes')->insert(['id' => 5, 'name' => 'Горы']);
        $db->table('map')->insert(['id' => 4242, 'cell_number' => 4242, 'coordinate_x' => 25, 'coordinate_y' => 25, 'biome_id' => 5]);
        $db->table('characters')->insert(['id' => 50, 'telegram_user_id' => 1, 'gold' => 1000, 'cell_number' => 1, 'biome_id' => 1, 'level' => 12]);
        $this->seedSettlement('dest', 1, 4242, 'faction', 1, 25, 25);
        $this->seedExplored(50, 4242);

        $res = $this->svc()->teleportTo(50, 1, 0);
        $this->assertTrue($res['success'], $res['message']);
        $this->assertSame(200, $res['cost'] ?? null);

        $char = $db->table('characters')->where('id', 50)->get()->getRowArray();
        $this->assertSame(4242, (int) $char['cell_number'], 'персонаж перемещён на якорь');
        $this->assertSame(5, (int) $char['biome_id'], 'биом обновлён');
        $this->assertSame(800, (int) $char['gold'], 'золото списано (1000-200)');

        // кулдаун взведён → повторный переход блокируется
        $this->assertGreaterThan(0, $this->svc()->cooldownRemainingSeconds(50));
        $res2 = $this->svc()->teleportTo(50, 1, 0);
        $this->assertFalse($res2['success']);
    }

    public function testTeleportToRejectsWhenDisabledOrPoor(): void
    {
        // killswitch OFF
        $res = $this->svc()->teleportTo(50, 1, 0);
        $this->assertFalse($res['success']);

        // enabled, но бедный
        $this->setBool('settlements.teleport.enabled', 1);
        $this->setInt('settlements.teleport.cost', 500);
        $db = Database::connect('tests');
        $db->table('map')->insert(['id' => 7777, 'cell_number' => 7777, 'coordinate_x' => 5, 'coordinate_y' => 5, 'biome_id' => 1]);
        $db->table('characters')->insert(['id' => 60, 'telegram_user_id' => 1, 'gold' => 100, 'cell_number' => 1, 'biome_id' => 1, 'level' => 3]);
        $this->seedSettlement('dest', 1, 7777, 'faction', 1, 5, 5);
        $this->seedExplored(60, 7777);

        $poor = $this->svc()->teleportTo(60, 1, 0);
        $this->assertFalse($poor['success'], 'недостаточно золота');
        $char = $db->table('characters')->where('id', 60)->get()->getRowArray();
        $this->assertSame(1, (int) $char['cell_number'], 'не перемещён при отказе');
    }
}
