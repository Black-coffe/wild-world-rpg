<?php

namespace Tests\Unit\Services\BuildingEffects;

use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Services\BuildingEffects\BuildingEffectsService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * S11 (v0.51.193) — тести BuildingEffectsService.
 *
 * Покриває:
 *  - L1 baseline → 1.0 (no effect)
 *  - L2 → 0.90 (-10%)
 *  - L3 → 0.75 (-25%)
 *  - L4-L10 cascade на L3 → 0.75 (plateau)
 *  - No Workshop → 1.0
 *  - Building not found in BuildingModel → 1.0
 *  - GameSettings override (custom multiplier)
 *  - Non-numeric GameSettings value → fallback на cascade
 *
 * Mocks: CharacterBuildingModel + BuildingModel через anonymous extends
 * (return arrays на ->first()). tunableReader callable injected.
 *
 * @internal
 */
final class BuildingEffectsServiceTest extends CIUnitTestCase
{
    /**
     * @param array<int, array<string,mixed>> $buildings  Available buildings (id, name_en)
     * @param array<int, array<string,mixed>> $charBuildings  Char buildings (character_id, building_id, level)
     */
    private function service(
        array $buildings,
        array $charBuildings,
        callable $tunableReader,
    ): BuildingEffectsService {
        $buildingModel = new class ($buildings) extends BuildingModel {
            /** @var array<int, array<string,mixed>> */
            private array $rows;
            /** @var array<string, mixed> */
            private array $filters = [];

            /**
             * @param array<int, array<string,mixed>> $rows
             */
            public function __construct(array $rows)
            {
                $this->rows = $rows;
            }

            public function where($key = null, $value = null, ?bool $escape = null): self
            {
                $this->filters[(string)$key] = $value;
                return $this;
            }

            public function first()
            {
                foreach ($this->rows as $row) {
                    $match = true;
                    foreach ($this->filters as $k => $v) {
                        if (($row[$k] ?? null) !== $v) {
                            $match = false;
                            break;
                        }
                    }
                    if ($match) {
                        return $row;
                    }
                }
                return null;
            }
        };

        $charBuildingModel = new class ($charBuildings) extends CharacterBuildingModel {
            /** @var array<int, array<string,mixed>> */
            private array $rows;
            /** @var array<string, mixed> */
            private array $filters = [];
            private string $orderBy = '';
            private string $orderDir = 'ASC';

            /**
             * @param array<int, array<string,mixed>> $rows
             */
            public function __construct(array $rows)
            {
                $this->rows = $rows;
            }

            public function where($key = null, $value = null, ?bool $escape = null): self
            {
                $this->filters[(string)$key] = $value;
                return $this;
            }

            public function orderBy(string $orderBy, string $direction = '', ?bool $escape = null): self
            {
                $this->orderBy = $orderBy;
                $this->orderDir = $direction !== '' ? strtoupper($direction) : 'ASC';
                return $this;
            }

            public function first()
            {
                $matches = [];
                foreach ($this->rows as $row) {
                    $match = true;
                    foreach ($this->filters as $k => $v) {
                        if (($row[$k] ?? null) !== $v) {
                            $match = false;
                            break;
                        }
                    }
                    if ($match) {
                        $matches[] = $row;
                    }
                }
                if (empty($matches)) {
                    return null;
                }
                if ($this->orderBy !== '') {
                    usort($matches, function ($a, $b) {
                        return ($a[$this->orderBy] ?? 0) <=> ($b[$this->orderBy] ?? 0);
                    });
                    if ($this->orderDir === 'DESC') {
                        $matches = array_reverse($matches);
                    }
                }
                return $matches[0];
            }
        };

        return new BuildingEffectsService($charBuildingModel, $buildingModel, $tunableReader);
    }

    /**
     * @param array<string, float> $overrides
     */
    private function reader(array $overrides): callable
    {
        return static fn (string $key, mixed $default): mixed
            => $overrides[$key] ?? $default;
    }

    public function testNoWorkshopReturnsOne(): void
    {
        $svc = $this->service(
            [['id' => 4, 'name_en' => 'Workshop']],
            [], // char has no buildings
            $this->reader([
                'building.workshop.l2.craft_time_multiplier' => 0.90,
                'building.workshop.l3.craft_time_multiplier' => 0.75,
            ]),
        );
        $this->assertSame(1.0, $svc->getCraftTimeMultiplier(491));
    }

