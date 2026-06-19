<?php

declare(strict_types=1);

namespace Tests\Unit\Services\PVE;

use App\Services\PVE\TributeService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-135 «Трофейная подать» — unit-тесты чистой `TributeService::decideDomination()`.
 * Без БД/настроек/времени: проверяем гейты-защиты, порог доминирования, односторонность,
 * порядок проверок и граничные значения. Источник истины — ADR-135 §Дизайн v1.
 *
 * @internal
 */
final class TributeDominanceDeciderTest extends CIUnitTestCase
{
    /**
     * Полностью eligible контекст (5/5 побед, 0 поражений, все гейты пройдены).
     *
     * @param array<string,mixed> $over
     * @return array<string,mixed>
     */
    private function ctx(array $over = []): array
    {
        return array_merge([
            'winsByMaster'          => 5,
            'winsByVassal'          => 0,
            'winsRequired'          => 5,
            'masterLevel'           => 25,
            'vassalLevel'           => 22,
            'minLevel'              => 20,
            'maxLevelGap'           => 10,
            'vassalAccountAgeDays'  => 30,
            'minAccountAgeDays'     => 21,
            'vassalRespawnHoursAgo' => 48,
            'respawnImmunityHours'  => 24,
            'hasActiveTribute'      => false,
            'daysSinceLastLift'     => null,
            'recaptureCooldownDays' => 7,
        ], $over);
    }

    public function testEligibleBaseline(): void
    {
        $d = TributeService::decideDomination($this->ctx());
        $this->assertTrue($d['eligible']);
        $this->assertSame('eligible', $d['reason']);
    }

    public function testInsufficientDominance(): void
    {
        $d = TributeService::decideDomination($this->ctx(['winsByMaster' => 4]));
        $this->assertFalse($d['eligible']);
        $this->assertSame('insufficient_dominance', $d['reason']);
    }

    public function testNotOneSidedWhenVassalHasAWin(): void
    {
        $d = TributeService::decideDomination($this->ctx(['winsByVassal' => 1]));
        $this->assertFalse($d['eligible']);
        $this->assertSame('not_one_sided', $d['reason']);
    }

    public function testVassalBelowMinLevel(): void
    {
        $d = TributeService::decideDomination($this->ctx(['vassalLevel' => 19]));
        $this->assertFalse($d['eligible']);
        $this->assertSame('vassal_below_min_level', $d['reason']);
    }

    public function testLevelGapTooLarge(): void
    {
        // master 40 - vassal 22 = 18 > 10.
        $d = TributeService::decideDomination($this->ctx(['masterLevel' => 40]));
        $this->assertFalse($d['eligible']);
        $this->assertSame('level_gap_too_large', $d['reason']);
    }

    public function testVassalAccountTooNew(): void
    {
        $d = TributeService::decideDomination($this->ctx(['vassalAccountAgeDays' => 10]));
        $this->assertFalse($d['eligible']);
        $this->assertSame('vassal_account_too_new', $d['reason']);
    }

    public function testRespawnImmunity(): void
    {
        $d = TributeService::decideDomination($this->ctx(['vassalRespawnHoursAgo' => 5]));
        $this->assertFalse($d['eligible']);
        $this->assertSame('respawn_immunity', $d['reason']);
    }

    public function testRespawnNullMeansNoImmunity(): void
    {
        // Никогда не респавнился / неизвестно → иммунитет не применяется.
        $d = TributeService::decideDomination($this->ctx(['vassalRespawnHoursAgo' => null]));
        $this->assertTrue($d['eligible']);
    }

    public function testAlreadyActive(): void
    {
        $d = TributeService::decideDomination($this->ctx(['hasActiveTribute' => true]));
        $this->assertFalse($d['eligible']);
        $this->assertSame('already_active', $d['reason']);
    }

    public function testRecaptureCooldownBlocks(): void
    {
        $d = TributeService::decideDomination($this->ctx(['daysSinceLastLift' => 3]));
        $this->assertFalse($d['eligible']);
        $this->assertSame('recapture_cooldown', $d['reason']);
    }

