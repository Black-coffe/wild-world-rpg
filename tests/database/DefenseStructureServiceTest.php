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
        $db->query('DROP TABLE IF EXISTS characters');

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
                level INT NULL DEFAULT 1,
                amount INT NULL DEFAULT 1
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
        // W5 (ADR-064) — characters table для combat_drone_active_until lazy-expiry check.
        $db->query('
            CREATE TABLE characters (
                id INT AUTO_INCREMENT PRIMARY KEY,
                combat_drone_active_until DATETIME NULL DEFAULT NULL
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
        $db->query('DROP TABLE IF EXISTS characters');
        $this->cleanCache();
        parent::tearDown();
    }

    /**
     * W5 (ADR-064): создать character row с (опциональным) активным combat-drone.
     */
    private function makeCharacter(int $charId, ?string $combatDroneActiveUntil = null): void
    {
        Database::connect('tests')->table('characters')->insert([
            'id' => $charId,
            'combat_drone_active_until' => $combatDroneActiveUntil,
        ]);
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

    private function buildStructure(int $charId, int $buildingId, int $cell, int $hp, int $level = 1, int $amount = 1): int
    {
        $db = Database::connect('tests');
        $db->table('character_buildings')->insert([
            'character_id' => $charId, 'building_id' => $buildingId,
            'map_cell_id' => $cell, 'building_type' => 'defensive', 'hp' => $hp, 'level' => $level, 'amount' => $amount,
        ]);
        return (int) $db->insertID();
    }

    /**
     * ADR-102 Ф3: включить стак обороны (killswitch + параметры) в game_settings.
     */
    private function enableStack(float $ratio = 0.6, float $max = 2.5): void
    {
        $db = Database::connect('tests');
        $db->table('game_settings')->insert([
            'setting_key' => 'defense.stack.enabled', 'category' => 'combat',
            'value_type' => 'bool', 'value_bool' => 1,
        ]);
        $db->table('game_settings')->insert([
            'setting_key' => 'defense.stack.diminish_ratio', 'category' => 'combat',
            'value_type' => 'float', 'value_float' => $ratio,
        ]);
        $db->table('game_settings')->insert([
            'setting_key' => 'defense.stack.max_factor', 'category' => 'combat',
            'value_type' => 'float', 'value_float' => $max,
        ]);
        $this->cleanCache();
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

    // ---- W5 (ADR-064): Combat drone integration ----

    public function testCombatDroneAloneGivesInitiativeWithoutStructures(): void
    {
        // Только combat-drone (active_until > NOW), без зданий → профиль не null,
        // damage_reduction=0, fence_damage=0, initiative_bonus = 12% (default).
        $this->makeCharacter(1, date('Y-m-d H:i:s', time() + 1800)); // +30 мин

        $profile = (new DefenseStructureService())->getDefenseProfile(1, 100);

        $this->assertNotNull($profile);
        $this->assertSame(1, $profile['owner_id']);
        $this->assertEqualsWithDelta(0.0, $profile['damage_reduction'], 0.0001);
        $this->assertSame(0, $profile['fence_damage']);
        $this->assertEqualsWithDelta(0.12, $profile['initiative_bonus'], 0.0001);
        $this->assertCount(0, $profile['structure_ids']);
    }

    public function testCombatDroneExpiredGivesNoBonus(): void
    {
        // active_until в прошлом → бонус не применяется → нет структур → null.
        $this->makeCharacter(1, date('Y-m-d H:i:s', time() - 3600));
        $this->assertNull((new DefenseStructureService())->getDefenseProfile(1, 100));
    }

    public function testCombatDroneNullActiveUntilGivesNoBonus(): void
    {
        // NULL active_until → нет бонуса → нет структур → null.
        $this->makeCharacter(1, null);
        $this->assertNull((new DefenseStructureService())->getDefenseProfile(1, 100));
    }

    public function testCombatDroneStacksWithTowerUnderCap(): void
    {
        // Tower 8% + Combat 12% = 20% < cap 25% → ожидаем 0.20.
        $this->buildStructure(1, $this->towerId, 100, 300);
        $this->makeCharacter(1, date('Y-m-d H:i:s', time() + 1800));

        $profile = (new DefenseStructureService())->getDefenseProfile(1, 100);

        $this->assertNotNull($profile);
        $this->assertEqualsWithDelta(0.20, $profile['initiative_bonus'], 0.0001);
    }

    public function testCombatDroneCappedWhenSumOverflows(): void
    {
        // Tower bonus admin-tuned до 20%, combat до 20% → sum=40% → clamp 25% (default cap).
        $db = Database::connect('tests');
        $db->table('game_settings')->update(
            ['value_int' => 20],
            ['setting_key' => 'defense.tower.defender_initiative_bonus_percent']
        );
        // seed drone.combat.initiative_bonus_percent=20 (drone-side)
        $db->table('game_settings')->insert([
            'setting_key' => 'drone.combat.initiative_bonus_percent',
            'category' => 'combat', 'value_type' => 'int', 'value_int' => 20,
        ]);
        $this->cleanCache();

        $this->buildStructure(1, $this->towerId, 100, 300);
        $this->makeCharacter(1, date('Y-m-d H:i:s', time() + 1800));

        $profile = (new DefenseStructureService())->getDefenseProfile(1, 100);

        $this->assertNotNull($profile);
        // Cap default = 25 → 0.25.
        $this->assertEqualsWithDelta(0.25, $profile['initiative_bonus'], 0.0001);
    }

    // ---- ADR-102 Ф3: стак обороны по amount ----

    public function testDefenseStackFactorDormantByDefault(): void
    {
        // killswitch не засеян (OFF) → amount>1 всё равно ×1.0 (byte-identical).
        $svc = new DefenseStructureService();
        $this->assertEqualsWithDelta(1.0, $svc->defenseStackFactor(1), 0.0001);
        $this->assertEqualsWithDelta(1.0, $svc->defenseStackFactor(5), 0.0001);
    }

    public function testDefenseStackFactorGeometricWhenEnabled(): void
    {
        $this->enableStack(0.6, 2.5);
        $svc = new DefenseStructureService();
        $this->assertEqualsWithDelta(1.0, $svc->defenseStackFactor(1), 0.0001);  // amount=1 всегда 1.0
        $this->assertEqualsWithDelta(1.6, $svc->defenseStackFactor(2), 0.0001);
        $this->assertEqualsWithDelta(1.96, $svc->defenseStackFactor(3), 0.0001);
        $this->assertEqualsWithDelta(2.176, $svc->defenseStackFactor(4), 0.0001);
    }

    public function testDefenseStackFactorCappedByMax(): void
    {
        $this->enableStack(0.6, 2.0); // max=2.0
        $svc = new DefenseStructureService();
        $this->assertEqualsWithDelta(2.0, $svc->defenseStackFactor(50), 0.0001);
    }

    public function testStackWallReductionWithAmount(): void
    {
        // 1 строка стены amount=3, stack ON → 15% × factor(3)=1.96 = round(29.4)=29 → 0.29.
        $this->enableStack(0.6, 2.5);
        $this->buildStructure(1, $this->wallId, 100, 200, 1, 3);
        $profile = (new DefenseStructureService())->getDefenseProfile(1, 100);
        $this->assertNotNull($profile);
        $this->assertEqualsWithDelta(0.29, $profile['damage_reduction'], 0.0001);
    }

    public function testStackByteIdenticalWhenStackOff(): void
    {
        // amount=3, но killswitch OFF → 15% (amount игнорируется) — byte-identical.
        $this->buildStructure(1, $this->wallId, 100, 200, 1, 3);
        $profile = (new DefenseStructureService())->getDefenseProfile(1, 100);
        $this->assertNotNull($profile);
        $this->assertEqualsWithDelta(0.15, $profile['damage_reduction'], 0.0001);
    }

    public function testStackAmountOneIsNoOpWhenEnabled(): void
    {
        // stack ON, но amount=1 → factor 1.0 → 15% (byte-identical для одиночных).
        $this->enableStack(0.6, 2.5);
        $this->buildStructure(1, $this->wallId, 100, 200, 1, 1);
        $profile = (new DefenseStructureService())->getDefenseProfile(1, 100);
        $this->assertNotNull($profile);
        $this->assertEqualsWithDelta(0.15, $profile['damage_reduction'], 0.0001);
    }

    public function testStackFenceWithAmount(): void
    {
        // ограда amount=2 → 3 × factor(2)=1.6 = round(4.8)=5.
        $this->enableStack(0.6, 2.5);
        $this->buildStructure(1, $this->fenceId, 100, 80, 1, 2);
        $profile = (new DefenseStructureService())->getDefenseProfile(1, 100);
        $this->assertNotNull($profile);
        $this->assertSame(5, $profile['fence_damage']);
    }

    public function testStackTowerNotStackedByAmount(): void
    {
        // вышка amount=3, stack ON → инициатива всё равно 8% (presence, анти-эксплойт).
        $this->enableStack(0.6, 2.5);
        $this->buildStructure(1, $this->towerId, 100, 300, 1, 3);
        $profile = (new DefenseStructureService())->getDefenseProfile(1, 100);
        $this->assertNotNull($profile);
        $this->assertEqualsWithDelta(0.08, $profile['initiative_bonus'], 0.0001);
    }

    public function testStackWallStillCappedAtGlobalMax(): void
    {
        // amount=20 L1: factor→~2.5 → 15×2.5=37.5; + плюс ещё стен пушим за cap.
        $this->enableStack(0.6, 2.5);
        $this->buildStructure(1, $this->wallId, 100, 200, 1, 20);
        $this->buildStructure(1, $this->wallId, 100, 200, 1, 20); // 2 ряда → >40 → клампится
        $profile = (new DefenseStructureService())->getDefenseProfile(1, 100);
        $this->assertNotNull($profile);
        $this->assertEqualsWithDelta(0.40, $profile['damage_reduction'], 0.0001);
    }

    public function testCombatDroneKillswitchOffSkipsDrone(): void
    {
        // drone.combat.enabled=0 → drone-bonus игнорируется, остаются только структуры.
        Database::connect('tests')->table('game_settings')->insert([
            'setting_key' => 'drone.combat.enabled',
            'category' => 'combat', 'value_type' => 'bool', 'value_bool' => 0,
        ]);
        $this->cleanCache();

        $this->buildStructure(1, $this->towerId, 100, 300);
        $this->makeCharacter(1, date('Y-m-d H:i:s', time() + 1800));

        $profile = (new DefenseStructureService())->getDefenseProfile(1, 100);

        $this->assertNotNull($profile);
        // Tower-only (combat-bonus заморожен): 8%.
        $this->assertEqualsWithDelta(0.08, $profile['initiative_bonus'], 0.0001);
    }
}
