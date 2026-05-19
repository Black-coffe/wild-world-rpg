<?php

namespace Tests\Unit\Services\Player;

use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Services\BuildingEffects\BuildingEffectsService;
use App\Services\Player\TeleportCostService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * S15 (v0.51.197) — тести TeleportCostService с урахуванням
 * TeleportationCenter level multiplier.
 *
 * Backward-compat: без $charId формула = baseline `1000 × level` (без discount).
 * Caller pattern: TeleportUseValidator/TeleportAction передають $charId.
 *
 * @internal
 */
final class TeleportCostServiceTest extends CIUnitTestCase
{
    /**
     * @param array<int, array<string,mixed>> $buildings
     * @param array<int, array<string,mixed>> $charBuildings
     * @param array<string, float>            $overrides
     */
    private function buildService(
        array $buildings,
        array $charBuildings,
        array $overrides,
    ): TeleportCostService {
        $buildingModel = new class ($buildings) extends BuildingModel {
            /** @var array<int, array<string,mixed>> */
            private array $rows;
            /** @var array<string, mixed> */
            private array $filters = [];

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
                $this->orderBy  = $orderBy;
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

        $reader = static fn (string $key, mixed $default): mixed
            => $overrides[$key] ?? $default;

        $effects = new BuildingEffectsService($charBuildingModel, $buildingModel, $reader);
        return new TeleportCostService($effects);
    }

    public function testBaselineCostWithoutCharIdFollowsThousandPerLevel(): void
    {
        $svc = $this->buildService([], [], []);
        // Backward-compat: без $charId — pure baseline формула, без discount.
        $this->assertSame(1000, $svc->calculateTeleportCost(1));
        $this->assertSame(10000, $svc->calculateTeleportCost(10));
        $this->assertSame(25000, $svc->calculateTeleportCost(25));
    }

    public function testCostWithoutTeleportCenterReturnsBaseline(): void
    {
        // Char має id, але немає TC → multiplier=1.0, baseline формула.
        $svc = $this->buildService(
            [['id' => 13, 'name_en' => 'TeleportationCenter']],
            [],
            ['building.teleportationcenter.l2.teleport_cost_multiplier' => 0.85],
        );
        $this->assertSame(10000, $svc->calculateTeleportCost(10, 491));
    }

    public function testCostWithTcL1ReturnsBaseline(): void
    {
        // L1 = baseline (multiplier 1.0).
        $svc = $this->buildService(
            [['id' => 13, 'name_en' => 'TeleportationCenter']],
            [['character_id' => 491, 'building_id' => 13, 'level' => 1]],
            ['building.teleportationcenter.l2.teleport_cost_multiplier' => 0.85],
        );
        $this->assertSame(10000, $svc->calculateTeleportCost(10, 491));
    }

    public function testCostWithTcL2AppliesFifteenPercentDiscount(): void
    {
        // Char level 10 + TC L2 → 10000 × 0.85 = 8500.
        $svc = $this->buildService(
            [['id' => 13, 'name_en' => 'TeleportationCenter']],
            [['character_id' => 491, 'building_id' => 13, 'level' => 2]],
            ['building.teleportationcenter.l2.teleport_cost_multiplier' => 0.85],
        );
        $this->assertSame(8500, $svc->calculateTeleportCost(10, 491));
    }

    public function testCostWithTcL3AppliesThirtyPercentDiscount(): void
    {
        // Char level 20 + TC L3 → 20000 × 0.70 = 14000 (full ROADMAP example).
        $svc = $this->buildService(
            [['id' => 13, 'name_en' => 'TeleportationCenter']],
            [['character_id' => 491, 'building_id' => 13, 'level' => 3]],
            [
                'building.teleportationcenter.l2.teleport_cost_multiplier' => 0.85,
                'building.teleportationcenter.l3.teleport_cost_multiplier' => 0.70,
            ],
        );
        $this->assertSame(14000, $svc->calculateTeleportCost(20, 491));
    }

    public function testCostMinClampOne(): void
    {
        // Edge case: level=0 char (theoretical) з extreme multiplier → clamp >= 1.
        $svc = $this->buildService(
            [['id' => 13, 'name_en' => 'TeleportationCenter']],
            [['character_id' => 491, 'building_id' => 13, 'level' => 3]],
            ['building.teleportationcenter.l3.teleport_cost_multiplier' => 0.10],
        );
        // 1000 × 0 × 0.10 = 0 → clamp до 1.
        $this->assertSame(1, $svc->calculateTeleportCost(0, 491));
    }
}
