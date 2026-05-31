<?php

namespace Tests\Unit\Services\Player;

use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Services\Player\AutomationGateService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-086 Фаза 1b — тесты AutomationGateService (гейт запуска роботов/телепорта на
 * наличие Солнечной станции).
 *
 * Покрывает:
 *  - killswitch OFF (dormant) → blocksLaunch всегда false (0 эффекта)
 *  - killswitch ON + нет Станции → blocksLaunch true (lock)
 *  - killswitch ON + есть Станция → blocksLaunch false (grandfathered-неважно: владелец)
 *  - ownsSolarStation true/false, Station не в buildings → false
 *  - coercion killswitch (bool / int 1 / string "true" / "0")
 *  - lockMessage содержит путь постройки
 *
 * Mocks: BuildingModel + CharacterBuildingModel (anon extends, ->first()) + reader callable.
 *
 * @internal
 */
final class AutomationGateServiceTest extends CIUnitTestCase
{
    /**
     * @param array<int, array<string,mixed>> $buildings
     * @param array<int, array<string,mixed>> $charBuildings
     * @param array<string, mixed>            $settings
     */
    private function service(array $buildings, array $charBuildings, array $settings): AutomationGateService
    {
        $buildingModel = new class ($buildings) extends BuildingModel {
            /** @var array<int, array<string,mixed>> */
            private array $rows;
            /** @var array<string, mixed> */
            private array $filters = [];

            /** @param array<int, array<string,mixed>> $rows */
            public function __construct(array $rows)
            {
                $this->rows = $rows;
            }

            public function where($key = null, $value = null, ?bool $escape = null): self
            {
                $this->filters[(string) $key] = $value;
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

            /** @param array<int, array<string,mixed>> $rows */
            public function __construct(array $rows)
            {
                $this->rows = $rows;
            }

            public function where($key = null, $value = null, ?bool $escape = null): self
            {
                $this->filters[(string) $key] = $value;
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

        $reader = static fn (string $key, mixed $default): mixed
            => array_key_exists($key, $settings) ? $settings[$key] : $default;

        return new AutomationGateService($buildingModel, $charBuildingModel, $reader);
    }

    private const SOLAR = [['id' => 6, 'name_en' => 'SolarStation']];

    public function testGateDisabledByDefaultIsDormant(): void
    {
        // Нет killswitch-ключа → default false → blocksLaunch false (даже без Станции).
        $svc = $this->service(self::SOLAR, [], []);
        $this->assertFalse($svc->gateEnabled());
        $this->assertFalse($svc->blocksLaunch(491));
    }

    public function testGateOffNeverBlocksEvenWithoutStation(): void
    {
        $svc = $this->service(self::SOLAR, [], ['building.solarstation.gate.enabled' => false]);
        $this->assertFalse($svc->blocksLaunch(491));
    }

    public function testGateOnBlocksWhenNoSolarStation(): void
    {
        $svc = $this->service(self::SOLAR, [], ['building.solarstation.gate.enabled' => true]);
        $this->assertTrue($svc->gateEnabled());
        $this->assertFalse($svc->ownsSolarStation(491));
        $this->assertTrue($svc->blocksLaunch(491));
    }

    public function testGateOnAllowsWhenOwnsSolarStation(): void
    {
        $svc = $this->service(
            self::SOLAR,
            [['character_id' => 491, 'building_id' => 6, 'level' => 1]],
            ['building.solarstation.gate.enabled' => true],
        );
        $this->assertTrue($svc->ownsSolarStation(491));
        $this->assertFalse($svc->blocksLaunch(491));
    }

    public function testOwnsSolarStationFalseForDifferentChar(): void
    {
        $svc = $this->service(
            self::SOLAR,
            [['character_id' => 491, 'building_id' => 6, 'level' => 2]],
            ['building.solarstation.gate.enabled' => true],
        );
        $this->assertTrue($svc->ownsSolarStation(491));
        $this->assertFalse($svc->ownsSolarStation(490));
        $this->assertTrue($svc->blocksLaunch(490));
    }

    public function testStationNotInBuildingsTableNotOwned(): void
    {
        $svc = $this->service([], [['character_id' => 491, 'building_id' => 6]], ['building.solarstation.gate.enabled' => true]);
        $this->assertFalse($svc->ownsSolarStation(491));
        $this->assertTrue($svc->blocksLaunch(491)); // gate ON + не определить владение → блок
    }

    public function testKillswitchCoercionIntOne(): void
    {
        $svc = $this->service(self::SOLAR, [], ['building.solarstation.gate.enabled' => 1]);
        $this->assertTrue($svc->gateEnabled());
    }

    public function testKillswitchCoercionStringTrue(): void
    {
        $svc = $this->service(self::SOLAR, [], ['building.solarstation.gate.enabled' => 'true']);
        $this->assertTrue($svc->gateEnabled());
    }

    public function testKillswitchCoercionStringZeroIsOff(): void
    {
        $svc = $this->service(self::SOLAR, [], ['building.solarstation.gate.enabled' => '0']);
        $this->assertFalse($svc->gateEnabled());
    }

    public function testLockMessageExplainsPrerequisiteAndPath(): void
    {
        $svc = $this->service(self::SOLAR, [], []);
        $msg = $svc->lockMessage();
        $this->assertStringContainsString('Солнечная станция', $msg);
        $this->assertStringContainsString('Строить', $msg); // путь постройки (discoverability)
    }
}
