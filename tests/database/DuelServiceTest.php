<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\PVE\DuelService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * W17 (ADR-071) — DuelService: killswitch + baseline + stat-equalize.
 * GameSettings из game_settings (как CaravanServiceTest).
 *
 * @internal
 */
final class DuelServiceTest extends CIUnitTestCase
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
    }

    protected function tearDown(): void
    {
        Database::connect('tests')->query('DROP TABLE IF EXISTS game_settings');
        $this->cleanCache();
        parent::tearDown();
    }

    private function seedBool(string $key, int $bool): void
    {
        Database::connect('tests')->table('game_settings')->insert([
            'setting_key' => $key, 'category' => 'combat', 'value_type' => 'bool', 'value_bool' => $bool,
        ]);
        $this->cleanCache();
    }

    private function seedInt(string $key, int $int): void
    {
        Database::connect('tests')->table('game_settings')->insert([
            'setting_key' => $key, 'category' => 'combat', 'value_type' => 'int', 'value_int' => $int,
        ]);
        $this->cleanCache();
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

    public function testDefaultsDormant(): void
    {
        $svc = new DuelService();
        $this->assertFalse($svc->enabled()); // default OFF
        $this->assertSame(20, $svc->baselineLevel());
        $this->assertSame(50, $svc->baselineStat());
        $this->assertSame(1000, $svc->baselineHealth());
    }

    public function testKillswitchOn(): void
    {
        $this->seedBool('pvp.duel.enabled', 1);
        $this->assertTrue((new DuelService())->enabled());
    }

    public function testBaselineTuned(): void
    {
        $this->seedInt('pvp.duel.baseline_level', 30);
        $this->seedInt('pvp.duel.baseline_stat', 80);
        $this->seedInt('pvp.duel.baseline_health', 2000);
        $svc = new DuelService();
        $this->assertSame(30, $svc->baselineLevel());
        $this->assertSame(80, $svc->baselineStat());
        $this->assertSame(2000, $svc->baselineHealth());
    }

    public function testEqualizeNormalizesStatsKeepsIdentityAndGear(): void
    {
        $svc = new DuelService();
        $char = [
            'id' => 42, 'name' => 'Veteran', 'cell_number' => 801916,
            'level' => 300, 'strength' => 9999, 'agility' => 5000, 'intellect' => 7000,
            'health' => 50000, 'max_health' => 50000, 'tired' => 5,
            'weapon_id' => 7, // снаряжение/прочие поля — не трогаются
        ];
        $eq = $svc->equalize($char);

        // Статы нормализованы к baseline.
        $this->assertSame(20, $eq['level']);
        $this->assertSame(50, $eq['strength']);
        $this->assertSame(50, $eq['agility']);
        $this->assertSame(50, $eq['intellect']);
        $this->assertSame(1000, $eq['health']);
        $this->assertSame(1000, $eq['max_health']);
        $this->assertGreaterThanOrEqual(30, $eq['tired']); // без tired-штрафа
        // Идентичность + «гир-поля» сохранены.
        $this->assertSame(42, $eq['id']);
        $this->assertSame('Veteran', $eq['name']);
        $this->assertSame(801916, $eq['cell_number']);
        $this->assertSame(7, $eq['weapon_id']);
    }

    public function testEqualizeMakesTwoFightersEqualOnStats(): void
    {
        $svc = new DuelService();
        $strong = $svc->equalize(['id' => 1, 'level' => 300, 'strength' => 9999, 'agility' => 9999, 'intellect' => 9999, 'health' => 99999]);
        $weak   = $svc->equalize(['id' => 2, 'level' => 1,   'strength' => 1,    'agility' => 1,    'intellect' => 1,    'health' => 100]);
        foreach (['level', 'strength', 'agility', 'intellect', 'health', 'max_health'] as $k) {
            $this->assertSame($strong[$k], $weak[$k], "Поле {$k} должно быть равным после equalize");
        }
    }

    // ---- W18.5 (ADR-073): resolveDuel — детерминированный исход, без ничьи ----

    /** @param array<int,array<string,mixed>> $logs */
    private function exhausted(array $logs): array
    {
        return ['type' => 'exhausted', 'rounds' => 150, 'winner' => null, 'loser' => null, 'roundLogs' => $logs];
    }

    public function testResolveKnockout(): void
    {
        $svc    = new DuelService();
        $result = ['type' => 'normal', 'rounds' => 7, 'winner' => ['id' => 1, 'name' => 'A'], 'loser' => ['id' => 2, 'name' => 'B'], 'roundLogs' => []];
        $r      = $svc->resolveDuel($result, ['id' => 1, 'name' => 'A'], ['id' => 2, 'name' => 'B']);
        $this->assertSame(1, $r['winnerId']);
        $this->assertSame(2, $r['loserId']);
        $this->assertSame('knockout', $r['reason']);
    }

    public function testResolveByRemainingHp(): void
    {
        // A получил 50, B получил 100 → A «здоровее» → побеждает A.
        $svc  = new DuelService();
        $logs = [
            ['attacker' => 'A', 'defender' => 'B', 'finalDamage' => 100, 'D_equip' => 10],
            ['attacker' => 'B', 'defender' => 'A', 'finalDamage' => 50,  'D_equip' => 10],
        ];
        $r = $svc->resolveDuel($this->exhausted($logs), ['id' => 1, 'name' => 'A'], ['id' => 2, 'name' => 'B']);
        $this->assertSame(1, $r['winnerId']);
        $this->assertSame('hp', $r['reason']);
    }

    public function testResolveByRemainingHpOtherSide(): void
    {
        // B получил меньше → побеждает B.
        $svc  = new DuelService();
        $logs = [
            ['attacker' => 'A', 'defender' => 'B', 'finalDamage' => 30,  'D_equip' => 10],
            ['attacker' => 'B', 'defender' => 'A', 'finalDamage' => 120, 'D_equip' => 10],
        ];
        $r = $svc->resolveDuel($this->exhausted($logs), ['id' => 1, 'name' => 'A'], ['id' => 2, 'name' => 'B']);
        $this->assertSame(2, $r['winnerId']);
        $this->assertSame('hp', $r['reason']);
    }

    public function testResolveByBuildWhenHpEqual(): void
    {
        // Равный полученный урон (по 50) → решает D_equip: у A 20 > у B 10 → A.
        $svc  = new DuelService();
        $logs = [
            ['attacker' => 'A', 'defender' => 'B', 'finalDamage' => 50, 'D_equip' => 20],
            ['attacker' => 'B', 'defender' => 'A', 'finalDamage' => 50, 'D_equip' => 10],
        ];
        $r = $svc->resolveDuel($this->exhausted($logs), ['id' => 1, 'name' => 'A'], ['id' => 2, 'name' => 'B']);
        $this->assertSame(1, $r['winnerId']);
        $this->assertSame('build', $r['reason']);
    }

    public function testResolveBySeniorityCreatedAt(): void
    {
        // Равны HP и билд → раньше created_at побеждает (A 2025 < B 2026).
        $svc  = new DuelService();
        $logs = [
            ['attacker' => 'A', 'defender' => 'B', 'finalDamage' => 50, 'D_equip' => 10],
            ['attacker' => 'B', 'defender' => 'A', 'finalDamage' => 50, 'D_equip' => 10],
        ];
        $r = $svc->resolveDuel(
            $this->exhausted($logs),
            ['id' => 7, 'name' => 'A', 'created_at' => '2025-04-21 14:16:20'],
            ['id' => 3, 'name' => 'B', 'created_at' => '2026-05-11 12:21:42']
        );
        $this->assertSame(7, $r['winnerId']); // старше по дате, несмотря на больший id
        $this->assertSame('seniority', $r['reason']);
    }

    public function testResolveBySeniorityIdWhenNoDate(): void
    {
        // Всё равно + created_at отсутствует → меньше id побеждает.
        $svc  = new DuelService();
        $logs = [
            ['attacker' => 'A', 'defender' => 'B', 'finalDamage' => 50, 'D_equip' => 10],
            ['attacker' => 'B', 'defender' => 'A', 'finalDamage' => 50, 'D_equip' => 10],
        ];
        $r = $svc->resolveDuel($this->exhausted($logs), ['id' => 2, 'name' => 'A'], ['id' => 5, 'name' => 'B']);
        $this->assertSame(2, $r['winnerId']);
        $this->assertSame('seniority', $r['reason']);
        // Ничья невозможна — всегда есть победитель.
        $this->assertNotSame($r['winnerId'], $r['loserId']);
    }
}
