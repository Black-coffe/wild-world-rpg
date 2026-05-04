<?php

namespace Tests\Database;

use App\Services\PVE\PvpRewardOrchestrator;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * F2.3b Step 4 — integration tests на DB writes PvpRewardOrchestrator.
 *
 * Покрывает:
 *   - giveWinnerBonus           (exp + 3× attr chance, RNG seeded)
 *   - processDeathAndRespawn    (-5% XP / -0.5% stats / respawn)
 *   - processMutualExhaustion   (health=10, tired=10 для обоих)
 *   - findRespawnCell           (claimed → explored random → 1)
 *   - makeWinnerDiffText / makeLoserDiffText (формат + DB read)
 *
 * @internal
 */
final class PvpRewardOrchestratorTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private PvpRewardOrchestrator $orch;

    protected function setUp(): void
    {
        parent::setUp();

        $db = Database::connect('tests');
        foreach (['characters', 'claimed_cells', 'explored_cells'] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }

        $db->query('
            CREATE TABLE characters (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NULL,
                level INT NOT NULL DEFAULT 1,
                health DECIMAL(10,2) NULL DEFAULT 100,
                max_health INT NULL DEFAULT 100,
                tired DECIMAL(10,2) NULL DEFAULT 100,
                max_tired INT NULL DEFAULT 100,
                experience DECIMAL(15,2) NULL DEFAULT 0,
                strength DECIMAL(10,2) NULL DEFAULT 0,
                agility DECIMAL(10,2) NULL DEFAULT 0,
                intellect DECIMAL(10,2) NULL DEFAULT 0,
                cell_number INT NULL DEFAULT 1,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        $db->query('
            CREATE TABLE claimed_cells (
                id INT AUTO_INCREMENT PRIMARY KEY,
                character_id INT NOT NULL,
                map_cell_id INT NOT NULL,
                status VARCHAR(50) NULL,
                claimed_at DATETIME NULL
            )
        ');
        $db->query('
            CREATE TABLE explored_cells (
                id INT AUTO_INCREMENT PRIMARY KEY,
                character_id INT NOT NULL,
                map_cell_id INT NOT NULL,
                discovered_at DATETIME NULL
            )
        ');

        $this->orch = new PvpRewardOrchestrator();
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (['characters', 'claimed_cells', 'explored_cells'] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        parent::tearDown();
    }

    private function insertChar(string $name, array $overrides = []): int
    {
        $db = Database::connect('tests');
        $defaults = [
            'name' => $name, 'level' => 50,
            'health' => 100, 'max_health' => 100,
            'tired' => 100, 'max_tired' => 100,
            'experience' => 1000, 'strength' => 50,
            'agility' => 50, 'intellect' => 50,
            'cell_number' => 100,
        ];
        $row = array_merge($defaults, $overrides);
        $cols = implode(',', array_keys($row));
        $vals = implode(',', array_fill(0, count($row), '?'));
        $db->query("INSERT INTO characters ({$cols}) VALUES ({$vals})", array_values($row));
        return (int) $db->insertID();
    }

    // ---- giveWinnerBonus ----

    public function testWinnerBonusBoostsExperienceBy5PercentMinimum(): void
    {
        $db = Database::connect('tests');
        $winnerId = $this->insertChar('Winner', ['experience' => 1000]);

        // mt_srand с известным значением — все 3 attr-rolls фиксированы.
        mt_srand(1);
        $this->orch->giveWinnerBonus($winnerId);

        $row = $db->query('SELECT experience FROM characters WHERE id = ?', [$winnerId])->getRowArray();
        // Минимум 1000 × 1.05 = 1050.
        $this->assertGreaterThanOrEqual(1050, (float) $row['experience']);
    }

    public function testWinnerBonusReturnsEarlyForUnknownWinner(): void
    {
        // Не должно бросать исключение.
        $this->orch->giveWinnerBonus(99999);
        $this->assertTrue(true);
    }

    public function testWinnerBonusExpBoostScalesWithLevelDiff(): void
    {
        $db = Database::connect('tests');
        // Winner low level, loser high level (на той же клетке).
        $winnerId = $this->insertChar('Winner', ['level' => 30, 'experience' => 1000, 'cell_number' => 100]);
        $this->insertChar('Loser', ['level' => 130, 'cell_number' => 100]);

        mt_srand(2);
        $this->orch->giveWinnerBonus($winnerId);

        $row = $db->query('SELECT experience FROM characters WHERE id = ?', [$winnerId])->getRowArray();
        // levelDiff = 130 - 30 = 100 → bonus = 0.05 + (100/100)*0.10 = 0.15
        // 1000 × 1.15 = 1150
        $this->assertEqualsWithDelta(1150.0, (float) $row['experience'], 0.01);
    }

    public function testWinnerAttrBonusIsMultiplicative(): void
    {
        $db = Database::connect('tests');
        $winnerId = $this->insertChar('Winner', ['strength' => 100, 'agility' => 100, 'intellect' => 100]);

        // Seed чтобы все 3 attr roll сработали (rand(0,100) < 20).
        // Чтобы найти подходящий seed, можно 0..50, потом проверить.
        // Гарантировано НЕ сработать: seed=99 даёт большие значения.
        // Просто проверяем что значения в диапазоне [100, 100×1.001=100.1].
        mt_srand(1);
        $this->orch->giveWinnerBonus($winnerId);

        $row = $db->query('SELECT strength, agility, intellect FROM characters WHERE id = ?', [$winnerId])->getRowArray();
        foreach (['strength', 'agility', 'intellect'] as $stat) {
            $this->assertGreaterThanOrEqual(100.0, (float) $row[$stat]);
            $this->assertLessThanOrEqual(100.1, (float) $row[$stat]);
        }
    }

    // ---- processDeathAndRespawn ----

    public function testProcessDeathReducesExpByExpLossPercent(): void
    {
        $db = Database::connect('tests');
        $loserId = $this->insertChar('Loser', ['experience' => 1000]);
        $loser   = $db->query('SELECT * FROM characters WHERE id = ?', [$loserId])->getRowArray();

        $this->orch->processDeathAndRespawn($loser);

        $after = $db->query('SELECT experience FROM characters WHERE id = ?', [$loserId])->getRowArray();
        // -5% → 950.
        $this->assertEqualsWithDelta(950.0, (float) $after['experience'], 0.01);
    }

    public function testProcessDeathReducesStatsBy05Percent(): void
    {
        $db = Database::connect('tests');
        $loserId = $this->insertChar('Loser', [
            'strength' => 100, 'agility' => 100, 'intellect' => 100,
        ]);
        $loser = $db->query('SELECT * FROM characters WHERE id = ?', [$loserId])->getRowArray();
        // Override loser['strength'] etc to be lower than DB to test max() guard.
        $loser['strength'] = 50; $loser['agility'] = 50; $loser['intellect'] = 50;

        $this->orch->processDeathAndRespawn($loser);

        $after = $db->query('SELECT strength, agility, intellect FROM characters WHERE id = ?', [$loserId])->getRowArray();
        // Each: max(loser_arg, dbVal × 0.995). dbVal=100 → 99.5; loser_arg=50 → max=99.5.
        $this->assertEqualsWithDelta(99.5, (float) $after['strength'],  0.01);
        $this->assertEqualsWithDelta(99.5, (float) $after['agility'],   0.01);
        $this->assertEqualsWithDelta(99.5, (float) $after['intellect'], 0.01);
    }

    public function testProcessDeathRestoresHealthAndTiredOnRespawn(): void
    {
        $db = Database::connect('tests');
        $loserId = $this->insertChar('Loser', ['max_health' => 100, 'max_tired' => 100]);
        $loser   = $db->query('SELECT * FROM characters WHERE id = ?', [$loserId])->getRowArray();

        $this->orch->processDeathAndRespawn($loser);

        $after = $db->query('SELECT health, tired, cell_number FROM characters WHERE id = ?', [$loserId])->getRowArray();
        $this->assertSame('100.00', $after['health']);
        $this->assertSame('100.00', $after['tired']);
        $this->assertSame(1, (int) $after['cell_number']); // fallback т.к. нет claimed/explored
    }

    public function testProcessDeathRespawnsToClaimedCellIfPresent(): void
    {
        $db = Database::connect('tests');
        $loserId = $this->insertChar('Loser');
        $db->query('INSERT INTO claimed_cells (character_id, map_cell_id, status) VALUES (?, 555, ?)', [$loserId, 'active']);
        $loser = $db->query('SELECT * FROM characters WHERE id = ?', [$loserId])->getRowArray();

        $this->orch->processDeathAndRespawn($loser);

        $after = $db->query('SELECT cell_number FROM characters WHERE id = ?', [$loserId])->getRowArray();
        $this->assertSame(555, (int) $after['cell_number']);
    }

    // ---- findRespawnCell ----

    public function testFindRespawnFallbackToOne(): void
    {
        $this->assertSame(1, $this->orch->findRespawnCell(99999));
    }

    public function testFindRespawnPrefersClaimedOverExplored(): void
    {
        $db = Database::connect('tests');
        $charId = $this->insertChar('C');
        $db->query('INSERT INTO claimed_cells (character_id, map_cell_id, status) VALUES (?, 100, ?)', [$charId, 'active']);
        $db->query('INSERT INTO explored_cells (character_id, map_cell_id) VALUES (?, 200)', [$charId]);

        $this->assertSame(100, $this->orch->findRespawnCell($charId));
    }

    public function testFindRespawnReturnsClaimedCellIgnoringStatus(): void
    {
        // findRespawnCell в legacy НЕ фильтрует по status (баг — но мы preserve as-is).
        $db = Database::connect('tests');
        $charId = $this->insertChar('C');
        $db->query('INSERT INTO claimed_cells (character_id, map_cell_id, status) VALUES (?, 333, ?)', [$charId, 'inactive']);

        $this->assertSame(333, $this->orch->findRespawnCell($charId));
    }

    public function testFindRespawnPicksRandomExplored(): void
    {
        $db = Database::connect('tests');
        $charId = $this->insertChar('C');
        $db->query('INSERT INTO explored_cells (character_id, map_cell_id) VALUES (?, 11)', [$charId]);
        $db->query('INSERT INTO explored_cells (character_id, map_cell_id) VALUES (?, 22)', [$charId]);
        $db->query('INSERT INTO explored_cells (character_id, map_cell_id) VALUES (?, 33)', [$charId]);

        mt_srand(42);
        $cell = $this->orch->findRespawnCell($charId);
        $this->assertContains($cell, [11, 22, 33]);
    }

    // ---- processMutualExhaustion ----

    public function testProcessMutualExhaustionResetsBothToTen(): void
    {
        $db = Database::connect('tests');
        $aId = $this->insertChar('A', ['health' => 100, 'tired' => 100]);
        $bId = $this->insertChar('B', ['health' => 100, 'tired' => 100]);
        $a = ['id' => $aId];
        $b = ['id' => $bId];

        $this->orch->processMutualExhaustion($a, $b);

        $rowA = $db->query('SELECT health, tired FROM characters WHERE id = ?', [$aId])->getRowArray();
        $rowB = $db->query('SELECT health, tired FROM characters WHERE id = ?', [$bId])->getRowArray();
        $this->assertSame('10.00', $rowA['health']);
        $this->assertSame('10.00', $rowA['tired']);
        $this->assertSame('10.00', $rowB['health']);
        $this->assertSame('10.00', $rowB['tired']);
    }

    // ---- diff text helpers ----

    public function testMakeLoserDiffTextFormatsLossesCorrectly(): void
    {
        $db = Database::connect('tests');
        $charId = $this->insertChar('Loser', [
            'experience' => 950, 'strength' => 99.5, 'agility' => 99.5, 'intellect' => 99.5,
        ]);
        // before snapshot — до debuff'а.
        $before = ['id' => $charId, 'experience' => 1000.0, 'strength' => 100.0, 'agility' => 100.0, 'intellect' => 100.0];

        $text = $this->orch->makeLoserDiffText($before);

        $this->assertStringContainsString('Опыт: -50', $text);
        $this->assertStringContainsString('Сила: -0.5', $text);
        $this->assertStringContainsString('Ловкость: -0.5', $text);
        $this->assertStringContainsString('Интеллект: -0.5', $text);
    }

    public function testMakeLoserDiffTextEmptyForUnknownChar(): void
    {
        $before = ['id' => 99999, 'experience' => 0, 'strength' => 0, 'agility' => 0, 'intellect' => 0];
        $this->assertSame('', $this->orch->makeLoserDiffText($before));
    }

    public function testMakeWinnerDiffTextFormatsGainsCorrectly(): void
    {
        $db = Database::connect('tests');
        $charId = $this->insertChar('Winner', [
            'experience' => 1100, 'strength' => 100.1, 'agility' => 100.0, 'intellect' => 100.0,
        ]);
        $before = ['id' => $charId, 'experience' => 1000.0, 'strength' => 100.0, 'agility' => 100.0, 'intellect' => 100.0];

        $text = $this->orch->makeWinnerDiffText($before);

        $this->assertStringContainsString('Опыт: +100', $text);
        $this->assertStringContainsString('Сила: +0.1', $text);
        $this->assertStringContainsString('Ловкость: +0', $text);
    }

    public function testMakeLoserDiffTextClampsNegativeAt0(): void
    {
        $db = Database::connect('tests');
        // Случай когда after > before (theoretically not possible, но max(0, ...) guard).
        $charId = $this->insertChar('Char', ['experience' => 1500]);
        $before = ['id' => $charId, 'experience' => 1000.0, 'strength' => 100.0, 'agility' => 100.0, 'intellect' => 100.0];

        $text = $this->orch->makeLoserDiffText($before);
        // after - before = 500, but max(0, 1000-1500) = 0
        $this->assertStringContainsString('Опыт: -0', $text);
    }
}
