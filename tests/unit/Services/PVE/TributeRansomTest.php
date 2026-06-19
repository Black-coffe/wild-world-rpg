<?php

declare(strict_types=1);

namespace Tests\Unit\Services\PVE;

use App\Services\PVE\TributeService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-135 Ф3 — unit-тесты чистой `TributeService::computeRansom()` (формула выкупа-burn).
 * cost = clamp(base + floor(collected*k), base, hard_cap). Без БД.
 *
 * @internal
 */
final class TributeRansomTest extends CIUnitTestCase
{
    public function testBaseWhenNothingCollected(): void
    {
        $this->assertSame(2000, TributeService::computeRansom(0, 2000, 0.5, 15000));
    }

    public function testScalesWithCollected(): void
    {
        // 2000 + floor(1000*0.5) = 2500.
        $this->assertSame(2500, TributeService::computeRansom(1000, 2000, 0.5, 15000));
    }

    public function testHardCapClamps(): void
    {
        // 2000 + 50000 = 52000 → cap 15000 (анти-P2W «дикие суммы»).
        $this->assertSame(15000, TributeService::computeRansom(100000, 2000, 0.5, 15000));
    }

    public function testNegativeCollectedTreatedAsZero(): void
    {
        $this->assertSame(2000, TributeService::computeRansom(-50, 2000, 0.5, 15000));
    }

    public function testZeroKStaysAtBase(): void
    {
        $this->assertSame(2000, TributeService::computeRansom(1000, 2000, 0.0, 15000));
    }

    public function testFloorRounding(): void
    {
        // 333 * 0.5 = 166.5 → floor 166 → 2166.
        $this->assertSame(2166, TributeService::computeRansom(333, 2000, 0.5, 15000));
    }

    public function testNoHardCapWhenZero(): void
    {
        // hardCap=0 → без потолка.
        $this->assertSame(52000, TributeService::computeRansom(100000, 2000, 0.5, 0));
    }
}
