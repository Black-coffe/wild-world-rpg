<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Entities\BattleCharacter;
use App\Services\PVE\BossEncounterService;
use App\Services\PVE\DamageService;
use App\Services\PVE\EquipmentService;
use App\Services\Player\DeathService;
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

    private const TABLES = ['game_settings', 'boss_points', 'boss_encounters', 'boss_engagements', 'boss_camp_state', 'boss_kill_announce_queue', 'titles', 'character_titles', 'characters', 'npcs', 'npc_spawns', 'crafted_items', 'crafted_items_log', 'characters_weapons', 'characters_outfits'];

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
        $db->query('CREATE TABLE boss_engagements (id INT AUTO_INCREMENT PRIMARY KEY, boss_point_id INT, boss_level INT, character_id INT, damage_dealt INT DEFAULT 0, rounds_participated INT DEFAULT 0, first_hit_at DATETIME NULL, last_hit_at DATETIME NULL, UNIQUE KEY uniq_contrib (boss_point_id, boss_level, character_id))');
        $db->query('CREATE TABLE boss_camp_state (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT, boss_point_id INT, first_seen_in_zone_at DATETIME NULL, dwell_minutes INT DEFAULT 0, loot_locked_until DATETIME NULL, last_warned_at DATETIME NULL, UNIQUE KEY uniq_cs (character_id, boss_point_id))');
        $db->query("CREATE TABLE characters (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100), level INT DEFAULT 1, health INT DEFAULT 100, max_health INT DEFAULT 100, tired INT DEFAULT 100, strength INT DEFAULT 1, agility INT DEFAULT 1, intellect INT DEFAULT 1, armor INT DEFAULT 0, damage_value INT DEFAULT 5, gold INT DEFAULT 0, cell_number INT DEFAULT 0, active_title_id INT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)");
        $db->query("CREATE TABLE crafted_items (id INT AUTO_INCREMENT PRIMARY KEY, name_eng VARCHAR(100), type VARCHAR(20))");
        $db->query("CREATE TABLE crafted_items_log (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT, crafted_item_id INT, quantity INT, durability_time DATETIME NULL)");
        $db->query("CREATE TABLE characters_weapons (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT, weapon_id INT, equipped TINYINT DEFAULT 0, is_soulbound TINYINT DEFAULT 0)");
        $db->query("CREATE TABLE characters_outfits (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT, outfit_id INT, equipped TINYINT DEFAULT 0, is_soulbound TINYINT DEFAULT 0)");

        $db->query("CREATE TABLE boss_kill_announce_queue (id INT AUTO_INCREMENT PRIMARY KEY, boss_point_id INT, boss_level INT, biome_id INT NULL, status VARCHAR(16) DEFAULT 'pending', created_at DATETIME NULL)");
        $db->query('CREATE TABLE titles (id INT AUTO_INCREMENT PRIMARY KEY, title_key VARCHAR(64), name VARCHAR(64), description VARCHAR(255) NULL, source_type VARCHAR(16), source_ref VARCHAR(64), icon VARCHAR(16) NULL, sort_order INT DEFAULT 0, enabled TINYINT DEFAULT 1)');
        $db->query('CREATE TABLE character_titles (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT, title_id INT, unlocked_at DATETIME NULL, UNIQUE KEY uniq_ct (character_id, title_id))');

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

    private function service(?DeathService $death = null): BossEncounterService
    {
        $logger = service('logger');
        $noopEquip = new class($logger) extends EquipmentService {
            public function applyEquipmentBonuses(BattleCharacter $character): void {}
        };
        $death ??= $this->deathSpy();

        return new BossEncounterService(new DamageService($logger), $noopEquip, $death);
    }

    /** Спай DeathService — считает вызовы, без реальной смерти/respawn (нет таблиц respawn в тесте). */
    private function deathSpy(): DeathService
    {
        return new class extends DeathService {
            public int $calls = 0;

            public function handlePlayerDeathAndReward(int $loserId, ?int $winnerId = null): array
            {
                $this->calls++;

                return ['hasBase' => false, 'penalty' => 0.0, 'newbieProtected' => false, 'transferredResources' => [], 'transferredCraftItems' => [], 'transferredGold' => 0, 'success' => true];
            }
        };
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

    // ───────────────────────── WB7 ─────────────────────────

    public function testFleeAppliesPartingBlowAndTiredCost(): void
    {
        $this->enable();
        $this->seedPoint(['current_health' => 1000, 'current_level' => 5]);
        $char = $this->seedChar(['health' => 200, 'tired' => 100]);
        $svc  = $this->service();
        $svc->start($char);
        $svc->act($char, 'flee');

        $row = Database::connect('tests')->table('characters')->where('id', $char['id'])->get()->getRowArray();
        $this->assertLessThan(200, (int) $row['health'], 'парт-инг-удар снял HP при отступлении');
        $this->assertGreaterThanOrEqual(1, (int) $row['health'], 'отступление не смертельно (floor 1)');
        $this->assertSame(90, (int) $row['tired'], 'штраф выносливости flee_tired_cost=10 (100→90)');
    }

    public function testFleePartingBlowNeverKills(): void
    {
        $this->enable();
        $this->seedPoint(['current_health' => 1000, 'current_level' => 40]); // сильный узел
        $char = $this->seedChar(['health' => 1, 'tired' => 100]); // на грани
        $svc  = $this->service();
        $svc->start($char, true); // WB13: разрыв L40 vs L10 → force минует предупреждение (тест про отступление)
        $svc->act($char, 'flee');

        $row = Database::connect('tests')->table('characters')->where('id', $char['id'])->get()->getRowArray();
        $this->assertSame(1, (int) $row['health'], 'отступление с 1 HP оставляет 1 (floor), не убивает');
    }

    public function testEngageCooldownBlocksReentry(): void
    {
        $this->enable();
        $pid  = $this->seedPoint(['current_health' => 1000]);
        $char = $this->seedChar();
        $svc  = $this->service();
        $svc->start($char);
        $svc->act($char, 'flee');

        // сразу пробуем зайти снова на тот же узел → откат
        $screen = $svc->start($char);
        $this->assertArrayHasKey('alert', $screen, 'повторный заход сразу после отхода заблокирован');
        // нового active-боя не появилось
        $active = Database::connect('tests')->table('boss_encounters')->where('character_id', $char['id'])->where('status', 'active')->countAllResults();
        $this->assertSame(0, $active);
    }

    public function testEngageCooldownExpiresAllowsReentry(): void
    {
        $this->enable();
        $pid  = $this->seedPoint(['current_health' => 1000]);
        $char = $this->seedChar();
        $svc  = $this->service();
        $svc->start($char);
        $svc->act($char, 'flee');

        // отодвигаем last_action_at fled-боя за окно отката (5 мин default)
        Database::connect('tests')->table('boss_encounters')
            ->where('character_id', $char['id'])->where('status', 'fled')
            ->update(['last_action_at' => date('Y-m-d H:i:s', strtotime('-10 minutes'))]);

        $screen = $svc->start($char);
        $this->assertArrayHasKey('text', $screen, 'после истечения отката заход снова возможен');
        $active = Database::connect('tests')->table('boss_encounters')->where('character_id', $char['id'])->where('status', 'active')->countAllResults();
        $this->assertSame(1, $active);
    }

    public function testLethalOutcomeInvokesDeathService(): void
    {
        $this->enable();
        $this->seedPoint(['current_health' => 100000, 'current_level' => 40]); // не убиваем узел
        $char  = $this->seedChar(['health' => 1]); // одного удара узла хватит
        $spy   = $this->deathSpy();
        $svc   = $this->service($spy);
        $svc->start($char, true); // WB13: L40 vs L10 → force минует предупреждение (тест про летальный исход)
        $svc->act($char, 'atk');

        $enc = $this->activeEnc((int) $char['id']);
        $this->assertSame('lost', $enc['status']);
        $this->assertSame(1, $spy->calls, 'летальный исход вызвал DeathService::handlePlayerDeathAndReward');
    }

    // ───────────────────────── WB8 «Облава» ─────────────────────────

    public function testAttackRecordsEngagementContribution(): void
    {
        $this->enable();
        $this->seedPoint(['current_health' => 1000, 'current_level' => 7]);
        $char = $this->seedChar();
        $svc  = $this->service();
        $svc->start($char);
        $svc->act($char, 'atk');

        $db   = Database::connect('tests');
        $rows = $db->table('boss_engagements')->where('character_id', $char['id'])->get()->getResultArray();
        $this->assertCount(1, $rows, 'удар записал вклад в ledger «Облавы»');
        $this->assertSame(7, (int) $rows[0]['boss_level'], 'boss_level = уровень узла на момент вклада');
        $this->assertGreaterThan(0, (int) $rows[0]['damage_dealt'], 'урон чанка учтён');
    }

    public function testWinGrantsOblavaGoldAndPurgesLedger(): void
    {
        $this->enable();
        // пул gold = level(5) * gold_per_level → задаём детерминированно
        $db = Database::connect('tests');
        $db->table('game_settings')->insert(['setting_key' => 'combat.nodes.gold_per_level', 'value_type' => 'int', 'value_int' => 100]);
        $db->table('game_settings')->insert(['setting_key' => 'combat.nodes.gold_floor', 'value_type' => 'int', 'value_int' => 0]);
        $db->table('game_settings')->insert(['setting_key' => 'combat.nodes.gold_hard_cap', 'value_type' => 'int', 'value_int' => 1000000]);
        $db->table('game_settings')->insert(['setting_key' => 'combat.nodes.loot_min_contribution_pct', 'value_type' => 'float', 'value_float' => 0.0]);
        $this->cleanCache();

        $pid  = $this->seedPoint(['current_health' => 5, 'current_level' => 5, 'max_health' => 1000]);
        $db->table('npc_spawns')->insert(['npc_id' => $this->npcId, 'cell_number' => 5000, 'coordinate_x' => 0, 'coordinate_y' => 850, 'current_health' => 5, 'status' => 'alive']);
        $char = $this->seedChar(['gold' => 0]);
        $svc  = $this->service();
        $svc->start($char);
        $screen = $svc->act($char, 'atk');

        $this->assertArrayHasKey('text', $screen);
        $goldRow = $db->table('characters')->where('id', $char['id'])->get()->getRowArray();
        $this->assertSame(500, (int) $goldRow['gold'], 'солo-килл = весь пул (5*100) победителю');
        $leftover = $db->table('boss_engagements')->where('boss_point_id', $pid)->countAllResults();
        $this->assertSame(0, $leftover, 'ledger потреблён при килле');
    }

    // ───────────────────────── WB10 анти-кемп на kill-пути ─────────────────────────

    public function testLockedKillerGetsNoCreditAndCampNotice(): void
    {
        $this->enable();
        $db = Database::connect('tests');
        $db->table('game_settings')->insert(['setting_key' => 'combat.nodes.gold_per_level', 'value_type' => 'int', 'value_int' => 100]);
        $db->table('game_settings')->insert(['setting_key' => 'combat.nodes.gold_floor', 'value_type' => 'int', 'value_int' => 0]);
        $db->table('game_settings')->insert(['setting_key' => 'combat.nodes.gold_hard_cap', 'value_type' => 'int', 'value_int' => 1000000]);
        $db->table('game_settings')->insert(['setting_key' => 'combat.nodes.loot_min_contribution_pct', 'value_type' => 'float', 'value_float' => 0.0]);
        $this->cleanCache();

        $pid  = $this->seedPoint(['current_health' => 5, 'current_level' => 5, 'max_health' => 1000]);
        $db->table('npc_spawns')->insert(['npc_id' => $this->npcId, 'cell_number' => 5000, 'coordinate_x' => 0, 'coordinate_y' => 850, 'current_health' => 5, 'status' => 'alive']);
        $char = $this->seedChar(['gold' => 0]);
        // Killer залочен у этой точки (накемпил).
        $db->table('boss_camp_state')->insert(['character_id' => $char['id'], 'boss_point_id' => $pid, 'dwell_minutes' => 720, 'loot_locked_until' => date('Y-m-d H:i:s', time() + 3600)]);

        $svc = $this->service();
        $svc->start($char);
        $screen = $svc->act($char, 'atk');

        $p = $this->point($pid);
        $this->assertNull($p['last_killer_character_id'], 'залоченный кемпер НЕ killer для анонса (кредит обнулён)');
        $this->assertStringContainsString('Гарь узла', (string) $screen['text'], 'кемперу объясняют, почему трофей прошёл мимо');
        $this->assertSame(500, (int) $db->table('characters')->where('id', $char['id'])->get()->getRowArray()['gold'], 'золото за вклад кемпер всё равно получает');
    }

    // ───────────────────────── WB11 анонс-очередь + титул ─────────────────────────

    /** Включить анонс с порогом + титулы + сид-титул под архетип узла. */
    private function enableAnnounceAndTitles(int $minLevel = 1): void
    {
        $db = Database::connect('tests');
        $db->table('game_settings')->insert(['setting_key' => 'world.nodes.announce_enabled', 'value_type' => 'bool', 'value_bool' => 1]);
        $db->table('game_settings')->insert(['setting_key' => 'world.nodes.announce_min_level', 'value_type' => 'int', 'value_int' => $minLevel]);
        $db->table('game_settings')->insert(['setting_key' => 'titles.enabled', 'value_type' => 'bool', 'value_bool' => 1]);
        // Гасим soulbound-лотерею (она бы дёрнула weapons/outfits, отсутствующие в этом тесте) — трофей покрыт BossLootServiceTest.
        $db->table('game_settings')->insert(['setting_key' => 'combat.nodes.soulbound_min_level', 'value_type' => 'int', 'value_int' => 99999]);
        $db->table('titles')->insert(['title_key' => 'boss_kill_scar', 'name' => 'Убийца Мясника', 'source_type' => 'boss_kill', 'source_ref' => 'boss_scar_butcher', 'icon' => '🔪', 'sort_order' => 190, 'enabled' => 1]);
        $this->cleanCache();
    }

    private function winKill(): array
    {
        $db   = Database::connect('tests');
        $pid  = $this->seedPoint(['current_health' => 5, 'current_level' => 120, 'max_health' => 1000, 'biome_id' => 1]);
        $db->table('npc_spawns')->insert(['npc_id' => $this->npcId, 'cell_number' => 5000, 'coordinate_x' => 0, 'coordinate_y' => 850, 'current_health' => 5, 'status' => 'alive']);
        $char = $this->seedChar(['gold' => 0]);
        $svc  = $this->service();
        $svc->start($char, true); // WB13: узел L120 vs L10 → force минует предупреждение (тест про килл/анонс)
        $svc->act($char, 'atk');

        return ['pid' => $pid, 'char' => $char];
    }

    public function testKillEnqueuesAnnounceAndAwardsTitle(): void
    {
        $this->enable();
        $this->enableAnnounceAndTitles(100); // L120 ≥ 100 → квалифицирует
        $r  = $this->winKill();
        $db = Database::connect('tests');

        $q = $db->table('boss_kill_announce_queue')->where('status', 'pending')->get()->getResultArray();
        $this->assertCount(1, $q, 'квалифицирующий килл попал в очередь анонса');
        $this->assertSame(120, (int) $q[0]['boss_level']);
        $this->assertSame(1, (int) $q[0]['biome_id']);

        $titles = $db->table('character_titles')->where('character_id', $r['char']['id'])->countAllResults();
        $this->assertSame(1, $titles, 'убийце выдан титул «Убийца Мясника»');
    }

    public function testKillBelowThresholdNotEnqueuedButTitleAwarded(): void
    {
        $this->enable();
        $this->enableAnnounceAndTitles(200); // L120 < 200 → НЕ квалифицирует анонс
        $r  = $this->winKill();
        $db = Database::connect('tests');

        $this->assertSame(0, $db->table('boss_kill_announce_queue')->countAllResults(), 'мелкий килл (ниже порога) не глобалят');
        $this->assertSame(1, $db->table('character_titles')->where('character_id', $r['char']['id'])->countAllResults(), 'титул всё равно выдан (порог только для анонса)');
    }

    public function testLockedKillerNoTitleButStillAnnounced(): void
    {
        $this->enable();
        $this->enableAnnounceAndTitles(100);
        $db  = Database::connect('tests');
        $pid = $this->seedPoint(['current_health' => 5, 'current_level' => 120, 'max_health' => 1000, 'biome_id' => 1]);
        $db->table('npc_spawns')->insert(['npc_id' => $this->npcId, 'cell_number' => 5000, 'coordinate_x' => 0, 'coordinate_y' => 850, 'current_health' => 5, 'status' => 'alive']);
        $char = $this->seedChar(['gold' => 0]);
        $db->table('boss_camp_state')->insert(['character_id' => $char['id'], 'boss_point_id' => $pid, 'dwell_minutes' => 720, 'loot_locked_until' => date('Y-m-d H:i:s', time() + 3600)]);

        $svc = $this->service();
        $svc->start($char, true); // WB13: узел L120 vs L10 → force минует предупреждение (тест про анонс/титул кемпера)
        $svc->act($char, 'atk');

        $this->assertSame(1, $db->table('boss_kill_announce_queue')->countAllResults(), 'узел пал → анонс enqueue (обезличен, не зависит от лока)');
        $this->assertSame(0, $db->table('character_titles')->where('character_id', $char['id'])->countAllResults(), 'залоченный кемпер титул НЕ получает (нет кредита за килл)');
    }

    // ───────────────────────── WB12 находимость: pointAtCell + карточка осмотра ─────────────────────────

    public function testPointAtCellReturnsAliveAndCooldownButNotWhenDisabled(): void
    {
        $svc = $this->service();
        $this->seedPoint(['cell_number' => 5000, 'status' => 'alive']);
        $this->seedPoint(['cell_number' => 5001, 'status' => 'cooldown']);

        // point_mode OFF → null даже при наличии точки.
        $this->assertNull($svc->pointAtCell(5000), 'dormant → нет узла');

        $this->enable();
        $this->assertNotNull($svc->pointAtCell(5000), 'alive узел найден');
        $this->assertNotNull($svc->pointAtCell(5001), 'cooldown узел тоже найден (для lock-state)');
        $this->assertNull($svc->pointAtCell(5999), 'на пустой клетке узла нет');
    }

    public function testLookExamineAliveNodeShowsTierLootAndAttack(): void
    {
        $this->enable();
        $this->seedPoint(['cell_number' => 5000, 'status' => 'alive', 'current_level' => 5, 'current_health' => 100, 'max_health' => 140]);
        $char   = $this->seedChar(['level' => 10, 'cell_number' => 5000]);
        $screen = $this->service()->look($char);

        $this->assertArrayHasKey('text', $screen);
        $this->assertStringContainsString('Осмотр узла', $screen['text']);
        $this->assertStringContainsString('⚖️ Оценка', $screen['text']);
        $this->assertStringContainsString('по силам', $screen['text'], 'узел L5 vs игрок L10 → 🟢 по силам');
        $this->assertStringContainsString('золото', $screen['text'], 'превью лута');
        $this->assertStringNotContainsString('Метка пустоши', $screen['text'], 'L5 < soulbound_min_level → трофей не обещаем');
        // Кнопка «Напасть» присутствует.
        $kb = json_encode($screen['keyboard']);
        $this->assertStringContainsString('nodeAct_start_', (string) $kb);
    }

    public function testLookExamineHighNodeNeedsGroupAndPromisesTrophy(): void
    {
        $this->enable();
        Database::connect('tests')->table('game_settings')->insert(['setting_key' => 'combat.nodes.soulbound_min_level', 'value_type' => 'int', 'value_int' => 50]);
        $this->cleanCache();
        $this->seedPoint(['cell_number' => 5000, 'status' => 'alive', 'current_level' => 120, 'current_health' => 4000, 'max_health' => 4500]);
        $char   = $this->seedChar(['level' => 10, 'cell_number' => 5000]);
        $screen = $this->service()->look($char);

        $this->assertStringContainsString('нужна группа', $screen['text'], 'узел L120 vs игрок L10 → 🔴 нужна группа');
        $this->assertStringContainsString('Метка пустоши', $screen['text'], 'L120 ≥ soulbound_min_level → трофей в превью');
    }

    public function testLookCooldownNodeShowsRespawnTimer(): void
    {
        $this->enable();
        $this->seedPoint(['cell_number' => 5000, 'status' => 'cooldown', 'current_health' => 0, 'respawn_at' => date('Y-m-d H:i:s', time() + 7200)]);
        $char   = $this->seedChar(['level' => 10, 'cell_number' => 5000]);
        $screen = $this->service()->look($char);

        $this->assertStringContainsString('Узел повержен', $screen['text']);
        $this->assertStringContainsString('через', $screen['text'], 'lock-state: показан таймер респавна, не молчание');
        $kb = json_encode($screen['keyboard']);
        $this->assertStringNotContainsString('nodeAct_start_', (string) $kb, 'кулдаун → нельзя напасть');
    }

    public function testLookNoNodeAlert(): void
    {
        $this->enable();
        $char = $this->seedChar(['cell_number' => 9999]);
        $this->assertArrayHasKey('alert', $this->service()->look($char));
    }

    // ───────────────────────── WB13 предупреждение о разрыве уровня ─────────────────────────

    public function testStartWarnsOnLethalLevelGap(): void
    {
        $this->enable();
        $this->seedPoint(['current_level' => 120, 'current_health' => 4000, 'max_health' => 4500]);
        $char   = $this->seedChar(['level' => 10]);
        $screen = $this->service()->start($char);

        $this->assertArrayHasKey('text', $screen, 'разрыв L120 vs L10 → экран-предупреждение, не сразу бой');
        $this->assertStringContainsString('разрыв уровня', $screen['text']);
        $kb = json_encode($screen['keyboard']);
        $this->assertStringContainsString('nodeAct_force_', (string) $kb, 'есть кнопка «Всё равно напасть» (force)');
        // Информирование, не блок: active-бой ещё НЕ создан (игрок не подтвердил).
        $active = Database::connect('tests')->table('boss_encounters')
            ->where('character_id', $char['id'])->where('status', 'active')->countAllResults();
        $this->assertSame(0, $active, 'предупреждение не создаёт бой');
    }

    public function testStartForceBypassesLevelGapWarning(): void
    {
        $this->enable();
        $this->seedPoint(['current_level' => 120, 'current_health' => 4000, 'max_health' => 4500]);
        $char   = $this->seedChar(['level' => 10, 'health' => 80]);
        $screen = $this->service()->start($char, true);

        $this->assertArrayHasKey('text', $screen);
        $this->assertStringContainsString('Бой с узлом', $screen['text'], 'force → сразу боевой экран');
        $enc = $this->activeEnc((int) $char['id']);
        $this->assertNotNull($enc);
        $this->assertSame('active', $enc['status'], 'force создал active-бой вопреки разрыву');
    }

    public function testStartNoWarningWhenGapWithinThreshold(): void
    {
        $this->enable();
        // diff = 15 = порог combat.nodes.tier_hard_diff (строго >) → НЕ предупреждаем.
        $this->seedPoint(['current_level' => 25]);
        $char   = $this->seedChar(['level' => 10]);
        $screen = $this->service()->start($char);

        $this->assertStringContainsString('Бой с узлом', $screen['text'], 'разрыв ровно на пороге (15) → сразу бой');
        $this->assertSame('active', $this->activeEnc((int) $char['id'])['status']);
    }

    // ───────────────────────── WB9 raid-only бонус ─────────────────────────

    public function testSoulboundTrophiesBoostNodeDamageOnly(): void
    {
        $this->enable();
        $db = Database::connect('tests');
        $db->table('game_settings')->insert(['setting_key' => 'combat.nodes.soulbound_raid_bonus_pct', 'value_type' => 'float', 'value_float' => 0.2]);
        $db->table('game_settings')->insert(['setting_key' => 'combat.nodes.soulbound_raid_bonus_cap', 'value_type' => 'float', 'value_float' => 1.0]);
        $this->cleanCache();

        // Контрольный чар без трофеев.
        $this->seedPoint(['current_health' => 100000, 'current_level' => 5, 'max_health' => 100000]);
        $plain = $this->seedChar(['damage_value' => 100]);
        $svc   = $this->service();
        $svc->start($plain);
        $svc->act($plain, 'atk');
        $baseDmg = (int) $this->activeEnc((int) $plain['id'])['damage_dealt'];

        // Чар с 2 soulbound-трофеями (оружие+броня) → бонус ×(1 + min(1.0, 2×0.2)) = ×1.4.
        $this->seedPoint(['current_health' => 100000, 'current_level' => 5, 'max_health' => 100000, 'cell_number' => 5001]);
        $hero = $this->seedChar(['damage_value' => 100, 'cell_number' => 5001]);
        $db->table('characters_weapons')->insert(['character_id' => $hero['id'], 'weapon_id' => 1, 'equipped' => 0, 'is_soulbound' => 1]);
        $db->table('characters_outfits')->insert(['character_id' => $hero['id'], 'outfit_id' => 1, 'equipped' => 0, 'is_soulbound' => 1]);
        $svc2 = $this->service();
        $svc2->start($hero);
        $svc2->act($hero, 'atk');
        $boostDmg = (int) $this->activeEnc((int) $hero['id'])['damage_dealt'];

        $this->assertGreaterThan($baseDmg, $boostDmg, 'raid-only бонус трофеев усилил урон по узлу');
        // sanity: ~×1.4 (2 трофея × 0.2), допускаем округление чанка.
        $this->assertGreaterThan((int) ($baseDmg * 1.3), $boostDmg);
    }
}
