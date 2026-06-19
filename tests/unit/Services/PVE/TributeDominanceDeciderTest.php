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
}
