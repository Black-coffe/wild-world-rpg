<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\PVE\BossLootService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * WB8 (ADR-137 «Узлы») — BossLootService: ledger вклада «Облавы» (атомарный апсёрт) + дележ лута
 * по вкладу (золото), порог участия, фолбэк топ-вкладчика, clamp пула, GC протухших строк.
 *
 * Детерминированно (золото — арифметика, без mt_rand).
 *
 * @internal
 */
final class BossLootServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = ['game_settings', 'boss_engagements', 'characters'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('CREATE TABLE game_settings (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(191), value_type VARCHAR(16) NULL, value_int INT NULL, value_bool TINYINT NULL, value_float DOUBLE NULL, value_string TEXT NULL)');
        $db->query('CREATE TABLE boss_engagements (id INT AUTO_INCREMENT PRIMARY KEY, boss_point_id INT, boss_level INT, character_id INT, damage_dealt INT DEFAULT 0, rounds_participated INT DEFAULT 0, first_hit_at DATETIME NULL, last_hit_at DATETIME NULL, UNIQUE KEY uniq_contrib (boss_point_id, boss_level, character_id))');
        $db->query('CREATE TABLE characters (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100), gold INT DEFAULT 0)');
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

    private function setInt(string $key, int $v): void
    {
        Database::connect('tests')->table('game_settings')->insert(['setting_key' => $key, 'value_type' => 'int', 'value_int' => $v]);
        $this->cleanCache();
    }

    private function setFloat(string $key, float $v): void
    {
        Database::connect('tests')->table('game_settings')->insert(['setting_key' => $key, 'value_type' => 'float', 'value_float' => $v]);
        $this->cleanCache();
    }

    private function char(int $gold = 0): int
    {
        $db = Database::connect('tests');
        $db->table('characters')->insert(['name' => 'Hero', 'gold' => $gold]);

        return (int) $db->insertID();
    }

    private function gold(int $charId): int
    {
        return (int) Database::connect('tests')->table('characters')->where('id', $charId)->get()->getRowArray()['gold'];
    }

    private function engRows(): array
    {
        return Database::connect('tests')->table('boss_engagements')->get()->getResultArray();
    }

    /** @param array<string,mixed> $over @return array<string,mixed> */
    private function point(array $over = []): array
    {
        return array_merge(['id' => 1, 'current_level' => 10, 'max_health' => 1000], $over);
    }

    // ----------------------------------------------------------------

    public function testRecordContributionUpsertsAndSums(): void
    {
        $svc = new BossLootService();
        $svc->recordContribution(1, 10, 7, 30);
        $svc->recordContribution(1, 10, 7, 20);

        $rows = $this->engRows();
        $this->assertCount(1, $rows, 'один и тот же (point,level,char) = одна строка (апсёрт)');
        $this->assertSame(50, (int) $rows[0]['damage_dealt'], 'урон суммирован атомарно (30+20)');
        $this->assertSame(2, (int) $rows[0]['rounds_participated'], 'раунды инкрементированы');
        $this->assertNotNull($rows[0]['first_hit_at']);
    }

    public function testRecordContributionSeparatesByLevel(): void
    {
        $svc = new BossLootService();
        $svc->recordContribution(1, 10, 7, 30); // жизнь L10
        $svc->recordContribution(1, 11, 7, 40); // следующая жизнь L11 (после респавна)

        $this->assertCount(2, $this->engRows(), 'разный boss_level → разные строки, не коллизят');
    }

    public function testRecordContributionIgnoresNonPositive(): void
    {
        $svc = new BossLootService();
        $svc->recordContribution(1, 10, 7, 0);
        $svc->recordContribution(0, 10, 7, 50);
        $svc->recordContribution(1, 10, 0, 50);

        $this->assertCount(0, $this->engRows(), 'невалидный/нулевой урон не пишется');
    }

    public function testDistributeProportionalGold(): void
    {
        $this->setInt('combat.nodes.gold_per_level', 100);
        $this->setInt('combat.nodes.gold_floor', 0);
        $this->setInt('combat.nodes.gold_hard_cap', 1000000);
        $this->setFloat('combat.nodes.loot_min_contribution_pct', 0.05);

        $a = $this->char();
        $b = $this->char();
        $svc = new BossLootService();
        $svc->recordContribution(1, 10, $a, 750); // 75% урона
        $svc->recordContribution(1, 10, $b, 250); // 25% урона

        // пул = 10 * 100 = 1000; делится 750/1000 и 250/1000
        $res = $svc->distributeLoot($this->point(['max_health' => 1000]), 10, $a);

        $this->assertSame(2, $res['participants']);
        $this->assertSame(1000, $res['totalDamage']);
        $this->assertSame(1000, $res['goldPool']);
        $this->assertSame(750, $this->gold($a), 'A получил 75% пула');
        $this->assertSame(250, $this->gold($b), 'B получил 25% пула');
        $this->assertSame(750, $res['killerGold']);
        $this->assertCount(0, $this->engRows(), 'ledger потреблён при килле');
    }

    public function testDistributeExcludesAfkPassenger(): void
    {
        $this->setInt('combat.nodes.gold_per_level', 100);
        $this->setInt('combat.nodes.gold_floor', 0);
        $this->setInt('combat.nodes.gold_hard_cap', 1000000);
        $this->setFloat('combat.nodes.loot_min_contribution_pct', 0.05); // ≥5% от max_health=1000 → ≥50

        $worker = $this->char();
        $afk    = $this->char();
        $svc    = new BossLootService();
        $svc->recordContribution(1, 10, $worker, 980);
        $svc->recordContribution(1, 10, $afk, 20); // 2% от 1000 < порог → пассажир

        $res = $svc->distributeLoot($this->point(['max_health' => 1000]), 10, $worker);

        $this->assertGreaterThan(0, $this->gold($worker), 'работяга получил золото');
        $this->assertSame(0, $this->gold($afk), 'AFK-пассажир (2%) исключён из дележа');
        $this->assertArrayNotHasKey($afk, $res['goldByChar']);
        $this->assertSame(2, $res['participants'], 'но в общем счёте участников он есть');
    }

    public function testDistributeTopContributorFallbackWhenAllBelowThreshold(): void
    {
        $this->setInt('combat.nodes.gold_per_level', 100);
        $this->setInt('combat.nodes.gold_floor', 0);
        $this->setInt('combat.nodes.gold_hard_cap', 1000000);
        $this->setFloat('combat.nodes.loot_min_contribution_pct', 0.5); // ≥50% от max_health=1000 → ≥500

        $a = $this->char();
        $b = $this->char();
        $svc = new BossLootService();
        $svc->recordContribution(1, 10, $a, 300); // 30%
        $svc->recordContribution(1, 10, $b, 200); // 20% — оба ниже 50%

        $res = $svc->distributeLoot($this->point(['max_health' => 1000]), 10, $a);

        $this->assertGreaterThan(0, $this->gold($a), 'фолбэк: топ-вкладчик получает пул');
        $this->assertSame(0, $this->gold($b));
        $this->assertArrayHasKey($a, $res['eligible']);
    }

    public function testGoldPoolClamp(): void
    {
        $this->setInt('combat.nodes.gold_per_level', 50);
        $this->setInt('combat.nodes.gold_floor', 100);
        $this->setInt('combat.nodes.gold_hard_cap', 5000);
        $svc = new BossLootService();

        $this->assertSame(100, $svc->goldPool(1), 'floor: 1*50=50 < 100 → 100');
        $this->assertSame(500, $svc->goldPool(10), '10*50=500 в коридоре');
        $this->assertSame(5000, $svc->goldPool(200), 'cap: 200*50=10000 > 5000 → 5000');
    }

    public function testPurgeStaleRemovesOnlyOld(): void
    {
        $this->setInt('combat.nodes.engagement_ttl_min', 60);
        $db = Database::connect('tests');
        // свежий
        $db->table('boss_engagements')->insert(['boss_point_id' => 1, 'boss_level' => 10, 'character_id' => 1, 'damage_dealt' => 50, 'last_hit_at' => date('Y-m-d H:i:s')]);
        // протухший (2ч назад)
        $db->table('boss_engagements')->insert(['boss_point_id' => 2, 'boss_level' => 10, 'character_id' => 1, 'damage_dealt' => 50, 'last_hit_at' => date('Y-m-d H:i:s', time() - 7200)]);

        $removed = (new BossLootService())->purgeStale();

        $this->assertSame(1, $removed, 'удалена одна протухшая строка');
        $rows = $this->engRows();
        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows[0]['boss_point_id'], 'свежая строка осталась');
    }

    public function testDistributeNoContributorsIsNoop(): void
    {
        $res = (new BossLootService())->distributeLoot($this->point(), 10, 1);
        $this->assertSame(0, $res['participants']);
        $this->assertSame([], $res['goldByChar']);
    }
}
