<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\PVE\PvpLadderService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * W18 (ADR-072) — PvpLadderService: killswitch + post-combat scoring + standings + weekly reset.
 * GameSettings/таблицы из tests-группы (как DuelServiceTest/CaravanServiceTest).
 *
 * @internal
 */
final class PvpLadderServiceTest extends CIUnitTestCase
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
        $db->query('DROP TABLE IF EXISTS pvp_ladder');
        $db->query('
            CREATE TABLE pvp_ladder (
                id INT AUTO_INCREMENT PRIMARY KEY,
                character_id INT NOT NULL UNIQUE,
                faction_id INT NULL,
                duel_wins INT NOT NULL DEFAULT 0,
                duel_losses INT NOT NULL DEFAULT 0,
                duel_draws INT NOT NULL DEFAULT 0,
                pvp_wins INT NOT NULL DEFAULT 0,
                pvp_losses INT NOT NULL DEFAULT 0,
                points INT NOT NULL DEFAULT 0,
                week_points INT NOT NULL DEFAULT 0,
                week_wins INT NOT NULL DEFAULT 0,
                updated_at DATETIME NULL
            )
        ');
        $db->query('DROP TABLE IF EXISTS characters');
        $db->query('CREATE TABLE characters (id INT PRIMARY KEY, name VARCHAR(100) NULL)');
        $db->query('DROP TABLE IF EXISTS character_factions');
        $db->query('CREATE TABLE character_factions (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NOT NULL, faction_id INT NOT NULL)');

        // Базовые персонажи + фракции (для join по имени и снапшота faction_id).
        foreach ([[1, 'Alice'], [2, 'Bob'], [3, 'Carol']] as [$id, $name]) {
            $db->table('characters')->insert(['id' => $id, 'name' => $name]);
        }
        $db->table('character_factions')->insert(['character_id' => 1, 'faction_id' => 1]);
        $db->table('character_factions')->insert(['character_id' => 2, 'faction_id' => 2]);
        // char 3 — без фракции (нейтрал → faction_id 0).
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        $db->query('DROP TABLE IF EXISTS game_settings');
        $db->query('DROP TABLE IF EXISTS pvp_ladder');
        $db->query('DROP TABLE IF EXISTS characters');
        $db->query('DROP TABLE IF EXISTS character_factions');
        $this->cleanCache();
        parent::tearDown();
    }

    private function enable(): void
    {
        Database::connect('tests')->table('game_settings')->insert([
            'setting_key' => 'pvp.ladder.enabled', 'category' => 'combat', 'value_type' => 'bool', 'value_bool' => 1,
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

    private function row(int $charId): ?array
    {
        $r = Database::connect('tests')->table('pvp_ladder')->where('character_id', $charId)->get();
        $a = $r === false ? null : $r->getRowArray();
        return is_array($a) ? $a : null;
    }

    public function testDefaultsDormant(): void
    {
        $svc = new PvpLadderService();
        $this->assertFalse($svc->enabled());
        $this->assertFalse($svc->broadcastEnabled());
        $this->assertSame(3, $svc->pointsDuelWin());
        $this->assertSame(1, $svc->pointsDuelDraw());
        $this->assertSame(5, $svc->pointsPvpWin());
    }

    public function testRecordDuelNoOpWhenDormant(): void
    {
        $svc = new PvpLadderService();
        $svc->recordDuel(1, 2, false);
        $this->assertNull($this->row(1), 'При dormant ничего не пишется');
        $this->assertNull($this->row(2));
    }

    public function testRecordDuelWinAndLoss(): void
    {
        $this->enable();
        $svc = new PvpLadderService();
        $svc->recordDuel(1, 2, false);

        $winner = $this->row(1);
        $this->assertNotNull($winner);
        $this->assertSame(1, (int) $winner['duel_wins']);
        $this->assertSame(3, (int) $winner['points']);
        $this->assertSame(3, (int) $winner['week_points']);
        $this->assertSame(1, (int) $winner['week_wins']);
        $this->assertSame(1, (int) $winner['faction_id'], 'faction_id снапшот char1');

        $loser = $this->row(2);
        $this->assertNotNull($loser);
        $this->assertSame(1, (int) $loser['duel_losses']);
        $this->assertSame(0, (int) $loser['points']);
    }

    public function testRecordDuelDrawAwardsBoth(): void
    {
        $this->enable();
        $svc = new PvpLadderService();
        $svc->recordDuel(1, 2, true);

        foreach ([1, 2] as $id) {
            $r = $this->row($id);
            $this->assertNotNull($r);
            $this->assertSame(1, (int) $r['duel_draws']);
            $this->assertSame(1, (int) $r['points'], 'ничья = points_duel_draw');
            $this->assertSame(0, (int) $r['duel_wins']);
        }
    }

    public function testRecordPvpAttackWorthMore(): void
    {
        $this->enable();
        $svc = new PvpLadderService();
        $svc->recordPvpAttack(1, 2);

        $w = $this->row(1);
        $this->assertSame(1, (int) $w['pvp_wins']);
        $this->assertSame(5, (int) $w['points']);
        $l = $this->row(2);
        $this->assertSame(1, (int) $l['pvp_losses']);
    }

    public function testCumulativeAcrossMatches(): void
    {
        $this->enable();
        $svc = new PvpLadderService();
        $svc->recordDuel(1, 2, false); // +3
        $svc->recordDuel(1, 3, false); // +3
        $svc->recordPvpAttack(1, 2);   // +5
        $this->assertSame(11, (int) $this->row(1)['points']);
        $this->assertSame(2, (int) $this->row(1)['duel_wins']);
        $this->assertSame(1, (int) $this->row(1)['pvp_wins']);
    }

    public function testTopGlobalOrdering(): void
    {
        $this->enable();
        $svc = new PvpLadderService();
        $svc->recordPvpAttack(2, 1); // Bob +5
        $svc->recordDuel(1, 3, false); // Alice +3
        $top = $svc->topGlobal(5);
        $this->assertCount(2, $top);
        $this->assertSame('Bob', $top[0]['name']);
        $this->assertSame('Alice', $top[1]['name']);
    }

    public function testTopByFactionFilters(): void
    {
        $this->enable();
        $svc = new PvpLadderService();
        $svc->recordDuel(1, 2, false); // Alice faction 1 +3
        $svc->recordPvpAttack(2, 1);   // Bob faction 2 +5
        $f1 = $svc->topByFaction(1, 5);
        $this->assertCount(1, $f1);
        $this->assertSame('Alice', $f1[0]['name']);
        $f2 = $svc->topByFaction(2, 5);
        $this->assertCount(1, $f2);
        $this->assertSame('Bob', $f2[0]['name']);
    }

    public function testWeeklyTopAndReset(): void
    {
        $this->enable();
        $svc = new PvpLadderService();
        $svc->recordDuel(1, 2, false);
        $week = $svc->weeklyTop(5);
        $this->assertCount(1, $week);
        $this->assertSame(3, (int) $week[0]['week_points']);

        $svc->resetWeek();
        $this->assertCount(0, $svc->weeklyTop(5), 'после resetWeek недельный топ пуст');
        // all-time points сохраняются.
        $this->assertSame(3, (int) $this->row(1)['points']);
    }

    public function testRankOf(): void
    {
        $this->enable();
        $svc = new PvpLadderService();
        $svc->recordPvpAttack(2, 1); // Bob 5
        $svc->recordDuel(1, 3, false); // Alice 3
        $this->assertSame(1, $svc->rankOf(2)); // Bob top
        $this->assertSame(2, $svc->rankOf(1)); // Alice second
        $this->assertSame(0, $svc->rankOf(3)); // Carol не в рейтинге
    }

    public function testPointsTunable(): void
    {
        $this->enable();
        $this->seedInt('pvp.ladder.points_duel_win', 10);
        $svc = new PvpLadderService();
        $this->assertSame(10, $svc->pointsDuelWin());
        $svc->recordDuel(1, 2, false);
        $this->assertSame(10, (int) $this->row(1)['points']);
    }
}
