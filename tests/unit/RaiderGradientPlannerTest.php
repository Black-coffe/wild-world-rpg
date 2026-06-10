<?php

use App\TaskHandlers\NPC\RaiderGradientHandler;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-105 — чистые планировщики градиента рейдеров «север = опаснее».
 *
 * bandTargets: целевая популяция по Y-бэндам (вес линейно растёт к северу,
 * частичный последний бэнд масштабируется долей строк, остаток — северу).
 * planAdjustments: наливы север→юг, срезы юг→север, бюджет per-tick соблюдён.
 *
 * @internal
 */
final class RaiderGradientPlannerTest extends CIUnitTestCase
{
    public function testBandTargetsSumEqualsTotal(): void
    {
        $targets = RaiderGradientHandler::bandTargets(7000, 850);

        $this->assertSame(7000, array_sum($targets));
        $this->assertCount(9, $targets); // Y0..849 = 8 полных бэндов + частичный 800-849
    }

    public function testBandTargetsNorthHeavy(): void
    {
        $targets = RaiderGradientHandler::bandTargets(7000, 850);

        // Север (Y0-99) плотнее юга (Y800-849) и монотонно не возрастает к югу.
        $this->assertGreaterThan($targets[800], $targets[0]);
        $values = array_values($targets);
        for ($i = 1, $n = count($values); $i < $n; $i++) {
            $this->assertLessThanOrEqual($values[$i - 1], $values[$i]);
        }
    }

    public function testBandTargetsPartialLastBandScaledByRows(): void
    {
        // yMax=850: последний бэнд 800-849 = 50 строк → вес 1×0.5; плотность НА СТРОКУ
        // не должна превышать соседний полный бэнд 700-799 (вес 2×1.0).
        $targets = RaiderGradientHandler::bandTargets(4450, 850);

        $perRowLast   = $targets[800] / 50;
        $perRowSeventh = $targets[700] / 100;
        $this->assertLessThanOrEqual($perRowSeventh + 0.5, $perRowLast);
    }

    public function testBandTargetsEdgeCases(): void
    {
        $this->assertSame([], RaiderGradientHandler::bandTargets(0, 850));
        $this->assertSame([], RaiderGradientHandler::bandTargets(100, 0));

        // Маленькая зона: один бэнд забирает всё.
        $this->assertSame([0 => 42], RaiderGradientHandler::bandTargets(42, 100));
    }

    public function testBandTargetsTinyTotalGoesNorthFirst(): void
    {
        // total меньше числа бэндов — остаток раздаётся с севера.
        $targets = RaiderGradientHandler::bandTargets(3, 850);

        $this->assertSame(3, array_sum($targets));
        $this->assertSame(1, $targets[0]);
        $this->assertSame(0, $targets[800]);
    }

    public function testPlanFillsNorthFirstThenTrimsSouthFirst(): void
    {
        // Текущее: юг переполнен, север пуст (прод-картина легаси-посева).
        $counts  = [0 => 0, 100 => 0, 200 => 500];
        $targets = [0 => 300, 100 => 150, 200 => 50];

        $plan = RaiderGradientHandler::planAdjustments($counts, $targets, 10000);

        $this->assertSame(['band' => 0, 'action' => 'fill', 'count' => 300], $plan[0]);
        $this->assertSame(['band' => 100, 'action' => 'fill', 'count' => 150], $plan[1]);
        $this->assertSame(['band' => 200, 'action' => 'trim', 'count' => 450], $plan[2]);
    }

    public function testPlanRespectsBudget(): void
    {
        $counts  = [0 => 0, 100 => 900];
        $targets = [0 => 500, 100 => 100];

        $plan = RaiderGradientHandler::planAdjustments($counts, $targets, 200);

        // Бюджет 200 съеден наливом севера; до среза юга очередь не дошла.
        $this->assertSame([['band' => 0, 'action' => 'fill', 'count' => 200]], $plan);

        $total = 0;
        foreach ($plan as $step) {
            $total += $step['count'];
        }
        $this->assertLessThanOrEqual(200, $total);
    }

    public function testPlanEmptyWhenBalanced(): void
    {
        $counts  = [0 => 300, 100 => 150];
        $targets = [0 => 300, 100 => 150];

        $this->assertSame([], RaiderGradientHandler::planAdjustments($counts, $targets, 200));
    }

    public function testPlanMissingCountTreatedAsZero(): void
    {
        $plan = RaiderGradientHandler::planAdjustments([], [0 => 5], 200);

        $this->assertSame([['band' => 0, 'action' => 'fill', 'count' => 5]], $plan);
    }

    public function testBossSingletonTargetsDeepestNorthBand(): void
    {
        // ADR-106: population_each=1, y_max=150 → единственный экземпляр всегда в Y0-99
        // (остаток от округления раздаётся с севера).
        $targets = RaiderGradientHandler::bandTargets(1, 150);

        $this->assertSame([0 => 1, 100 => 0], $targets);
    }

    public function testBossKilledTriggersSingleFill(): void
    {
        // Босс убит (0 живых) → план = один налив в северный бэнд (возрождение в новой клетке).
        $plan = RaiderGradientHandler::planAdjustments([0 => 0, 100 => 0], [0 => 1, 100 => 0], 200);

        $this->assertSame([['band' => 0, 'action' => 'fill', 'count' => 1]], $plan);
    }

    public function testBossAliveIsSteadyState(): void
    {
        $this->assertSame([], RaiderGradientHandler::planAdjustments([0 => 1, 100 => 0], [0 => 1, 100 => 0], 200));
    }
}
