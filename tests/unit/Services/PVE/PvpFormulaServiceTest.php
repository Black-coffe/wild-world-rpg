<?php

namespace Tests\Unit\Services\PVE;

use App\Services\PVE\PvpFormulaService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\GameBalance;

/**
 * F2.3 — unit-тесты на чистые формулы PvP.
 * Источник истины: AttackPlayerAction v0.5.0, методы 752-789 + 1099-1114
 * + lore/combat/PvP.md.
 *
 * @internal
 */
final class PvpFormulaServiceTest extends CIUnitTestCase
{
    private PvpFormulaService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new PvpFormulaService(new GameBalance());
    }

    // ---- computeLevelBonus ----

    public function testLevelBonusEqualLevels(): void
    {
        $this->assertSame(0.0, $this->svc->computeLevelBonus(['level' => 50], ['level' => 50]));
    }

    public function testLevelBonusAttackerHigherCappedAt10Percent(): void
    {
        // diff = 100 - 50 = 50 → cap'нут на 5 → 5 × 0.02 = 0.1
        $this->assertSame(0.1, $this->svc->computeLevelBonus(['level' => 100], ['level' => 50]));
    }

    public function testLevelBonusAttackerLowerCappedAtMinus10Percent(): void
    {
        // diff = 30 - 100 = -70 → cap'нут на -5 → -5 × 0.02 = -0.1
        $this->assertSame(-0.1, $this->svc->computeLevelBonus(['level' => 30], ['level' => 100]));
    }

    public function testLevelBonusModeratePositive(): void
    {
        // diff = 53 - 50 = 3 → 3 × 0.02 = 0.06
        $this->assertSame(0.06, $this->svc->computeLevelBonus(['level' => 53], ['level' => 50]));
    }

    // ---- computeStatsBonus ----

    public function testStatsBonusZeroStrength(): void
    {
        $this->assertSame(0.0, $this->svc->computeStatsBonus(['strength' => 0]));
    }

    public function testStatsBonusFiftyStrength(): void
    {
        // 50 * 0.001 = 0.05
        $this->assertSame(0.05, $this->svc->computeStatsBonus(['strength' => 50]));
    }

    public function testStatsBonusCappedAt10Percent(): void
    {
        // 100 * 0.001 = 0.1, проверка cap
        $this->assertSame(0.1, $this->svc->computeStatsBonus(['strength' => 100]));
        // 500 * 0.001 = 0.5, cap'нут на 0.1
        $this->assertSame(0.1, $this->svc->computeStatsBonus(['strength' => 500]));
    }

    public function testStatsBonusMissingStrength(): void
    {
        $this->assertSame(0.0, $this->svc->computeStatsBonus([]));
    }

    // ---- getDodgeChance ----

    public function testDodgeChanceZeroAgility(): void
    {
        $this->assertSame(0.0, $this->svc->getDodgeChance(['agility' => 0]));
    }

    public function testDodgeChanceModerateAgility(): void
    {
        // 100 × 0.25 = 25
        $this->assertSame(25.0, $this->svc->getDodgeChance(['agility' => 100]));
    }

    public function testDodgeChanceCappedAt75(): void
    {
        // 1000 × 0.25 = 250 → cap 75
        $this->assertSame(75.0, $this->svc->getDodgeChance(['agility' => 1000]));
    }

    // ---- computeRangePenalty ----

    public function testRangePenaltyCloseRange(): void
    {
        $this->assertSame(1.0, $this->svc->computeRangePenalty(10.0, 5.0));
        $this->assertSame(1.0, $this->svc->computeRangePenalty(10.0, 10.0));
    }

    public function testRangePenaltyTooFar(): void
    {
        // weapon=10, distance=20 → 0.5
        $this->assertSame(0.5, $this->svc->computeRangePenalty(10.0, 20.0));
    }

    public function testRangePenaltyVeryFar(): void
    {
        $this->assertSame(0.1, $this->svc->computeRangePenalty(10.0, 100.0));
    }

    // ---- getRarityCoefficient ----

    public function testRarityCoefficientsMatchTable(): void
    {
        $this->assertSame(1.0, $this->svc->getRarityCoefficient('common'));
        $this->assertSame(1.1, $this->svc->getRarityCoefficient('uncommon'));
        $this->assertSame(1.3, $this->svc->getRarityCoefficient('rare'));
        $this->assertSame(1.5, $this->svc->getRarityCoefficient('epic'));
        $this->assertSame(1.7, $this->svc->getRarityCoefficient('legendary'));
    }

    public function testRarityCoefficientCaseInsensitive(): void
    {
        $this->assertSame(1.5, $this->svc->getRarityCoefficient('EPIC'));
        $this->assertSame(1.7, $this->svc->getRarityCoefficient('Legendary'));
    }

    public function testRarityCoefficientUnknownDefaultsToCommon(): void
    {
        $this->assertSame(1.0, $this->svc->getRarityCoefficient('cosmic'));
        $this->assertSame(1.0, $this->svc->getRarityCoefficient(''));
    }

    // ---- rollPercent ----

    public function testRollPercentZeroNeverHits(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $this->assertFalse($this->svc->rollPercent(0.0));
        }
    }

    public function testRollPercentHundredAlwaysHits(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $this->assertTrue($this->svc->rollPercent(100.0));
        }
    }

    // ---- calculateLuckyStrikeChance ----

    public function testLuckyStrikeAttackerStrongerNoChance(): void
    {
        // diff >= 0 → 0% lucky strike
        $this->assertSame(0.0, $this->svc->calculateLuckyStrikeChance(
            ['level' => 100, 'agility' => 50],
            ['level' => 50,  'agility' => 50]
        ));
    }

    public function testLuckyStrikeAttackerWeakerSmallDiff(): void
    {
        // diff = 50 - 60 = -10, abs=10, chance = 10 × 0.3 = 3.0
        // agility same, no agi bonus
        $this->assertSame(3.0, $this->svc->calculateLuckyStrikeChance(
            ['level' => 50, 'agility' => 50],
            ['level' => 60, 'agility' => 50]
        ));
    }

    public function testLuckyStrikeAgilityAdds(): void
    {
        // diff = -10, base 3.0; agi diff = 100, +100 × 0.02 × 100 = 200
        // total 203, cap'нут на luckyStrikeMaxChance = 40
        $this->assertSame(40.0, $this->svc->calculateLuckyStrikeChance(
            ['level' => 50, 'agility' => 200],
            ['level' => 60, 'agility' => 100]
        ));
    }

    public function testLuckyStrikeAttackerHigherAgilityLowerLevel(): void
    {
        // diff = -1, base = 0.3; agi diff = 1, +1 × 0.02 × 100 = 2 → total 2.3
        $this->assertEqualsWithDelta(
            2.3,
            $this->svc->calculateLuckyStrikeChance(
                ['level' => 49, 'agility' => 51],
                ['level' => 50, 'agility' => 50]
            ),
            0.001
        );
    }
}
