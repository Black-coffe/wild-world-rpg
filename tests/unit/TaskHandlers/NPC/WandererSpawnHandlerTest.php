<?php

declare(strict_types=1);

namespace Tests\Unit\TaskHandlers\NPC;

use App\TaskHandlers\NPC\WandererSpawnHandler;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-099 — распределение спавна между фракционным и нейтральным под-пулами (чистая pickFaction).
 *
 * @internal
 */
final class WandererSpawnHandlerTest extends CIUnitTestCase
{
    public function testNoFactionPoolAlwaysNeutral(): void
    {
        // Нет фракционных шаблонов → всегда нейтрал, какой бы ни был ratio/roll.
        $this->assertFalse(WandererSpawnHandler::pickFaction(0, 100, false, true));
        $this->assertFalse(WandererSpawnHandler::pickFaction(99, 100, false, true));
    }

    public function testNoNeutralPoolAlwaysFaction(): void
    {
        // Нет нейтральных шаблонов → всегда фракционный (даже при ratio 0).
        $this->assertTrue(WandererSpawnHandler::pickFaction(0, 0, true, false));
        $this->assertTrue(WandererSpawnHandler::pickFaction(99, 0, true, false));
    }

    public function testRatioZeroIsDormant(): void
    {
        // ratio=0 (dormant) → фракционные не выбираются ни при каком roll.
        $this->assertFalse(WandererSpawnHandler::pickFaction(0, 0, true, true));
        $this->assertFalse(WandererSpawnHandler::pickFaction(50, 0, true, true));
        $this->assertFalse(WandererSpawnHandler::pickFaction(99, 0, true, true));
    }

    public function testRatioHundredAlwaysFaction(): void
    {
        // ratio=100 → любой roll ∈ [0,99] < 100 → фракционный.
        $this->assertTrue(WandererSpawnHandler::pickFaction(0, 100, true, true));
        $this->assertTrue(WandererSpawnHandler::pickFaction(99, 100, true, true));
    }

    public function testRatioBoundaryRollLessThanRatio(): void
    {
        // ratio=40 → roll<40 фракционный, roll>=40 нейтрал.
        $this->assertTrue(WandererSpawnHandler::pickFaction(0, 40, true, true));
        $this->assertTrue(WandererSpawnHandler::pickFaction(39, 40, true, true));
        $this->assertFalse(WandererSpawnHandler::pickFaction(40, 40, true, true));
        $this->assertFalse(WandererSpawnHandler::pickFaction(99, 40, true, true));
    }

    public function testRatioClampedToValidRange(): void
    {
        // Защита от выхода за [0,100]: ниже 0 → как 0 (нейтрал), выше 100 → как 100 (фракционный).
        $this->assertFalse(WandererSpawnHandler::pickFaction(0, -20, true, true));
        $this->assertTrue(WandererSpawnHandler::pickFaction(99, 200, true, true));
    }
}
