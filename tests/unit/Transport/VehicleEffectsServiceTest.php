<?php

declare(strict_types=1);

namespace Tests\Unit\Transport;

use App\Services\World\VehicleEffectsService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Транспортная система (story transport-02) — unit-тесты ридера профиля машины.
 *
 * Тест-сим: конструктор принимает map-оверрайды (минуя GameSettings) → чистая логика без БД
 * (паттерн ADR-131/138). Главный инвариант: killswitch off, `null`-ключ или неизвестный ключ →
 * всегда нейтральный профиль (byte-identical с пешеходом).
 *
 * @internal
 */
final class VehicleEffectsServiceTest extends CIUnitTestCase
{
    /**
     * @param array<string, mixed> $overrides
     */
    private function svc(array $overrides): VehicleEffectsService
    {
        return new VehicleEffectsService(null, $overrides);
    }

    /**
     * Таблица контракта (plan.md → ## Contracts): key => [explored, unexplored, cold, tired, order, cargo, wear].
     *
     * @return array<string, array{0: int, 1: int, 2: int, 3: float, 4: int, 5: float, 6: int}>
     */
    private function contractTable(): array
    {
        return [
            'cart'       => [4, 4, 4, 0.90, 90, 0.20, 1],
            'mtb'        => [4, 5, 5, 0.80, 90, 0.00, 1],
            'snowmobile' => [4, 4, 5, 1.10, 120, 0.25, 2],
            'draft_cart' => [4, 4, 4, 0.75, 120, 0.33, 1],
            'drone_auto' => [3, 3, 3, 0.75, 90, 0.00, 1],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function activeConfig(): array
    {
        $config = [
            'world.vehicle.enabled'         => true,
            'world.vehicle.cargo.max_share' => 0.33,
            'world.march.cells_per_tick'    => 3,
            'world.march.max_steps_per_order' => 60,
        ];

        foreach ($this->contractTable() as $key => [$explored, $unexplored, $cold, $tired, $order, $cargo, $wear]) {
            $prefix                                      = "world.vehicle.{$key}.";
            $config[$prefix . 'cells_per_tick_explored']   = $explored;
            $config[$prefix . 'cells_per_tick_unexplored'] = $unexplored;
            $config[$prefix . 'cells_per_tick_cold']       = $cold;
            $config[$prefix . 'tired_factor']              = $tired;
            $config[$prefix . 'max_steps_per_order']       = $order;
            $config[$prefix . 'cargo_share']                = $cargo;
            $config[$prefix . 'wear_per_cell']              = $wear;
        }

        return $config;
    }

    // ── нейтраль ────────────────────────────────────────────────────────

    public function testNeutralProfileReadsMarchBase(): void
    {
        $svc = $this->svc(['world.march.cells_per_tick' => 3, 'world.march.max_steps_per_order' => 60]);

        $this->assertSame([
            'key'                 => null,
            'cells_per_tick'      => 3,
            'tired_factor'        => 1.0,
            'max_steps_per_order' => 60,
            'cargo_share'         => 0.0,
            'wear_per_cell'       => 0,
        ], $svc->neutralProfile());
    }

    public function testDisabledIsNeutralElementByElement(): void
    {
        $config          = $this->activeConfig();
        $config['world.vehicle.enabled'] = false;
        $svc             = $this->svc($config);

        $this->assertFalse($svc->isEnabled());
        $this->assertSame($svc->neutralProfile(), $svc->profileFor('mtb', VehicleEffectsService::TERRAIN_UNEXPLORED));
    }

    public function testNullVehicleKeyIsAlwaysNeutralRegardlessOfKillswitch(): void
    {
        $enabled  = $this->svc($this->activeConfig());
        $disabled = $this->svc(array_merge($this->activeConfig(), ['world.vehicle.enabled' => false]));

        $this->assertSame($enabled->neutralProfile(), $enabled->profileFor(null, VehicleEffectsService::TERRAIN_EXPLORED));
        $this->assertSame($disabled->neutralProfile(), $disabled->profileFor(null, VehicleEffectsService::TERRAIN_EXPLORED));
    }

    public function testUnknownVehicleKeyIsNeutralWithoutException(): void
    {
        $svc = $this->svc($this->activeConfig());

        $this->assertSame($svc->neutralProfile(), $svc->profileFor('spaceship', VehicleEffectsService::TERRAIN_EXPLORED));
    }

    // ── табличный тест: пять машин × три местности ────────────────────────

    /**
     * @return iterable<string, array{0: string, 1: string, 2: int, 3: float, 4: int, 5: float, 6: int}>
     */
    public static function vehicleTerrainProvider(): iterable
    {
        $table = [
            'cart'       => [4, 4, 4, 0.90, 90, 0.20, 1],
            'mtb'        => [4, 5, 5, 0.80, 90, 0.00, 1],
            'snowmobile' => [4, 4, 5, 1.10, 120, 0.25, 2],
            'draft_cart' => [4, 4, 4, 0.75, 120, 0.33, 1],
            'drone_auto' => [3, 3, 3, 0.75, 90, 0.00, 1],
        ];
        $terrains = [
            VehicleEffectsService::TERRAIN_EXPLORED   => 0,
            VehicleEffectsService::TERRAIN_UNEXPLORED => 1,
            VehicleEffectsService::TERRAIN_COLD       => 2,
        ];

        foreach ($table as $key => [$explored, $unexplored, $cold, $tired, $order, $cargo, $wear]) {
            $cells = [$explored, $unexplored, $cold];
            foreach ($terrains as $terrain => $idx) {
                yield "{$key}/{$terrain}" => [$key, $terrain, $cells[$idx], $tired, $order, $cargo, $wear];
            }
        }
    }

    /**
     * @dataProvider vehicleTerrainProvider
     */
    public function testProfileMatchesContractTable(
        string $key,
        string $terrain,
        int $cells,
        float $tired,
        int $order,
        float $cargo,
        int $wear,
    ): void {
        $svc = $this->svc($this->activeConfig());

        $this->assertSame([
            'key'                 => $key,
            'cells_per_tick'      => $cells,
            'tired_factor'        => $tired,
            'max_steps_per_order' => $order,
            'cargo_share'         => $cargo,
            'wear_per_cell'       => $wear,
        ], $svc->profileFor($key, $terrain));
    }

    // ── ни одно значение не хуже пешего, кроме поимённого исключения ──────

    public function testNoVehicleIsWorseThanBaseExceptSnowmobileTiredFactor(): void
    {
        $svc  = $this->svc($this->activeConfig());
        $base = $svc->neutralProfile();

        foreach (array_keys($this->contractTable()) as $key) {
            foreach ([VehicleEffectsService::TERRAIN_EXPLORED, VehicleEffectsService::TERRAIN_UNEXPLORED, VehicleEffectsService::TERRAIN_COLD] as $terrain) {
                $profile = $svc->profileFor($key, $terrain);

                $this->assertGreaterThanOrEqual($base['cells_per_tick'], $profile['cells_per_tick'], "{$key}/{$terrain}: cells_per_tick хуже пешего");

                if ($key === 'snowmobile') {
                    $this->assertSame(1.10, $profile['tired_factor'], 'snowmobile — единственное поимённое исключение (хуже пешего)');
                } else {
                    $this->assertLessThanOrEqual(1.0, $profile['tired_factor'], "{$key}/{$terrain}: tired_factor хуже пешего");
                }
            }
        }
    }

    // ── зажимы (hard_max) ──────────────────────────────────────────────

    public function testCellsPerTickClampedToHardMaxFive(): void
    {
        $config                                       = $this->activeConfig();
        $config['world.vehicle.mtb.cells_per_tick_explored'] = 999;
        $svc                                           = $this->svc($config);

        $this->assertSame(5, $svc->profileFor('mtb', VehicleEffectsService::TERRAIN_EXPLORED)['cells_per_tick']);
    }

    public function testCargoShareClampedToConfiguredMaxShare(): void
    {
        $config                                     = $this->activeConfig();
        $config['world.vehicle.draft_cart.cargo_share'] = 0.90;
        $config['world.vehicle.cargo.max_share']        = 0.33;
        $svc                                         = $this->svc($config);

        $this->assertSame(0.33, $svc->profileFor('draft_cart', VehicleEffectsService::TERRAIN_EXPLORED)['cargo_share']);
    }
}
