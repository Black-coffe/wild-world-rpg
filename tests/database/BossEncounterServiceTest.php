<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Entities\BattleCharacter;
use App\Services\PVE\BossEncounterService;
use App\Services\PVE\DamageService;
use App\Services\PVE\EquipmentService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * WB6 (ADR-137 «Узлы») — BossEncounterService: killswitch, старт боя, чанк-урон, исходы
 * (победа/поражение/отступление), гейт спецприёма, хил-предмет, персистентность HP узла.
 *
 * Бой детерминирован (DamageService без mt_rand). EquipmentService замокан no-op'ом → тест не
 * зависит от таблиц экипировки (урон игрока = character.damage_value).
 *
 * @internal
 */
final class BossEncounterServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = ['game_settings', 'boss_points', 'boss_encounters', 'characters', 'npcs', 'npc_spawns', 'crafted_items', 'crafted_items_log'];

    private int $npcId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('CREATE TABLE game_settings (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(191), value_type VARCHAR(16) NULL, value_int INT NULL, value_bool TINYINT NULL, value_float DOUBLE NULL, value_string TEXT NULL)');
        $db->query("CREATE TABLE npcs (id INT AUTO_INCREMENT PRIMARY KEY, npc_name_en VARCHAR(100), npc_name_ru VARCHAR(100), strength INT DEFAULT 1, agility INT DEFAULT 1, created_at DATETIME NULL, updated_at DATETIME NULL)");
        $db->query("CREATE TABLE npc_spawns (id INT AUTO_INCREMENT PRIMARY KEY, npc_id INT, cell_number INT, coordinate_x INT, coordinate_y INT, current_health DECIMAL(7,2), spawned_at DATETIME NULL, status VARCHAR(20))");
        $db->query("CREATE TABLE boss_points (id INT AUTO_INCREMENT PRIMARY KEY, cell_number INT, coordinate_x INT, coordinate_y INT, biome_id INT NULL, y_band TINYINT, base_level INT, current_level INT, current_health INT, max_health INT, status VARCHAR(16), respawn_at DATETIME NULL, last_killer_character_id INT NULL, kill_count INT DEFAULT 0, current_npc_id INT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)");
        $db->query("CREATE TABLE boss_encounters (id INT AUTO_INCREMENT PRIMARY KEY, boss_point_id INT, character_id INT, round_no INT DEFAULT 0, player_hp INT, status VARCHAR(16) DEFAULT 'active', damage_dealt INT DEFAULT 0, last_special_round_no INT DEFAULT 0, last_action_at DATETIME NULL, created_at DATETIME NULL)");
        $db->query("CREATE TABLE characters (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100), level INT DEFAULT 1, health INT DEFAULT 100, max_health INT DEFAULT 100, tired INT DEFAULT 100, strength INT DEFAULT 1, agility INT DEFAULT 1, intellect INT DEFAULT 1, armor INT DEFAULT 0, damage_value INT DEFAULT 5, cell_number INT DEFAULT 0, created_at DATETIME NULL, updated_at DATETIME NULL)");
        $db->query("CREATE TABLE crafted_items (id INT AUTO_INCREMENT PRIMARY KEY, name_eng VARCHAR(100), type VARCHAR(20))");
        $db->query("CREATE TABLE crafted_items_log (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT, crafted_item_id INT, quantity INT, durability_time DATETIME NULL)");

        $db->table('npcs')->insert(['npc_name_en' => 'boss_scar_butcher', 'npc_name_ru' => 'Шрам', 'strength' => 6, 'agility' => 4]);
        $this->npcId = (int) $db->insertID();
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
            'cell_number' => 5000, 'coordinate_x' => 0, 'coordinate_y' => 850, 'biome_id' => 1, 'y_band' => 0,
            'base_level' => 5, 'current_level' => 5, 'current_health' => 1000, 'max_health' => 1000,
            'status' => 'alive', 'respawn_at' => null, 'kill_count' => 0, 'current_npc_id' => $this->npcId,
        ], $over));

        return (int) $db->insertID();
    }

    /** @param array<string,mixed> $over @return array<string,mixed> */
    private function seedChar(array $over = []): array
    {
        $db   = Database::connect('tests');
        $base = array_merge([
            'name' => 'Hero', 'level' => 10, 'health' => 100, 'max_health' => 100, 'tired' => 100,
            'strength' => 5, 'agility' => 5, 'intellect' => 5, 'armor' => 0, 'damage_value' => 100, 'cell_number' => 5000,
        ], $over);
        $db->table('characters')->insert($base);
        $base['id'] = (int) $db->insertID();

        return $base;
    }

    private function service(): BossEncounterService
    {
        $logger = service('logger');
        $noopEquip = new class($logger) extends EquipmentService {
            public function applyEquipmentBonuses(BattleCharacter $character): void {}
        };

        return new BossEncounterService(new DamageService($logger), $noopEquip);
    }

    private function point(int $id): array
    {
        return Database::connect('tests')->table('boss_points')->where('id', $id)->get()->getRowArray() ?? [];
    }

    private function activeEnc(int $charId): ?array
    {
        $r = Database::connect('tests')->table('boss_encounters')->where('character_id', $charId)->get()->getRowArray();

        return is_array($r) ? $r : null;
    }

    // ----------------------------------------------------------------

    public function testDormantReturnsAlert(): void
    {
        // killswitch OFF.
        $this->seedPoint();
        $char = $this->seedChar();
        $screen = $this->service()->open($char);
        $this->assertArrayHasKey('alert', $screen);
    }

    public function testStartCreatesActiveEncounter(): void
    {
        $this->enable();
        $pid  = $this->seedPoint();
        $char = $this->seedChar(['health' => 80]);
        $screen = $this->service()->start($char);

        $this->assertArrayHasKey('text', $screen);
        $enc = $this->activeEnc((int) $char['id']);
        $this->assertNotNull($enc);
        $this->assertSame('active', $enc['status']);
        $this->assertSame($pid, (int) $enc['boss_point_id']);
        $this->assertSame(80, (int) $enc['player_hp']);
    }

    public function testAttackChipsBossHpPersisted(): void
    {
        $this->enable();
        $pid  = $this->seedPoint(['current_health' => 1000]);
        $char = $this->seedChar();
        $svc  = $this->service();
        $svc->start($char);
        $svc->act($char, 'atk');

        $p = $this->point($pid);
        $this->assertLessThan(1000, (int) $p['current_health'], 'урон по узлу записан');
        $this->assertGreaterThan(0, (int) $p['current_health'], 'узел ещё жив');
        $this->assertSame('alive', $p['status']);
        $enc = $this->activeEnc((int) $char['id']);
        $this->assertSame(3, (int) $enc['round_no']); // rounds_per_tap default
        $this->assertGreaterThan(0, (int) $enc['damage_dealt']);
    }

    public function testWinPutsNodeOnCooldown(): void
    {
        $this->enable();
        $pid  = $this->seedPoint(['current_health' => 5]); // одного удара хватит
        $db   = Database::connect('tests');
        $db->table('npc_spawns')->insert(['npc_id' => $this->npcId, 'cell_number' => 5000, 'coordinate_x' => 0, 'coordinate_y' => 850, 'current_health' => 5, 'status' => 'alive']);
        $char = $this->seedChar();
        $svc  = $this->service();
        $svc->start($char);
        $screen = $svc->act($char, 'atk');

        $this->assertArrayHasKey('text', $screen);
        $p = $this->point($pid);
        $this->assertSame('cooldown', $p['status']);
        $this->assertSame(1, (int) $p['kill_count']);
        $this->assertSame((int) $char['id'], (int) $p['last_killer_character_id']);
        $this->assertNotNull($p['respawn_at']);
        $enc = $this->activeEnc((int) $char['id']);
        $this->assertSame('won', $enc['status']);
        // материализованный спавн снят
        $spawns = $db->table('npc_spawns')->where('cell_number', 5000)->where('status', 'alive')->countAllResults();
        $this->assertSame(0, $spawns);
    }

    public function testFleeKeepsBossWounds(): void
    {
        $this->enable();
        $pid  = $this->seedPoint(['current_health' => 1000]);
        $char = $this->seedChar();
        $svc  = $this->service();
        $svc->start($char);
        $svc->act($char, 'atk');
        $hpAfterHit = (int) $this->point($pid)['current_health'];
        $svc->act($char, 'flee');

        $p = $this->point($pid);
        $this->assertSame('alive', $p['status'], 'отступление не снимает узел');
        $this->assertSame($hpAfterHit, (int) $p['current_health'], 'раны на узле остаются (персистентны)');
        $enc = $this->activeEnc((int) $char['id']);
        $this->assertSame('fled', $enc['status']);
    }

    public function testSpecialRequiresStamina(): void
    {
        $this->enable();
        $pid  = $this->seedPoint(['current_health' => 1000]);
        $char = $this->seedChar(['tired' => 0]);
        $svc  = $this->service();
        $svc->start($char);
        $screen = $svc->act($char, 'spec');

        $this->assertArrayHasKey('alert', $screen, 'без выносливости спецприём = всплывашка');
        $this->assertSame(1000, (int) $this->point($pid)['current_health'], 'чанк не сыгран');
    }

    public function testItemNoItemAlert(): void
    {
        $this->enable();
        $this->seedPoint(['current_health' => 1000]);
        $char = $this->seedChar();
        $svc  = $this->service();
        $svc->start($char);
        $screen = $svc->act($char, 'item');
        $this->assertArrayHasKey('alert', $screen);
    }

    public function testItemHealsAndConsumes(): void
    {
        $this->enable();
        $this->seedPoint(['current_health' => 1000]);
        $char = $this->seedChar(['health' => 40, 'max_health' => 200]);
        $db   = Database::connect('tests');
        $db->table('crafted_items')->insert(['name_eng' => 'first_aid_kit', 'type' => 'drug']);
        $itemId = (int) $db->insertID();
        $db->table('crafted_items_log')->insert(['character_id' => $char['id'], 'crafted_item_id' => $itemId, 'quantity' => 2]);

        $svc = $this->service();
        $svc->start($char);
        $screen = $svc->act($char, 'item');
        $this->assertArrayHasKey('text', $screen, 'предмет применён → боевой экран, не алерт');
        $qty = (int) $db->table('crafted_items_log')->where('crafted_item_id', $itemId)->get()->getRowArray()['quantity'];
        $this->assertSame(1, $qty, 'медикамент потрачен (2→1)');
    }
}