    public function testRecaptureCooldownPassed(): void
    {
        $d = TributeService::decideDomination($this->ctx(['daysSinceLastLift' => 10]));
        $this->assertTrue($d['eligible']);
    }

    public function testGuardPrecedesThreshold(): void
    {
        // Низкий уровень И мало побед → возвращается ГЕЙТ (детерминированный порядок).
        $d = TributeService::decideDomination($this->ctx(['vassalLevel' => 19, 'winsByMaster' => 1]));
        $this->assertSame('vassal_below_min_level', $d['reason']);
    }

    // ── Граничные значения (включающие пороги) ───────────────────────────

    public function testBoundaryWinsExactlyRequired(): void
    {
        $d = TributeService::decideDomination($this->ctx(['winsByMaster' => 5, 'winsRequired' => 5]));
        $this->assertTrue($d['eligible']);
    }

    public function testBoundaryLevelGapExactlyAtLimit(): void
    {
        // gap ровно 10 (master 32, vassal 22) — НЕ > 10 → проходит.
        $d = TributeService::decideDomination($this->ctx(['masterLevel' => 32, 'vassalLevel' => 22]));
        $this->assertTrue($d['eligible']);
    }

    public function testBoundaryAccountAgeExactlyAtMin(): void
    {
        $d = TributeService::decideDomination($this->ctx(['vassalAccountAgeDays' => 21, 'minAccountAgeDays' => 21]));
        $this->assertTrue($d['eligible']);
    }

    public function testBoundaryRespawnExactlyAtImmunity(): void
    {
        // respawnHoursAgo == immunity (24) — НЕ < 24 → иммунитет снят, проходит.
        $d = TributeService::decideDomination($this->ctx(['vassalRespawnHoursAgo' => 24, 'respawnImmunityHours' => 24]));
        $this->assertTrue($d['eligible']);
    }

    public function testVassalAtMinLevelExactlyEligible(): void
    {
        $d = TributeService::decideDomination($this->ctx(['vassalLevel' => 20, 'minLevel' => 20, 'masterLevel' => 25]));
        $this->assertTrue($d['eligible']);
    }

    // ── Анти-сговор: реципрокность + берст (ADR-135 Ф2-hardening) ─────────

    public function testReverseTributeActiveBlocks(): void
    {
        $d = TributeService::decideDomination($this->ctx(['reverseTributeActive' => true]));
        $this->assertFalse($d['eligible']);
        $this->assertSame('reverse_tribute_active', $d['reason']);
    }

    public function testWinsTooBurstyBlocks(): void
    {
        // Разброс 0.5ч < min 2ч → берст (альт «слил» серию за минуты).
        $d = TributeService::decideDomination($this->ctx(['winsSpanHours' => 0.5, 'minWinSpanHours' => 2]));
        $this->assertFalse($d['eligible']);
        $this->assertSame('wins_too_bursty', $d['reason']);
    }

    public function testWinsSpanNullPasses(): void
    {
        // Нет данных о разбросе (< 2 побед / неизвестно) → не судим → проходит.
        $d = TributeService::decideDomination($this->ctx(['winsSpanHours' => null, 'minWinSpanHours' => 2]));
        $this->assertTrue($d['eligible']);
    }

    public function testWinsSpanAtLimitPasses(): void
    {
        // Разброс ровно = min (2.0ч) → НЕ < 2 → проходит.
        $d = TributeService::decideDomination($this->ctx(['winsSpanHours' => 2.0, 'minWinSpanHours' => 2]));
        $this->assertTrue($d['eligible']);
    }

    public function testBurstGateOffWhenMinZero(): void
    {
        // minWinSpanHours=0 → берст-гейт выключен даже при нулевом разбросе.
        $d = TributeService::decideDomination($this->ctx(['winsSpanHours' => 0.0, 'minWinSpanHours' => 0]));
        $this->assertTrue($d['eligible']);
    }

