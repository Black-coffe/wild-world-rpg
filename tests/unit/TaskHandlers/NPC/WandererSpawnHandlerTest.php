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

    // ── ADR-104 Ф3a — диспетчеризация зон (основная + новичковая) ───────────────

    public function testDormantNewbieZoneFillsOnlyMain(): void
    {
        // npc.newbie_zone.population=0 → только основная зона Y401-900 (поведение ADR-089).
        $h = new FakeWandererSpawnHandler();
        $h->newbiePop = 0;
        $h->run();
        $this->assertSame([[401, 900, 25]], $h->zoneCalls);
    }

    public function testNewbieZonePopulationAddsSecondPass(): void
    {
        // population>0 → второй проход для новичковой зоны (Y≥y_min, без верхней границы).
        $h = new FakeWandererSpawnHandler();
        $h->newbiePop = 8;
        $h->newbieYMin = 900;
        $h->run();
        $this->assertSame([[401, 900, 25], [900, null, 8]], $h->zoneCalls);
    }

    public function testKillswitchOffSpawnsNothing(): void
    {
        $h = new FakeWandererSpawnHandler();
        $h->interaction = false;
        $h->newbiePop   = 8;
        $h->run();
        $this->assertSame([], $h->zoneCalls);
    }

    public function testEmptyPoolSpawnsNothing(): void
    {
        $h = new FakeWandererSpawnHandler();
        $h->pools = ['neutral' => [], 'faction' => []];
        $h->run();
        $this->assertSame([], $h->zoneCalls);
    }

    // ── S2/ADR-144 — классификация под-пула (странник ≠ босс) ────────────────────

    public function testClassifyExcludesBossTemplates(): void
    {
        // 🔴 Узлы-боссы (ADR-106 переведены в passive) НЕ должны попадать в пул блукачей.
        $boss = ['ai_behavior' => 'passive', 'npc_type' => 'hostile', 'is_boss' => 1];
        $this->assertNull(WandererSpawnHandler::classifyPoolRow($boss), 'босс is_boss=1 → исключён');

        // Даже neutral-тип, но is_boss=1 → всё равно исключён (инвариант по is_boss).
        $neutralBoss = ['ai_behavior' => 'passive', 'npc_type' => 'neutral', 'is_boss' => 1];
        $this->assertNull(WandererSpawnHandler::classifyPoolRow($neutralBoss));
    }

    public function testClassifyExcludesNamed(): void
    {
        $named = ['ai_behavior' => 'passive', 'npc_type' => 'named', 'is_boss' => 0];
        $this->assertNull(WandererSpawnHandler::classifyPoolRow($named), 'именные не масс-спавнятся');
    }

    public function testClassifyExcludesNonPassive(): void
    {
        $aggressive = ['ai_behavior' => 'aggressive', 'npc_type' => 'neutral', 'is_boss' => 0];
        $this->assertNull(WandererSpawnHandler::classifyPoolRow($aggressive));
    }

    public function testClassifyNeutralAndFaction(): void
    {
        $neutral = ['ai_behavior' => 'passive', 'npc_type' => 'neutral', 'is_boss' => 0];
        $faction = ['ai_behavior' => 'passive', 'npc_type' => 'faction', 'is_boss' => 0];
        $this->assertSame('neutral', WandererSpawnHandler::classifyPoolRow($neutral));
        $this->assertSame('faction', WandererSpawnHandler::classifyPoolRow($faction));
    }
}

/**
 * Test-double: подменяет DB/GameSettings-seam'ы, записывает вызовы fillZone как [yMin,yMax,target].
 *
 * @internal
 */
final class FakeWandererSpawnHandler extends WandererSpawnHandler
{
    public bool $interaction = true;
    /** @var array{neutral: list<int>, faction: list<int>} */
    public array $pools = ['neutral' => [1], 'faction' => []];
    public int $mainPop = 25;
    public int $newbiePop = 0;
    public int $newbieYMin = 900;
    /** @var list<array{0:int,1:int|null,2:int}> */
    public array $zoneCalls = [];

    protected function interactionEnabled(): bool
    {
        return $this->interaction;
    }

    protected function loadPools(): array
    {
        return $this->pools;
    }

    protected function targetPopulation(): int
    {
        return $this->mainPop;
    }

    protected function factionRatio(): int
    {
        return 0;
    }

    protected function newbieZonePopulation(): int
    {
        return $this->newbiePop;
    }

    protected function newbieZoneYMin(): int
    {
        return $this->newbieYMin;
    }

    protected function fillZone(array $poolAll, array $neutralIds, array $factionIds, int $ratio, int $target, int $yMin, ?int $yMax): void
    {
        $this->zoneCalls[] = [$yMin, $yMax, $target];
    }
}