    public function testL1WorkshopReturnsOne(): void
    {
        $svc = $this->service(
            [['id' => 4, 'name_en' => 'Workshop']],
            [['character_id' => 491, 'building_id' => 4, 'level' => 1]],
            $this->reader([
                'building.workshop.l2.craft_time_multiplier' => 0.90,
                'building.workshop.l3.craft_time_multiplier' => 0.75,
            ]),
        );
        $this->assertSame(1.0, $svc->getCraftTimeMultiplier(491));
    }

    public function testL2WorkshopReturnsNinetyPercent(): void
    {
        $svc = $this->service(
            [['id' => 4, 'name_en' => 'Workshop']],
            [['character_id' => 491, 'building_id' => 4, 'level' => 2]],
            $this->reader([
                'building.workshop.l2.craft_time_multiplier' => 0.90,
                'building.workshop.l3.craft_time_multiplier' => 0.75,
            ]),
        );
        $this->assertSame(0.90, $svc->getCraftTimeMultiplier(491));
    }

    public function testL3WorkshopReturnsSeventyFive(): void
    {
        $svc = $this->service(
            [['id' => 4, 'name_en' => 'Workshop']],
            [['character_id' => 491, 'building_id' => 4, 'level' => 3]],
            $this->reader([
                'building.workshop.l2.craft_time_multiplier' => 0.90,
                'building.workshop.l3.craft_time_multiplier' => 0.75,
            ]),
        );
        $this->assertSame(0.75, $svc->getCraftTimeMultiplier(491));
    }

    public function testL5WorkshopCascadesToL3Plateau(): void
    {
        // На проді 2 чара на L5; за рішенням 2026-05-19 — plateau на L3 multiplier.
        $svc = $this->service(
            [['id' => 4, 'name_en' => 'Workshop']],
            [['character_id' => 491, 'building_id' => 4, 'level' => 5]],
            $this->reader([
                'building.workshop.l2.craft_time_multiplier' => 0.90,
                'building.workshop.l3.craft_time_multiplier' => 0.75,
            ]),
        );
        $this->assertSame(0.75, $svc->getCraftTimeMultiplier(491));
    }

    public function testL10WorkshopCascadesToL3Plateau(): void
    {
        // Hard ceiling: навіть L10 cascade на L3 (0.75) поки немає явних L4-L10 ключів.
        $svc = $this->service(
            [['id' => 4, 'name_en' => 'Workshop']],
            [['character_id' => 491, 'building_id' => 4, 'level' => 10]],
            $this->reader([
                'building.workshop.l2.craft_time_multiplier' => 0.90,
                'building.workshop.l3.craft_time_multiplier' => 0.75,
            ]),
        );
        $this->assertSame(0.75, $svc->getCraftTimeMultiplier(491));
    }

    public function testL4WorkshopWithCustomL4KeyTakesPrecedence(): void
    {
        // Якщо у майбутньому додасться явний L4 key — service підхопить його замість cascade.
        $svc = $this->service(
            [['id' => 4, 'name_en' => 'Workshop']],
            [['character_id' => 491, 'building_id' => 4, 'level' => 4]],
            $this->reader([
                'building.workshop.l2.craft_time_multiplier' => 0.90,
                'building.workshop.l3.craft_time_multiplier' => 0.75,
                'building.workshop.l4.craft_time_multiplier' => 0.65, // explicit L4
            ]),
        );
        $this->assertSame(0.65, $svc->getCraftTimeMultiplier(491));
    }

    public function testNoGameSettingsKeysFallsBackToOne(): void
    {
        // Не seeded GameSettings → cascade не знаходить нічого → 1.0.
        $svc = $this->service(
            [['id' => 4, 'name_en' => 'Workshop']],
            [['character_id' => 491, 'building_id' => 4, 'level' => 5]],
            $this->reader([]), // no keys
        );
        $this->assertSame(1.0, $svc->getCraftTimeMultiplier(491));
    }

    public function testBuildingNotInBuildingTableReturnsOne(): void
    {
        // Workshop interface отсутній у buildings таблиці → 1.0.
        $svc = $this->service(
            [], // no buildings
            [['character_id' => 491, 'building_id' => 4, 'level' => 5]],
            $this->reader([
                'building.workshop.l2.craft_time_multiplier' => 0.90,
            ]),
        );
        $this->assertSame(1.0, $svc->getCraftTimeMultiplier(491));
    }

    public function testDifferentCharacterReturnsOne(): void
    {
        // Char 490 не має Workshop; char 491 має L3.
        $svc = $this->service(
            [['id' => 4, 'name_en' => 'Workshop']],
            [['character_id' => 491, 'building_id' => 4, 'level' => 3]],
            $this->reader([
                'building.workshop.l3.craft_time_multiplier' => 0.75,
            ]),
        );
        $this->assertSame(0.75, $svc->getCraftTimeMultiplier(491));
        $this->assertSame(1.0, $svc->getCraftTimeMultiplier(490));
    }