    public function testReverseTributePrecedesBurst(): void
    {
        // Оба нарушены → возвращается реципрокность (раньше в детерминированном порядке).
        $d = TributeService::decideDomination($this->ctx([
            'reverseTributeActive' => true,
            'winsSpanHours'        => 0.1,
            'minWinSpanHours'      => 2,
        ]));
        $this->assertSame('reverse_tribute_active', $d['reason']);
    }

    public function testThresholdPrecedesAntiCollusion(): void
    {
        // Мало побед И берст → возвращается insufficient_dominance (анти-сговор оценивается ПОСЛЕ порога).
        $d = TributeService::decideDomination($this->ctx([
            'winsByMaster'    => 4,
            'winsSpanHours'   => 0.1,
            'minWinSpanHours' => 2,
        ]));
        $this->assertSame('insufficient_dominance', $d['reason']);
    }

    // ── assessCollusion (корреляция активности action_log) ───────────────

    public function testAssessCollusionDisabledWhenMinZero(): void
    {
        $r = TributeService::assessCollusion(['activitySamples' => 100, 'concurrentActions' => 0, 'collusionMinSamples' => 0]);
        $this->assertFalse($r['colluding']);
        $this->assertSame('disabled', $r['reason']);
    }

    public function testAssessCollusionInsufficientSamples(): void
    {
        $r = TributeService::assessCollusion(['activitySamples' => 10, 'concurrentActions' => 0, 'collusionMinSamples' => 50]);
        $this->assertFalse($r['colluding']);
        $this->assertSame('insufficient_samples', $r['reason']);
    }

    public function testAssessCollusionFlagsSingleOperator(): void
    {
        // Достаточно действий у обоих, но НИ РАЗУ не одновременно → сигнатура одного оператора.
        $r = TributeService::assessCollusion(['activitySamples' => 80, 'concurrentActions' => 0, 'collusionMinSamples' => 50]);
        $this->assertTrue($r['colluding']);
        $this->assertSame('no_concurrent_activity', $r['reason']);
    }

    public function testAssessCollusionPassesWhenConcurrent(): void
    {
        // Была одновременная активность → два разных человека.
        $r = TributeService::assessCollusion(['activitySamples' => 80, 'concurrentActions' => 3, 'collusionMinSamples' => 50]);
        $this->assertFalse($r['colluding']);
        $this->assertSame('ok', $r['reason']);
    }

    public function testAssessCollusionSamplesAtThreshold(): void
    {
        // samples ровно = min, concurrent 0 → судим → сговор.
        $r = TributeService::assessCollusion(['activitySamples' => 50, 'concurrentActions' => 0, 'collusionMinSamples' => 50]);
        $this->assertTrue($r['colluding']);
    }

    // ── countConcurrent (двухуказательный merge) ─────────────────────────

    public function testCountConcurrentNoneWhenFarApart(): void
    {
        $a = [1000, 5000, 9000];
        $b = [3000, 7000, 11000];
        $this->assertSame(0, TributeService::countConcurrent($a, $b, 120));
    }

    public function testCountConcurrentMatchesWithinWindow(): void
    {
        $a = [1000, 2000, 3000];
        $b = [1050, 5000]; // 1050 в пределах 120с от 1000.
        $this->assertSame(1, TributeService::countConcurrent($a, $b, 120));
    }

    public function testCountConcurrentBoundaryExactWindow(): void
    {
        $this->assertSame(1, TributeService::countConcurrent([1000], [1120], 120)); // ровно граница → <=.
        $this->assertSame(0, TributeService::countConcurrent([1000], [1121], 120)); // на 1с дальше → нет.
    }

    public function testCountConcurrentEmpty(): void
    {
        $this->assertSame(0, TributeService::countConcurrent([], [1000], 120));
        $this->assertSame(0, TributeService::countConcurrent([1000], [], 120));
    }

    public function testCountConcurrentCountsEachAWithMatch(): void
    {
        // Два разных A около b + один далёкий matched A → 3 (каждый A с совпадением).
        $a = [1000, 1000, 8000];
        $b = [1010, 1020, 8010];
        $this->assertSame(3, TributeService::countConcurrent($a, $b, 120));
    }
}
