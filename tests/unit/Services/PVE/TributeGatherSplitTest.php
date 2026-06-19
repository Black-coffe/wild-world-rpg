<?php

declare(strict_types=1);

namespace Tests\Unit\Services\PVE;

use App\Services\PVE\TributeService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-135 Ф2 — unit-тесты чистой `TributeService::splitGather()` (zero-sum раздел добычи).
 * Ключевой инвариант: для каждого ресурса vassal_amount + masterShare == original; общий
 * переток ограничен дневным cap. Без БД.
 *
 * @internal
 */
final class TributeGatherSplitTest extends CIUnitTestCase
{
    /** @return array{resource_id:int, amount:int} */
    private function res(int $id, int $amt): array
    {
        return ['resource_id' => $id, 'amount' => $amt];
    }

    public function testZeroSumBasicTenPercent(): void
    {
        $s = TributeService::splitGather([$this->res(1, 100), $this->res(2, 50)], 0.10, 1000);
        $this->assertSame(15, $s['transferred']);
        $this->assertSame(90, $s['vassal'][0]['amount']);
        $this->assertSame(45, $s['vassal'][1]['amount']);
        $this->assertSame(10, $s['masterCredits'][1]);
        $this->assertSame(5, $s['masterCredits'][2]);
        // Инвариант Σ для каждого ресурса.
        $this->assertSame(100, $s['vassal'][0]['amount'] + $s['masterCredits'][1]);
        $this->assertSame(50, $s['vassal'][1]['amount'] + $s['masterCredits'][2]);
    }

    public function testFloorBelowTenUnitsNoTribute(): void
    {
        // floor(9 * 0.1) = 0 → жертва оставляет всё, переток 0.
        $s = TributeService::splitGather([$this->res(1, 9)], 0.10, 1000);
        $this->assertSame(0, $s['transferred']);
        $this->assertSame(9, $s['vassal'][0]['amount']);
        $this->assertArrayNotHasKey(1, $s['masterCredits']);
    }

    public function testDailyCapLimitsSingleResource(): void
    {
        // floor(100*0.1)=10, но cap=3 → переток 3.
        $s = TributeService::splitGather([$this->res(1, 100)], 0.10, 3);
        $this->assertSame(3, $s['transferred']);
        $this->assertSame(97, $s['vassal'][0]['amount']);
        $this->assertSame(3, $s['masterCredits'][1]);
    }

    public function testCapExhaustsAcrossResources(): void
    {
        // wood floor(10)=10 (remaining 12→2); stone min(10, 2)=2.
        $s = TributeService::splitGather([$this->res(1, 100), $this->res(2, 100)], 0.10, 12);
        $this->assertSame(12, $s['transferred']);
        $this->assertSame(10, $s['masterCredits'][1]);
        $this->assertSame(2, $s['masterCredits'][2]);
        $this->assertSame(90, $s['vassal'][0]['amount']);
        $this->assertSame(98, $s['vassal'][1]['amount']);
    }

    public function testZeroRateNoTransfer(): void
    {
        $s = TributeService::splitGather([$this->res(1, 100)], 0.0, 1000);
        $this->assertSame(0, $s['transferred']);
        $this->assertSame(100, $s['vassal'][0]['amount']);
    }

    public function testZeroCapNoTransfer(): void
    {
        $s = TributeService::splitGather([$this->res(1, 100)], 0.10, 0);
        $this->assertSame(0, $s['transferred']);
        $this->assertSame(100, $s['vassal'][0]['amount']);
    }

    public function testInvariantSumPreservedMixed(): void
    {
        $found  = [$this->res(1, 37), $this->res(2, 8), $this->res(3, 250)];
        $s      = TributeService::splitGather($found, 0.10, 1000);
        $vassal = 0;
        foreach ($s['vassal'] as $r) {
            $vassal += $r['amount'];
        }
        // Глобальный инвариант: ничего не потеряно и не создано.
        $this->assertSame(37 + 8 + 250, $vassal + $s['transferred']);
    }

    public function testNegativeCapTreatedAsZero(): void
    {
        $s = TributeService::splitGather([$this->res(1, 100)], 0.10, -5);
        $this->assertSame(0, $s['transferred']);
        $this->assertSame(100, $s['vassal'][0]['amount']);
    }
}
