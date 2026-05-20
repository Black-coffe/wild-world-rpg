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
                hp INT NULL
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

        $settings = [
            ['defense.wall.damage_reduction_percent', 15],
            ['defense.fence.attacker_damage_per_round', 3],
            ['defense.total_damage_reduction_max_percent', 40],
            ['defense.decay_hp_per_attack', 10],
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

    private function buildStructure(int $charId, int $buildingId, int $cell, int $hp): int
    {
        $db = Database::connect('tests');
        $db->table('character_buildings')->insert([
            'character_id' => $charId, 'building_id' => $buildingId,
            'map_cell_id' => $cell, 'building_type' => 'defensive', 'hp' => $hp,
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
}