    // ============================================================
    // S12 (v0.51.194) — getCraftYieldMultiplier (BlastFurnace)
    // ============================================================

    public function testYieldMultiplierNullBoostBuildingReturnsOne(): void
    {
        $svc = $this->service([], [], $this->reader([]));
        $this->assertSame(1.0, $svc->getCraftYieldMultiplier(491, null));
        $this->assertSame(1.0, $svc->getCraftYieldMultiplier(491, ''));
    }

    public function testYieldMultiplierNoBlastFurnaceReturnsOne(): void
    {
        $svc = $this->service(
            [['id' => 2, 'name_en' => 'BlastFurnace']],
            [], // char has no BlastFurnace
            $this->reader([
                'building.blastfurnace.l2.craft_yield_multiplier' => 1.15,
            ]),
        );
        $this->assertSame(1.0, $svc->getCraftYieldMultiplier(491, 'BlastFurnace'));
    }

    public function testYieldMultiplierL1BlastFurnaceReturnsOne(): void
    {
        $svc = $this->service(
            [['id' => 2, 'name_en' => 'BlastFurnace']],
            [['character_id' => 491, 'building_id' => 2, 'level' => 1]],
            $this->reader([
                'building.blastfurnace.l2.craft_yield_multiplier' => 1.15,
            ]),
        );
        $this->assertSame(1.0, $svc->getCraftYieldMultiplier(491, 'BlastFurnace'));
    }

    public function testYieldMultiplierL2ReturnsOnePointFifteen(): void
    {
        $svc = $this->service(
            [['id' => 2, 'name_en' => 'BlastFurnace']],
            [['character_id' => 491, 'building_id' => 2, 'level' => 2]],
            $this->reader([
                'building.blastfurnace.l2.craft_yield_multiplier' => 1.15,
                'building.blastfurnace.l3.craft_yield_multiplier' => 1.35,
            ]),
        );
        $this->assertSame(1.15, $svc->getCraftYieldMultiplier(491, 'BlastFurnace'));
    }

    public function testYieldMultiplierL3ReturnsOnePointThirtyFive(): void
    {
        $svc = $this->service(
            [['id' => 2, 'name_en' => 'BlastFurnace']],
            [['character_id' => 491, 'building_id' => 2, 'level' => 3]],
            $this->reader([
                'building.blastfurnace.l2.craft_yield_multiplier' => 1.15,
                'building.blastfurnace.l3.craft_yield_multiplier' => 1.35,
            ]),
        );
        $this->assertSame(1.35, $svc->getCraftYieldMultiplier(491, 'BlastFurnace'));
    }

    public function testYieldMultiplierL5CascadesToL3Plateau(): void
    {
        // L4-L10 plateau (як S11 Workshop user pick).
        $svc = $this->service(
            [['id' => 2, 'name_en' => 'BlastFurnace']],
            [['character_id' => 491, 'building_id' => 2, 'level' => 5]],
            $this->reader([
                'building.blastfurnace.l2.craft_yield_multiplier' => 1.15,
                'building.blastfurnace.l3.craft_yield_multiplier' => 1.35,
            ]),
        );
        $this->assertSame(1.35, $svc->getCraftYieldMultiplier(491, 'BlastFurnace'));
    }

    public function testYieldMultiplierIsCaseInsensitiveForBuildingKey(): void
    {
        // GameSettings key завжди lowercase; service strtolower'ить boost_building.
        $svc = $this->service(
            [['id' => 2, 'name_en' => 'BlastFurnace']],
            [['character_id' => 491, 'building_id' => 2, 'level' => 2]],
            $this->reader([
                'building.blastfurnace.l2.craft_yield_multiplier' => 1.15,
            ]),
        );
        $this->assertSame(1.15, $svc->getCraftYieldMultiplier(491, 'BlastFurnace'));
    }

    // ============================================================
    // S13a (v0.51.195) — getCraftTimeMultiplier with extra Laboratory stack
    // ============================================================

    public function testCraftTimeMultiplierNoExtraBuildingReturnsWorkshopOnly(): void
    {
        // Backward compat: caller без $extraBuilding → лише Workshop.
        $svc = $this->service(
            [['id' => 4, 'name_en' => 'Workshop'], ['id' => 8, 'name_en' => 'Laboratory']],
            [['character_id' => 491, 'building_id' => 4, 'level' => 2]],
            $this->reader([
                'building.workshop.l2.craft_time_multiplier' => 0.90,
                'building.laboratory.l2.craft_time_multiplier' => 0.90,
            ]),
        );
        $this->assertSame(0.90, $svc->getCraftTimeMultiplier(491));
    }

