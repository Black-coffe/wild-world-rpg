<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Player\FactionProjectService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * V20 (ADR-051) — FactionProjectService: readers, factionIdFor (нейтрал/нет → 0),
 * craftTimeMultiplierFor (активный/истёкший buff, killswitch), getOrCreateProject.
 * deposit() (транзакция) покрыт testbot e2e.
 *
 * @internal
 */
final class FactionProjectServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();
        $db = Database::connect('tests');
        $db->query('DROP TABLE IF EXISTS game_settings');
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
        $db->query('DROP TABLE IF EXISTS character_factions');
        $db->query('
            CREATE TABLE character_factions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                character_id INT NOT NULL,
                faction_id INT NOT NULL,
                joined_at DATETIME NULL
            )
        ');
        $db->query('DROP TABLE IF EXISTS faction_projects');
        $db->query('
            CREATE TABLE faction_projects (
                id INT AUTO_INCREMENT PRIMARY KEY,
                faction_id INT NOT NULL,
                progress INT NOT NULL DEFAULT 0,
                buff_until DATETIME NULL,
                cycles_completed INT NOT NULL DEFAULT 0,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        $db->query('DROP TABLE IF EXISTS game_settings');
        $db->query('DROP TABLE IF EXISTS character_factions');
        $db->query('DROP TABLE IF EXISTS faction_projects');
        $this->cleanCache();
        parent::tearDown();
    }

    private function seedBool(string $key, int $bool): void
    {
        Database::connect('tests')->table('game_settings')->insert([
            'setting_key' => $key, 'category' => 'endgame', 'value_type' => 'bool', 'value_bool' => $bool,
        ]);
        $this->cleanCache();
    }

    private function seedInt(string $key, int $int): void
    {
        Database::connect('tests')->table('game_settings')->insert([
            'setting_key' => $key, 'category' => 'endgame', 'value_type' => 'int', 'value_int' => $int,
        ]);
        $this->cleanCache();
    }

    private function seedFloat(string $key, float $float): void
    {
        Database::connect('tests')->table('game_settings')->insert([
            'setting_key' => $key, 'category' => 'endgame', 'value_type' => 'float', 'value_float' => $float,
        ]);
        $this->cleanCache();
    }

    private function seedMember(int $charId, int $factionId): void
    {
        Database::connect('tests')->table('character_factions')->insert([
            'character_id' => $charId, 'faction_id' => $factionId, 'joined_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function seedProjectBuff(int $factionId, ?string $buffUntil): void
    {
        Database::connect('tests')->table('faction_projects')->insert([
            'faction_id' => $factionId, 'progress' => 0, 'buff_until' => $buffUntil, 'cycles_completed' => 0,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
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

    // ── readers ──────────────────────────────────────────────────────────────

    public function testReaderDefaults(): void
    {
        $svc = new FactionProjectService();
        $this->assertTrue($svc->enabled());
        $this->assertSame(20000, $svc->threshold());
        $this->assertSame(1000, $svc->depositGold());
        $this->assertSame(24, $svc->buffDurationHours());
        $this->assertEqualsWithDelta(0.90, $svc->craftTimeMultiplier(), 1e-9);
    }

    public function testReaderTuned(): void
    {
        $this->seedInt('faction.project.threshold', 5000);
        $this->seedFloat('faction.project.craft_time_multiplier', 0.80);
        $svc = new FactionProjectService();
        $this->assertSame(5000, $svc->threshold());
        $this->assertEqualsWithDelta(0.80, $svc->craftTimeMultiplier(), 1e-9);
    }

    // ── factionIdFor ─────────────────────────────────────────────────────────

    public function testFactionIdForPlayableNeutralAndNone(): void
    {
        $this->seedMember(10, 3); // Инженеры
        $this->seedMember(11, 5); // Нейтрал
        $svc = new FactionProjectService();
        $this->assertSame(3, $svc->factionIdFor(10));
        $this->assertSame(0, $svc->factionIdFor(11), 'нейтрал (5) → 0');
        $this->assertSame(0, $svc->factionIdFor(12), 'нет записи → 0');
    }

    // ── craftTimeMultiplierFor ───────────────────────────────────────────────

    public function testCraftMultiplierActiveBuff(): void
    {
        $this->seedMember(10, 3);
        $this->seedProjectBuff(3, date('Y-m-d H:i:s', time() + 3600)); // +1h
        $this->assertEqualsWithDelta(0.90, (new FactionProjectService())->craftTimeMultiplierFor(10), 1e-9);
    }

    public function testCraftMultiplierExpiredBuff(): void
    {
        $this->seedMember(10, 3);
        $this->seedProjectBuff(3, date('Y-m-d H:i:s', time() - 3600)); // −1h
        $this->assertSame(1.0, (new FactionProjectService())->craftTimeMultiplierFor(10));
    }

    public function testCraftMultiplierNoFactionOrNoBuff(): void
    {
        $this->seedMember(12, 5); // нейтрал
        $svc = new FactionProjectService();
        $this->assertSame(1.0, $svc->craftTimeMultiplierFor(12), 'нейтрал → 1.0');
        $this->assertSame(1.0, $svc->craftTimeMultiplierFor(99), 'нет фракции → 1.0');
        // член фракции без проекта/баффа → 1.0
        $this->seedMember(13, 2);
        $this->assertSame(1.0, $svc->craftTimeMultiplierFor(13), 'нет проекта → 1.0');
    }

    public function testKillswitchForcesNoMultiplier(): void
    {
        $this->seedBool('faction.project.enabled', 0);
        $this->seedMember(10, 3);
        $this->seedProjectBuff(3, date('Y-m-d H:i:s', time() + 3600));
        $this->assertFalse((new FactionProjectService())->enabled());
        $this->assertSame(1.0, (new FactionProjectService())->craftTimeMultiplierFor(10), 'killswitch off → 1.0 даже при активном buff');
    }

    // ── getOrCreateProject ───────────────────────────────────────────────────

    public function testGetOrCreateProjectIdempotent(): void
    {
        $svc = new FactionProjectService();
        $p1  = $svc->getOrCreateProject(1);
        $this->assertSame(1, (int) $p1['faction_id']);
        $p2 = $svc->getOrCreateProject(1);
        $this->assertSame((int) $p1['id'], (int) $p2['id'], 'повторный вызов не создаёт дубль');
        $count = Database::connect('tests')->table('faction_projects')->where('faction_id', 1)->countAllResults();
        $this->assertSame(1, $count);
    }
}
