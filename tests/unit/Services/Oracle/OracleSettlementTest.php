<?php

namespace Tests\Unit\Services\Oracle;

use App\Services\Oracle\OracleService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-133 — unit-тесты на чистое ядро пари-мютюэль расчёта (без БД).
 *
 * 🔴 Главный инвариант механики: Σвыплат ≤ Σставок ВСЕГДА → «Оракул острова» это сток,
 * а НЕ фонтан золота (дом сжигает рейк, выигрыш — только из ставок проигравших).
 *
 * @internal
 */
final class OracleSettlementTest extends CIUnitTestCase
{
    /**
     * @param list<array{outcome:string, stake:int}> $bets
     */
    private function sumStakes(array $bets): int
    {
        $s = 0;
        foreach ($bets as $b) {
            $s += $b['stake'];
        }

        return $s;
    }

    /**
     * @param list<int> $payouts
     */
    private function sumPayouts(array $payouts): int
    {
        return array_sum($payouts);
    }

    public function testWorkedExampleFromAdr(): void
    {
        // Гонка фракций: банк 20000 (A9000/B6000/C3000/D2000), рейк 8%, победил B.
        $bets = [
            ['outcome' => 'A', 'stake' => 9000],
            ['outcome' => 'B', 'stake' => 6000],
            ['outcome' => 'C', 'stake' => 3000],
            ['outcome' => 'D', 'stake' => 2000],
        ];
        $r = OracleService::computeSettlement($bets, 'B', 0.08, 500);

        $this->assertFalse($r['void']);
        $this->assertSame(20000, $r['pool']);
        $this->assertSame(1600, $r['rake']);          // floor(20000 * 0.08)
        $this->assertSame(18400, $r['distributable']);
        $this->assertSame(6000, $r['winningPool']);
        // Единственная ставка на B забирает весь распределяемый банк.
        $this->assertSame([0, 18400, 0, 0], $r['payouts']);
    }

    public function testProportionalSplitAmongWinners(): void
    {
        $bets = [
            ['outcome' => 'B', 'stake' => 500],
            ['outcome' => 'B', 'stake' => 1500],
            ['outcome' => 'A', 'stake' => 4000],
        ];
        $r = OracleService::computeSettlement($bets, 'B', 0.08, 500);

        $this->assertFalse($r['void']);
        $this->assertSame(6000, $r['pool']);
        $this->assertSame(480, $r['rake']);            // floor(6000 * 0.08)
        $this->assertSame(5520, $r['distributable']);
        $this->assertSame(2000, $r['winningPool']);
        // floor(500*5520/2000)=1380 ; floor(1500*5520/2000)=4140 ; проигравший 0.
        $this->assertSame([1380, 4140, 0], $r['payouts']);
    }

    public function testRakeIsBurnedNeverAFaucet(): void
    {
        $bets = [
            ['outcome' => 'yes', 'stake' => 1000],
            ['outcome' => 'no', 'stake' => 1000],
        ];
        $r = OracleService::computeSettlement($bets, 'yes', 0.08, 500);

        // 🔴 Инвариант: выплачено не больше, чем поставлено (рейк + округление сгорают).
        $this->assertLessThanOrEqual($this->sumStakes($bets), $this->sumPayouts($r['payouts']));
        // И не больше распределяемого банка.
        $this->assertLessThanOrEqual($r['distributable'], $this->sumPayouts($r['payouts']));
        $this->assertGreaterThan(0, $r['rake']);
    }

    public function testVoidOnThinPoolFullRefund(): void
    {
        $bets = [
            ['outcome' => 'A', 'stake' => 100],
            ['outcome' => 'B', 'stake' => 100],
        ];
        $r = OracleService::computeSettlement($bets, 'A', 0.08, 500); // pool 200 < minPool 500

        $this->assertTrue($r['void']);
        $this->assertSame(0, $r['rake']);
        $this->assertSame([100, 100], $r['payouts']); // полный возврат
        $this->assertSame($this->sumStakes($bets), $this->sumPayouts($r['payouts']));
    }

    public function testVoidOnOneSidedPool(): void
    {
        $bets = [
            ['outcome' => 'A', 'stake' => 1000],
            ['outcome' => 'A', 'stake' => 2000],
        ];
        $r = OracleService::computeSettlement($bets, 'A', 0.08, 100); // только один исход → нет встречной стороны

        $this->assertTrue($r['void']);
        $this->assertSame([1000, 2000], $r['payouts']);
    }

    public function testVoidOnNullWinner(): void
    {
        $bets = [
            ['outcome' => 'A', 'stake' => 1000],
            ['outcome' => 'B', 'stake' => 1000],
        ];
        $r = OracleService::computeSettlement($bets, null, 0.08, 100); // метрика не разрешилась

        $this->assertTrue($r['void']);
        $this->assertSame([1000, 1000], $r['payouts']);
    }

    public function testVoidWhenWinningOutcomeHasNoBackers(): void
    {
        $bets = [
            ['outcome' => 'A', 'stake' => 1000],
            ['outcome' => 'B', 'stake' => 1000],
        ];
        $r = OracleService::computeSettlement($bets, 'C', 0.08, 100); // никто не ставил на победивший C

        $this->assertTrue($r['void']);
        $this->assertSame([1000, 1000], $r['payouts']);
    }

    public function testEmptyBetsAreVoid(): void
    {
        $r = OracleService::computeSettlement([], 'A', 0.08, 0);
        $this->assertTrue($r['void']);
        $this->assertSame([], $r['payouts']);
        $this->assertSame(0, $r['pool']);
    }

    /**
     * Property-чек инварианта на наборе разнородных сценариев.
     */
    public function testInvariantHoldsAcrossScenarios(): void
    {
        $scenarios = [
            [[['outcome' => 'A', 'stake' => 50], ['outcome' => 'B', 'stake' => 50], ['outcome' => 'A', 'stake' => 999]], 'A'],
            [[['outcome' => 'A', 'stake' => 777], ['outcome' => 'B', 'stake' => 333], ['outcome' => 'C', 'stake' => 12345]], 'C'],
            [[['outcome' => 'yes', 'stake' => 1], ['outcome' => 'no', 'stake' => 1000000]], 'yes'],
            [[['outcome' => 'A', 'stake' => 3], ['outcome' => 'B', 'stake' => 7], ['outcome' => 'C', 'stake' => 11], ['outcome' => 'D', 'stake' => 13]], 'B'],
        ];

        foreach ($scenarios as [$bets, $winner]) {
            foreach ([0.0, 0.05, 0.08, 0.25] as $rake) {
                $r = OracleService::computeSettlement($bets, $winner, $rake, 500);
                $this->assertLessThanOrEqual(
                    $this->sumStakes($bets),
                    $this->sumPayouts($r['payouts']),
                    "Σвыплат превысила Σставок (rake={$rake}) — это фонтан, недопустимо",
                );
            }
        }
    }
}
