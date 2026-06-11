<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\BuildingEffects\BuildingEffectsService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * E18 (ADR-118) — BuildingEffectsService::effectAtLevel: level-aware множитель эффекта для витрины
 * «Развитие базы». Интерполяция L4-L9 между L3 и L10 (ADR-042). Инъекция tunableReader → без DB.
 *
 * @internal
 */
final class BuildingEffectAtLevelTest extends CIUnitTestCase
{
    private function svc(): BuildingEffectsService
    {
        $reader = static function (string $k, mixed $d): mixed {
            $map = [
                'building.workshop.l3.craft_time_multiplier'  => 0.75,
                'building.workshop.l10.craft_time_multiplier' => 0.55,
            ];
            return $map[$k] ?? $d;
        };
        return new BuildingEffectsService(null, null, $reader);
    }

    public function testLevelBelowTwoNoEffect(): void
    {
        $this->assertSame(1.0, $this->svc()->effectAtLevel('workshop', 1, 'craft_time_multiplier'));
        $this->assertSame(1.0, $this->svc()->effectAtLevel('workshop', 0, 'craft_time_multiplier'));
    }

    public function testAnchorLevels(): void
    {
        $svc = $this->svc();
        $this->assertEqualsWithDelta(0.75, $svc->effectAtLevel('workshop', 3, 'craft_time_multiplier'), 0.0001);
        $this->assertEqualsWithDelta(0.55, $svc->effectAtLevel('workshop', 10, 'craft_time_multiplier'), 0.0001);
    }

    public function testInterpolatedMidLevels(): void
    {
        $svc = $this->svc();
        // L5 = 0.75 + (0.55-0.75)*(5-3)/7 = 0.75 - 0.05714 = 0.69286
        $this->assertEqualsWithDelta(0.69286, $svc->effectAtLevel('workshop', 5, 'craft_time_multiplier'), 0.0001);
        // L8 = 0.75 + (0.55-0.75)*(8-3)/7 = 0.75 - 0.14286 = 0.60714
        $this->assertEqualsWithDelta(0.60714, $svc->effectAtLevel('workshop', 8, 'craft_time_multiplier'), 0.0001);
        // Монотонность: L5 > L8 > L10 (для reduce-параметра меньше = сильнее).
        $this->assertGreaterThan($svc->effectAtLevel('workshop', 8, 'craft_time_multiplier'), $svc->effectAtLevel('workshop', 5, 'craft_time_multiplier'));
    }

    public function testUnknownParamFallsToOne(): void
    {
        $this->assertSame(1.0, $this->svc()->effectAtLevel('workshop', 5, 'nonexistent_param'));
    }
}
