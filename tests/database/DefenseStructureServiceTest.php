<?php

namespace Tests\Database;

use App\Services\PVE\DefenseStructureService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * S26 (ADR-030) — DefenseStructureService: gating + cap + decay.
 *
 * Защита применяется только когда защитник стоит на своей клетке с active
 * (hp>0) defensive-структурами. Combat-балАнсы из GameSettings, cap клампит.
 *
 * @internal
 */
final class DefenseStructureServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private int $wallId = 0;
    private int $fenceId = 0;
    private int $towerId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();

        $db = Database::connect('tests');
        $db->query('DROP TABLE IF EXISTS character_buildings');
        $db->query('DROP TABLE IF EXISTS buildings');
        $db->query('DROP TABLE IF EXISTS game_settings');

        $db->query('
            CREATE TABLE buildings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name_en VARCHAR(150) NULL
            )
        ');
        $db->query('
            CREATE TABLE character_buildings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                character_id INT NULL,
                building_id INT NULL,
                map_cell_id INT NULL,
                building_type VARCHAR(32) NULL,
                hp INT NULL,
                level INT NULL DEFAULT 1
            )
        ');
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

        $db->table('buildings')->insert(['name_en' => 'WoodenWall']);
        $this->wallId = (int) $db->insertID();
        $db->table('buildings')->insert(['name_en' => 'BarbedFence']);
        $this->fenceId = (int) $db->insertID();
        $db->table('buildings')->insert(['name_en' => 'WatchTower']);
        $this->towerId = (int) $db->insertID();

        $settings = [
            ['defense.wall.damage_reduction_percent', 15],
            ['defense.fence.attacker_damage_per_round', 3],
            ['defense.total_damage_reduction_max_percent', 40],
            ['defense.decay_hp_per_attack', 10],
            ['defense.tower.defender_initiative_bonus_percent', 8],
            ['defense.scaling.per_level_percent', 20], // ADR-041: levelMult = 1 + 0.20×(lvl−1)
        ];
        foreach ($settings as [$key, $val]) {
            $db->table('game_settings')->insert([
                'setting_key' => $key, 'category' => 'combat', 'value_type' => 'int',
                'value_int' => $val, 'hard_min' => '0', 'hard_max' => '100',
            ]);
        }
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        $db->query('DROP TABLE IF EXISTS character_buildings');
        $db->query('DROP TABLE IF EXISTS buildings');
        $db->query('DROP TABLE IF EXISTS game_settings');
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

    private function buildStructure(int $charId, int $buildingId, int $cell, int $hp, int $level = 1): int
    {
        $db = Database::connect('tests');
        $db->table('character_buildings')->insert([
            'character_id' => $charId, 'building_id' => $buildingId,
            'map_cell_id' => $cell, 'building_type' => 'defensive', 'hp' => $hp, 'level' => $level,
        ]);
        return (int) $db->insertID();
    }

    public function testProfileWallAndFenceAtBase(): void
    {
        $this->buildStructure(1, $this->wallId, 100, 200);
        $this->buildStructure(1, $this->fenceId, 100, 80);

        $profile = (new DefenseStructureService())->getDefenseProfile(1, 100);

        $this->assertNotNull($profile);
        $this->assertSame(1, $profile['owner_id']);
        $this->assertEqualsWithDelta(0.15, $profile['damage_reduction'], 0.0001);
        $this->assertSame(3, $profile['fence_damage']);
        $this->assertEqualsWithDelta(0.0, $profile['initiative_bonus'], 0.0001); // нет вышки
        $this->assertCount(2, $profile['structure_ids']);
    }

    public function testNullWhenNotOnStructureCell(): void
    {
        $this->buildStructure(1, $this->wallId, 100, 200);
        $this->assertNull((new DefenseStructureService())->getDefenseProfile(1, 999));
    }

    public function testNullForNonOwner(): void
    {
        $this->buildStructure(1, $this->wallId, 100, 200);
        $this->assertNull((new DefenseStructureService())->getDefenseProfile(2, 100));
    }

    public function testBrokenStructureExcluded(): void
    {
        $this->buildStructure(1, $this->wallId, 100, 0); // сломана
        $this->assertNull((new DefenseStructureService())->getDefenseProfile(1, 100));
    }

    public function testReductionCappedAtGlobalMax(): void
    {
        // 5 стен × 15% = 75% → клампится в 40%.
        for ($i = 0; $i < 5; $i++) {
            $this->buildStructure(1, $this->wallId, 100, 200);
        }
        $profile = (new DefenseStructureService())->getDefenseProfile(1, 100);
        $this->assertNotNull($profile);
        $this->assertEqualsWithDelta(0.40, $profile['damage_reduction'], 0.0001);
    }

    public function testApplyDecayReducesHp(): void
    {
        $id = $this->buildStructure(1, $this->wallId, 100, 200);
        (new DefenseStructureService())->applyDecay([$id]);
        $hp = (int) Database::connect('tests')->table('character_buildings')->where('id', $id)->get()->getRowArray()['hp'];
        $this->assertSame(190, $hp); // 200 − 10
    }

    // ---- S26b (ADR-031): WatchTower initiative ----

    public function testTowerOnlyProfileGivesInitiative(): void
    {
        // Только вышка (без стен/ограды) → профиль не null, есть инициатива.
        $this->buildStructure(1, $this->towerId, 100, 300);

        $profile = (new DefenseStructureService())->getDefenseProfile(1, 100);

        $this->assertNotNull($profile);
        $this->assertEqualsWithDelta(0.0, $profile['damage_reduction'], 0.0001);
        $this->assertSame(0, $profile['fence_damage']);
        $this->assertEqualsWithDelta(0.08, $profile['initiative_bonus'], 0.0001);
        $this->assertCount(1, $profile['structure_ids']); // вышка decays
    }

    public function testTowerInitiativePresenceBasedNotStacked(): void
    {
        // 2 вышки → бонус всё равно 8% (presence-based, анти-эксплойт).
        $this->buildStructure(1, $this->towerId, 100, 300);
        $this->buildStructure(1, $this->towerId, 100, 300);

        $profile = (new DefenseStructureService())->getDefenseProfile(1, 100);

        $this->assertNotNull($profile);
        $this->assertEqualsWithDelta(0.08, $profile['initiative_bonus'], 0.0001);
        $this->assertCount(2, $profile['structure_ids']); // обе decays
    }

    public function testWallAndTowerCombined(): void
    {
        // Стена (reduction) + вышка (initiative) одновременно.
        $this->buildStructure(1, $this->wallId, 100, 200);
        $this->buildStructure(1, $this->towerId, 100, 300);

        $profile = (new DefenseStructureService())->getDefenseProfile(1, 100);

        $this->assertNotNull($profile);
        $this->assertEqualsWithDelta(0.15, $profile['damage_reduction'], 0.0001);
        $this->assertEqualsWithDelta(0.08, $profile['initiative_bonus'], 0.0001);
        $this->assertCount(2, $profile['structure_ids']);
    }

    public function testBrokenTowerGivesNoInitiative(): void
    {
        // Сломанная вышка (hp=0) не учитывается → null (нет других структур).
        $this->buildStructure(1, $this->towerId, 100, 0);
        $this->assertNull((new DefenseStructureService())->getDefenseProfile(1, 100));
    }

    // ---- ADR-041: per-level scaling эффекта + maxHp ----

    public function testWallReductionScalesWithLevel(): void
    {
        // L2 стена: levelMult=1.2 → 15% × 1.2 = round(18) = 18% → 0.18.
        $this->buildStructure(1, $this->wallId, 100, 200, 2);
        $profile = (new DefenseStructureService())->getDefenseProfile(1, 100);
        $this->assertNotNull($profile);
        $this->assertEqualsWithDelta(0.18, $profile['damage_reduction'], 0.0001);
    }

    public function testFenceDamageScalesWithLevel(): void
    {
        // L3 ограда: levelMult=1.4 → 3 × 1.4 = round(4.2) = 4.
        $this->buildStructure(1, $this->fenceId, 100, 80, 3);
        $profile = (new DefenseStructureService())->getDefenseProfile(1, 100);
        $this->assertNotNull($profile);
        $this->assertSame(4, $profile['fence_damage']);
    }

    public function testTowerInitiativeScalesWithLevel(): void
    {
        // L2 вышка: levelMult=1.2 → 8% × 1.2 = round(9.6) = 10% → 0.10.
        $this->buildStructure(1, $this->towerId, 100, 300, 2);
        $profile = (new DefenseStructureService())->getDefenseProfile(1, 100);
        $this->assertNotNull($profile);
        $this->assertEqualsWithDelta(0.10, $profile['initiative_bonus'], 0.0001);
    }

    public function testLevelOneIsNoOp(): void
    {
        // L1 (явно) — множитель ×1.0, базовые значения сохраняются.
        $this->buildStructure(1, $this->wallId, 100, 200, 1);
        $profile = (new DefenseStructureService())->getDefenseProfile(1, 100);
        $this->assertNotNull($profile);
        $this->assertEqualsWithDelta(0.15, $profile['damage_reduction'], 0.0001);
    }

    public function testCapStillAppliesAfterScaling(): void
    {
        // 5 стен L10 (levelMult=2.8) → 15×2.8=42% каждая, сумма огромна → клампится в 40%.
        for ($i = 0; $i < 5; $i++) {
            $this->buildStructure(1, $this->wallId, 100, 200, 10);
        }
        $profile = (new DefenseStructureService())->getDefenseProfile(1, 100);
        $this->assertNotNull($profile);
        $this->assertEqualsWithDelta(0.40, $profile['damage_reduction'], 0.0001);
    }

    public function testMaxHpForScalesWithLevel(): void
    {
        $svc = new DefenseStructureService();
        $this->assertSame(200, $svc->maxHpFor(200, 1));   // L1 = база (no-op)
        $this->assertSame(240, $svc->maxHpFor(200, 2));   // ×1.2
        $this->assertSame(280, $svc->maxHpFor(200, 3));   // ×1.4
        $this->assertSame(96, $svc->maxHpFor(80, 2));     // ограда ×1.2
    }
}
