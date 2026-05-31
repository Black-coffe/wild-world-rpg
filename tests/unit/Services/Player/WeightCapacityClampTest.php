<?php

namespace Tests\Unit\Services\Player;

use App\Services\Player\WeightCapacityService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-085 Склад Фаза 1b — тесты чистого greedy-clamp WeightCapacityService::clampToRemaining.
 *
 * Overflow-политика «добор до cap + излишек не собран»: kept = что влезло (amount урезан),
 * skipped = недобранное. Безвесые всегда влезают. Полный → всё в skipped.
 * Чистый алгоритм (без DB/settings) — заданный $remaining.
 *
 * @internal
 */
final class WeightCapacityClampTest extends CIUnitTestCase
{
    private function svc(): WeightCapacityService
    {
        return new WeightCapacityService();
    }

    public function testAllFitsWhenAmpleCapacity(): void
    {
        $found = [
            ['resource_id' => 1, 'amount' => 3, 'weight' => 0.5],
            ['resource_id' => 2, 'amount' => 2, 'weight' => 1.0],
        ];
        $r = $this->svc()->clampToRemaining(100.0, $found);
        $this->assertCount(2, $r['kept']);
        $this->assertSame([], $r['skipped']);
        $this->assertSame(3, $r['kept'][0]['amount']);
        $this->assertSame(2, $r['kept'][1]['amount']);
    }

    public function testPartialClampWhenTight(): void
    {
        // remaining 10 kg, item weight 1.0 × 15 → влезает 10, не влезает 5.
        $found = [['resource_id' => 7, 'amount' => 15, 'weight' => 1.0]];
        $r = $this->svc()->clampToRemaining(10.0, $found);
        $this->assertSame(10, $r['kept'][0]['amount']);
        $this->assertCount(1, $r['skipped']);
        $this->assertSame(5, $r['skipped'][0]['amount']);
        $this->assertSame(7, $r['skipped'][0]['resource_id']);
    }

    public function testSkipsAllWhenFull(): void
    {
        // remaining 0 → ничего не влезает (полный рюкзак).
        $found = [
            ['resource_id' => 1, 'amount' => 4, 'weight' => 0.5],
            ['resource_id' => 2, 'amount' => 1, 'weight' => 2.0],
        ];
        $r = $this->svc()->clampToRemaining(0.0, $found);
        $this->assertSame([], $r['kept']);
        $this->assertCount(2, $r['skipped']);
    }

    public function testWeightlessAlwaysFitsEvenWhenFull(): void
    {
        // remaining 0, но безвесые (weight 0) ресурсы влезают всегда (не занимают cap).
        $found = [['resource_id' => 9, 'amount' => 100, 'weight' => 0.0]];
        $r = $this->svc()->clampToRemaining(0.0, $found);
        $this->assertCount(1, $r['kept']);
        $this->assertSame(100, $r['kept'][0]['amount']);
        $this->assertSame([], $r['skipped']);
    }

    public function testGreedyAcrossMultipleResources(): void
    {
        // remaining 10. A: 1.0×8 = 8 (влезает, остаток 2). B: 1.0×5 → влезает 2, не влезает 3.
        $found = [
            ['resource_id' => 1, 'amount' => 8, 'weight' => 1.0],
            ['resource_id' => 2, 'amount' => 5, 'weight' => 1.0],
        ];
        $r = $this->svc()->clampToRemaining(10.0, $found);
        $this->assertSame(8, $r['kept'][0]['amount']);
        $this->assertSame(2, $r['kept'][1]['amount']);
        $this->assertCount(1, $r['skipped']);
        $this->assertSame(3, $r['skipped'][0]['amount']);
        $this->assertSame(2, $r['skipped'][0]['resource_id']);
    }

    public function testMixedWeightlessAndWeightedUnderTightCap(): void
    {
        // remaining 1.0. Безвесый влезает целиком; весовой (0.5×4=2) → влезает 2, не влезает 2.
        $found = [
            ['resource_id' => 3, 'amount' => 50, 'weight' => 0.0],
            ['resource_id' => 4, 'amount' => 4,  'weight' => 0.5],
        ];
        $r = $this->svc()->clampToRemaining(1.0, $found);
        // безвесый kept целиком
        $kept = [];
        foreach ($r['kept'] as $k) {
            $kept[$k['resource_id']] = $k['amount'];
        }
        $this->assertSame(50, $kept[3]);
        $this->assertSame(2, $kept[4]);
        $this->assertSame(2, $r['skipped'][0]['amount']);
    }

    public function testZeroAmountSkippedSilently(): void
    {
        // amount 0 не попадает ни в kept, ни в skipped.
        $found = [['resource_id' => 1, 'amount' => 0, 'weight' => 1.0]];
        $r = $this->svc()->clampToRemaining(100.0, $found);
        $this->assertSame([], $r['kept']);
        $this->assertSame([], $r['skipped']);
    }
}
