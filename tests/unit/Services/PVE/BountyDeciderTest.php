<?php

declare(strict_types=1);

namespace Tests\Unit\Services\PVE;

use App\Services\PVE\BountyService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-135 Ф3b — unit-тесты чистых решений `BountyService` (без БД): кулдаун повторного
 * трофея и порог титула «Охотник за головами».
 *
 * @internal
 */
final class BountyDeciderTest extends CIUnitTestCase
{
    // ── withinCooldown ───────────────────────────────────────────────────

    public function testCooldownNullLastClaimNeverBlocks(): void
    {
        // Пара ещё не охочена → кулдаун не блокирует.
        $this->assertFalse(BountyService::withinCooldown(null, 1_000_000, 24));
    }

    public function testCooldownZeroHoursNeverBlocks(): void
    {
        $this->assertFalse(BountyService::withinCooldown(1_000_000, 1_000_100, 0));
    }

    public function testCooldownRecentClaimBlocks(): void
    {
        // 1 час назад при окне 24ч → блок.
        $now = 1_000_000;
        $this->assertTrue(BountyService::withinCooldown($now - 3600, $now, 24));
    }

    public function testCooldownOldClaimPasses(): void
    {
        // 25 часов назад при окне 24ч → не блок.
        $now = 1_000_000;
        $this->assertFalse(BountyService::withinCooldown($now - 25 * 3600, $now, 24));
    }

    public function testCooldownBoundaryExactlyAtWindow(): void
    {
        // Ровно 24ч назад → НЕ < окна → не блок.
        $now = 1_000_000;
        $this->assertFalse(BountyService::withinCooldown($now - 24 * 3600, $now, 24));
        // На секунду свежее границы → блок.
        $this->assertTrue(BountyService::withinCooldown($now - (24 * 3600 - 1), $now, 24));
    }

    // ── qualifiesForTitle ────────────────────────────────────────────────

    public function testTitleBelowThreshold(): void
    {
        $this->assertFalse(BountyService::qualifiesForTitle(2, 3));
    }

    public function testTitleAtThreshold(): void
    {
        $this->assertTrue(BountyService::qualifiesForTitle(3, 3));
    }

    public function testTitleAboveThreshold(): void
    {
        $this->assertTrue(BountyService::qualifiesForTitle(10, 3));
    }

    public function testTitleThresholdZeroDisables(): void
    {
        // threshold ≤ 0 → титул не выдаётся (защита от мисконфига).
        $this->assertFalse(BountyService::qualifiesForTitle(100, 0));
    }
}
