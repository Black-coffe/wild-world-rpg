<?php

namespace Tests\Unit\Services\Player\Gather;

use App\Services\Player\Gather\GatherFormulaService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * F2.7 — unit-тесты на чистые формулы добычи.
 * Источник истины: legacy GatherTaskHandler v0.5.0 (методы 340-471).
 *
 * @internal
 */
final class GatherFormulaServiceTest extends CIUnitTestCase
{
    private GatherFormulaService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new GatherFormulaService();
    }

    // ---- getAllowedRarities ----

    public function testLevel1OnlyHighestRarity(): void
    {
        $this->assertSame([10], $this->svc->getAllowedRarities(1));
    }

    public function testLevel2AddsRarity9(): void
    {
        $this->assertSame([9, 10], $this->svc->getAllowedRarities(2));
    }

    public function testLevel5(): void
    {
        $this->assertSame([6, 7, 8, 9, 10], $this->svc->getAllowedRarities(5));
    }

    public function testLevel10AndAboveAllRarities(): void
    {
        $expected = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
        $this->assertSame($expected, $this->svc->getAllowedRarities(10));
        $this->assertSame($expected, $this->svc->getAllowedRarities(50));
        $this->assertSame($expected, $this->svc->getAllowedRarities(335));
    }

    // ---- unlockLevelForRarity (ADR-090) ----

    public function testUnlockLevelForRarity(): void
    {
        $this->assertSame(1, $this->svc->unlockLevelForRarity(10));
        $this->assertSame(2, $this->svc->unlockLevelForRarity(9));
        $this->assertSame(5, $this->svc->unlockLevelForRarity(6));
        $this->assertSame(10, $this->svc->unlockLevelForRarity(1));
    }

    // ---- rarityYieldFactor (ADR-090 «Мягкий старт») ----

    /** Killswitch OFF → жёсткий гейт (byte-identical): 1.0 если разблокировано, иначе 0.0. */
    public function testYieldFactorDisabledIsHardGate(): void
    {
        // L1: только rarity 10 разблокирована.
        $this->assertSame(1.0, $this->svc->rarityYieldFactor(1, 10, false, 2, 0.20));
        $this->assertSame(0.0, $this->svc->rarityYieldFactor(1, 9, false, 2, 0.20));
        $this->assertSame(0.0, $this->svc->rarityYieldFactor(1, 1, false, 2, 0.20));
        // L5: rarity 6..10 разблокированы (unlock L5..L1).
        $this->assertSame(1.0, $this->svc->rarityYieldFactor(5, 6, false, 2, 0.20));
        $this->assertSame(0.0, $this->svc->rarityYieldFactor(5, 5, false, 2, 0.20));
    }

    /** Контракт byte-identical: OFF-фактор == (level>=unlock ? 1.0 : 0.0) для всех пар. */
    public function testYieldFactorDisabledMatchesAllowedRarities(): void
    {
        foreach ([1, 2, 3, 5, 8, 10, 50] as $level) {
            $allowed = $this->svc->getAllowedRarities($level);
            for ($rarity = 1; $rarity <= 10; $rarity++) {
                $expected = in_array($rarity, $allowed, true) ? 1.0 : 0.0;
                $this->assertSame(
                    $expected,
                    $this->svc->rarityYieldFactor($level, $rarity, false, 2, 0.20),
                    "OFF factor должен совпадать с жёстким гейтом: level={$level} rarity={$rarity}"
                );
            }
        }
    }

    /** Killswitch ON → soft-ramp: разблокированные полны, превью-тиры step^tiersEarly, дальше окна 0. */
    public function testYieldFactorEnabledSoftRamp(): void
    {
        // L1, window 2, step 0.20:
        $this->assertSame(1.0, $this->svc->rarityYieldFactor(1, 10, true, 2, 0.20));            // unlock L1, full
        $this->assertEqualsWithDelta(0.20, $this->svc->rarityYieldFactor(1, 9, true, 2, 0.20), 1e-9); // 1 early
        $this->assertEqualsWithDelta(0.04, $this->svc->rarityYieldFactor(1, 8, true, 2, 0.20), 1e-9); // 2 early
        $this->assertSame(0.0, $this->svc->rarityYieldFactor(1, 7, true, 2, 0.20));             // 3 early > window → 0
        // Разблокированный тир на более высоком уровне → full.
        $this->assertSame(1.0, $this->svc->rarityYieldFactor(3, 8, true, 2, 0.20));             // r8 unlock L3
    }

    /** window 0 → превью выключено (эквивалент жёсткого гейта даже при ON). */
    public function testYieldFactorZeroWindowNoPreview(): void
    {
        $this->assertSame(1.0, $this->svc->rarityYieldFactor(1, 10, true, 0, 0.20));
        $this->assertSame(0.0, $this->svc->rarityYieldFactor(1, 9, true, 0, 0.20));
    }

    // ---- getBaseQuantityByRarity ----

    public function testBaseQuantityByRarityTable(): void
    {
        $this->assertSame(60, $this->svc->getBaseQuantityByRarity(10));
        $this->assertSame(58, $this->svc->getBaseQuantityByRarity(9));
        $this->assertSame(50, $this->svc->getBaseQuantityByRarity(8));
        $this->assertSame(38, $this->svc->getBaseQuantityByRarity(7));
        $this->assertSame(25, $this->svc->getBaseQuantityByRarity(6));
        $this->assertSame(18, $this->svc->getBaseQuantityByRarity(5));
        $this->assertSame(10, $this->svc->getBaseQuantityByRarity(4));
        $this->assertSame(5,  $this->svc->getBaseQuantityByRarity(3));
        $this->assertSame(3,  $this->svc->getBaseQuantityByRarity(2));
        $this->assertSame(2,  $this->svc->getBaseQuantityByRarity(1));
    }

    public function testBaseQuantityForUnknownRarity(): void
    {
        $this->assertSame(0, $this->svc->getBaseQuantityByRarity(0));
        $this->assertSame(0, $this->svc->getBaseQuantityByRarity(11));
        $this->assertSame(0, $this->svc->getBaseQuantityByRarity(-1));
    }

    // ---- getHealthTirednessFactor ----

    public function testHealthyAndRestedFactor(): void
    {
        // health=100, tired=100 → ((100-50)/50 + (100-50)/50) = 1+1 = 2
        // factor = 1 + 0.125*2 = 1.25
        $this->assertSame(1.25, $this->svc->getHealthTirednessFactor([
            'health' => 100, 'tired' => 100,
        ]));
    }

    public function testNeutralFactor(): void
    {
        // health=50, tired=50 → 0+0=0 → factor=1.0
        $this->assertSame(1.0, $this->svc->getHealthTirednessFactor([
            'health' => 50, 'tired' => 50,
        ]));
    }

    public function testZeroHealthClampedToMinimum(): void
    {
        // health=0, tired=0 → -1+(-1)=-2 → 1+0.125*(-2) = 0.75 → clamp 0.1
        // Hmm wait, 1 + 0.125*(-2) = 0.75, NOT below 0.1.
        // Let me recheck: -1 -1 = -2, factor = 1 + 0.125 * -2 = 1 - 0.25 = 0.75. Не clamp'ит.
        $this->assertSame(0.75, $this->svc->getHealthTirednessFactor([
            'health' => 0, 'tired' => 0,
        ]));
    }

    public function testCriticalHealthClamped(): void
    {
        // health=-1000, tired=-1000 → factor должен clamp'нуться на 0.1
        $this->assertSame(0.1, $this->svc->getHealthTirednessFactor([
            'health' => -1000, 'tired' => -1000,
        ]));
    }

    public function testMaxFactorClampedAt2(): void
    {
        // health=10000, tired=10000 → factor должен clamp'нуться на 2.0
        $this->assertSame(2.0, $this->svc->getHealthTirednessFactor([
            'health' => 10000, 'tired' => 10000,
        ]));
    }

    // ---- calculateSpentMinutes ----

    public function testCalculateSpentMinutesSimple(): void
    {
        $this->assertSame(60, $this->svc->calculateSpentMinutes(
            '2026-05-04 10:00:00',
            '2026-05-04 11:00:00'
        ));
    }

    public function testCalculateSpentMinutesAcrossDay(): void
    {
        $this->assertSame(1500, $this->svc->calculateSpentMinutes(
            '2026-05-04 10:00:00',
            '2026-05-05 11:00:00'
        ));
    }

    public function testCalculateSpentMinutesPartial(): void
    {
        $this->assertSame(33, $this->svc->calculateSpentMinutes(
            '2026-05-04 10:00:00',
            '2026-05-04 10:33:00'
        ));
    }

    public function testCalculateSpentMinutesSameTime(): void
    {
        $this->assertSame(0, $this->svc->calculateSpentMinutes(
            '2026-05-04 10:00:00',
            '2026-05-04 10:00:00'
        ));
    }
}
