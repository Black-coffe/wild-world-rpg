<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers\Telegram\Commands\Actions;

use App\Controllers\Telegram\Commands\Actions\GatherAction;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Стоимость одного захода в добычу: (1000 - level) / 200 c floor-ом 0.01.
 * Единый источник правды для подсказки на экране и для реального списания
 * (deductTiredness). Тест фиксирует контракт, чтобы цифры не разъехались.
 *
 * @internal
 */
final class GatherActionStaminaCostTest extends CIUnitTestCase
{
    public function testCostDecreasesWithLevel(): void
    {
        $l1   = GatherAction::gatherStaminaCost(1);
        $l100 = GatherAction::gatherStaminaCost(100);
        $l200 = GatherAction::gatherStaminaCost(200);

        $this->assertEqualsWithDelta(4.995, $l1, 0.0001);
        $this->assertEqualsWithDelta(4.5, $l100, 0.0001);
        $this->assertEqualsWithDelta(4.0, $l200, 0.0001);

        // Чем выше уровень — тем дешевле.
        $this->assertGreaterThan($l100, $l1);
        $this->assertGreaterThan($l200, $l100);
    }

    public function testNeverBelowFloor(): void
    {
        // Сверхвысокий уровень не уводит стоимость в 0/отрицательное.
        $this->assertSame(0.01, GatherAction::gatherStaminaCost(999999));
    }

    public function testMatchesPlayerReportedThreshold(): void
    {
        // Игрок-репортер: 4.2 выносливости. На низком уровне порог ~5 → не пускает;
        // ровно на L160 стоимость = 4.2, выше — уже хватает.
        $this->assertGreaterThan(4.2, GatherAction::gatherStaminaCost(1));
        $this->assertEqualsWithDelta(4.2, GatherAction::gatherStaminaCost(160), 0.0001);
        $this->assertLessThan(4.2, GatherAction::gatherStaminaCost(200));
    }
}
