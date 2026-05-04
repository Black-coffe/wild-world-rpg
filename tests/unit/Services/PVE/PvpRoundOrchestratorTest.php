<?php

namespace Tests\Unit\Services\PVE;

use App\Services\PVE\PvpFormulaService;
use App\Services\PVE\PvpRoundOrchestrator;
use CodeIgniter\Test\CIUnitTestCase;
use Config\GameBalance;

/**
 * F2.3b Step 3 — unit-тесты на pure методы PvpRoundOrchestrator.
 * simulateFight требует DB (через PvpDamageCalculator → PvpEquipmentRepository),
 * его покрывает AttackPlayerActionFixtureFenceTest.
 *
 * @internal
 */
final class PvpRoundOrchestratorTest extends CIUnitTestCase
{
    private PvpRoundOrchestrator $orch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orch = new PvpRoundOrchestrator(null, new PvpFormulaService(), new GameBalance());
    }

    private function ch(string $name, int $level, float $agility, float $strength = 50.0): array
    {
        return [
            'id'          => crc32($name) & 0x7fffffff,
            'name'        => $name,
            'level'       => $level,
            'health'      => 100.0,
            'strength'    => $strength,
            'agility'     => $agility,
            'intellect'   => 50.0,
        ];
    }

    // ---- determineInitiative ----

    public function testInitiativeHigherAgilityWins(): void
    {
        $alice = $this->ch('Alice', 50, 80.0);
        $bob   = $this->ch('Bob',   50, 50.0);

        $first = $this->orch->determineInitiative($alice, $bob);
        $this->assertSame('Alice', $first['name']);
    }

    public function testInitiativeHigherLevelWinsWhenAgilityEqual(): void
    {
        $alice = $this->ch('Alice', 60, 50.0);
        $bob   = $this->ch('Bob',   30, 50.0);

        $first = $this->orch->determineInitiative($alice, $bob);
        // i1 = 50 + 61*0.5 = 80.5 ; i2 = 50 + 31*0.5 = 65.5 → Alice
        $this->assertSame('Alice', $first['name']);
    }

    public function testInitiativeAgilityCanCompensateForLowerLevel(): void
    {
        $alice = $this->ch('Alice', 30, 100.0); // 100 + 31*0.5 = 115.5
        $bob   = $this->ch('Bob',   60, 50.0);  // 50  + 61*0.5 = 80.5

        $first = $this->orch->determineInitiative($alice, $bob);
        $this->assertSame('Alice', $first['name']);
    }

    public function testInitiativeTieBreakerFirstParam(): void
    {
        $alice = $this->ch('Alice', 50, 50.0);
        $bob   = $this->ch('Bob',   50, 50.0);
        // i1 == i2 → возвращает c1 (Alice).
        $this->assertSame('Alice', $this->orch->determineInitiative($alice, $bob)['name']);
    }

    // ---- checkLuckyStrike ----

    public function testLuckyStrikeFalseWhenAttackerStronger(): void
    {
        // diff > 0 → return false, БЕЗ mt_rand consumed.
        $atk = $this->ch('Attacker', 100, 50.0);
        $def = $this->ch('Defender', 50,  50.0);

        // Несколько прогонов — должен возвращать false независимо от seed.
        mt_srand(1);
        $this->assertFalse($this->orch->checkLuckyStrike($atk, $def));
        mt_srand(999);
        $this->assertFalse($this->orch->checkLuckyStrike($atk, $def));
    }

    public function testLuckyStrikeFalseWhenLevelsEqual(): void
    {
        $atk = $this->ch('A', 50, 50.0);
        $def = $this->ch('B', 50, 50.0);
        $this->assertFalse($this->orch->checkLuckyStrike($atk, $def));
    }

    public function testLuckyStrikeWithSeededRandomDeterministic(): void
    {
        // Слабый атакер — chance вычисляется (зависит от lvlDiff и agi).
        $atk = $this->ch('Weak',   30, 100.0);
        $def = $this->ch('Strong', 60, 50.0);

        // Lucky strike chance с этими статами:
        //   absDiff=30 × 0.3 = 9.0 base
        //   agiDiff=50 × 0.02 × 100 = 100 → cap 40
        //   итого chance=40 (макс)

        // Seed=1 vs seed=999: разный результат, но детерминированный.
        mt_srand(1);
        $r1 = $this->orch->checkLuckyStrike($atk, $def);
        mt_srand(1);
        $r2 = $this->orch->checkLuckyStrike($atk, $def);
        $this->assertSame($r1, $r2, 'reseed=1 → identical result');
    }

    // ---- applyLuckyStrikeDebuff ----

    public function testDebuffReducesOneStat(): void
    {
        $defender = [
            'strength'  => 100.0,
            'agility'   => 100.0,
            'intellect' => 100.0,
        ];
        $before = $defender;
        $this->orch->applyLuckyStrikeDebuff($defender);

        // Ровно ОДИН стат должен уменьшиться.
        $changedCount = 0;
        foreach (['strength', 'agility', 'intellect'] as $st) {
            if ($defender[$st] != $before[$st]) {
                $changedCount++;
                // -10% (luckyStrikeDebuffPercent=0.10) → 100 × 0.9 = 90
                $this->assertEqualsWithDelta(90.0, $defender[$st], 0.001);
            }
        }
        $this->assertSame(1, $changedCount, 'ровно один стат debuff\'нут');
    }

    public function testDebuffClampedAtMinimum1(): void
    {
        // Все статы 1 → debuff даёт 0.9 → clamp на 1.
        $defender = [
            'strength'  => 1,
            'agility'   => 1,
            'intellect' => 1,
        ];
        $this->orch->applyLuckyStrikeDebuff($defender);
        foreach (['strength', 'agility', 'intellect'] as $st) {
            $this->assertGreaterThanOrEqual(1, $defender[$st]);
        }
    }
}