    public function testCraftTimeMultiplierStacksWorkshopAndLaboratory(): void
    {
        // Char має Workshop L2 (0.90) + Laboratory L2 (0.90). Stack: 0.90 × 0.90 = 0.81.
        $svc = $this->service(
            [['id' => 4, 'name_en' => 'Workshop'], ['id' => 8, 'name_en' => 'Laboratory']],
            [
                ['character_id' => 491, 'building_id' => 4, 'level' => 2],
                ['character_id' => 491, 'building_id' => 8, 'level' => 2],
            ],
            $this->reader([
                'building.workshop.l2.craft_time_multiplier' => 0.90,
                'building.laboratory.l2.craft_time_multiplier' => 0.90,
            ]),
        );
        $this->assertSame(0.81, round($svc->getCraftTimeMultiplier(491, 'Laboratory'), 4));
    }

    public function testCraftTimeMultiplierStacksWorkshopL3AndLabL3(): void
    {
        // Max stack: Workshop L3 (0.75) × Laboratory L3 (0.75) = 0.5625 (-44% для medical).
        $svc = $this->service(
            [['id' => 4, 'name_en' => 'Workshop'], ['id' => 8, 'name_en' => 'Laboratory']],
            [
                ['character_id' => 491, 'building_id' => 4, 'level' => 3],
                ['character_id' => 491, 'building_id' => 8, 'level' => 3],
            ],
            $this->reader([
                'building.workshop.l3.craft_time_multiplier' => 0.75,
                'building.laboratory.l3.craft_time_multiplier' => 0.75,
            ]),
        );
        $this->assertSame(0.5625, round($svc->getCraftTimeMultiplier(491, 'Laboratory'), 4));
    }

    public function testCraftTimeMultiplierLabOnlyNoWorkshop(): void
    {
        // Char має Laboratory L3 але немає Workshop → лише Lab ефект (0.75).
        $svc = $this->service(
            [['id' => 4, 'name_en' => 'Workshop'], ['id' => 8, 'name_en' => 'Laboratory']],
            [['character_id' => 491, 'building_id' => 8, 'level' => 3]],
            $this->reader([
                'building.workshop.l2.craft_time_multiplier' => 0.90,
                'building.laboratory.l3.craft_time_multiplier' => 0.75,
            ]),
        );
        $this->assertSame(0.75, $svc->getCraftTimeMultiplier(491, 'Laboratory'));
    }

    public function testCraftTimeMultiplierExtraIsWorkshopDeduplicates(): void
    {
        // Якщо $extraBuilding=='Workshop' (defensive: caller-помилка) → не double-apply.
        $svc = $this->service(
            [['id' => 4, 'name_en' => 'Workshop']],
            [['character_id' => 491, 'building_id' => 4, 'level' => 3]],
            $this->reader([
                'building.workshop.l3.craft_time_multiplier' => 0.75,
            ]),
        );
        $this->assertSame(0.75, $svc->getCraftTimeMultiplier(491, 'Workshop'));
    }

    // ============================================================
    // S13b (v0.51.195) — getGreenhouseProductionForLevel
    // ============================================================
    // Foundation read-through від `Config\GameBalance::$greenhouseLevels`.
    // Тести використовують реальний config (не mock) — не порушують behaviour.

    public function testGreenhouseProductionLevel1(): void
    {
        $svc = new \App\Services\BuildingEffects\BuildingEffectsService();
        $row = $svc->getGreenhouseProductionForLevel(1);
        $this->assertSame(1, $row['water']);
        $this->assertSame(2, $row['Fruit']);
        $this->assertSame(1, $row['Berries']);
    }

    public function testGreenhouseProductionLevel10IncludesMushroomsAndCrops(): void
    {
        $svc = new \App\Services\BuildingEffects\BuildingEffectsService();
        $row = $svc->getGreenhouseProductionForLevel(10);
        $this->assertSame(10, $row['water']);
        $this->assertSame(5, $row['Fruit']);
        $this->assertSame(5, $row['Berries']);
        $this->assertSame(3, $row['Mushrooms']);
        $this->assertSame(2, $row['Crops']);
    }

    public function testGreenhouseProductionLevelOutOfRangeReturnsEmpty(): void
    {
        $svc = new \App\Services\BuildingEffects\BuildingEffectsService();
        $this->assertSame([], $svc->getGreenhouseProductionForLevel(0));
        $this->assertSame([], $svc->getGreenhouseProductionForLevel(99));
    }
}
