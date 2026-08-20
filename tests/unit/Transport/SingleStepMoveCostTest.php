<?php

declare(strict_types=1);

namespace Tests\Unit\Transport;

use App\Controllers\Telegram\Commands\Actions\MoveCharacterToDirectionAction;
use App\Services\World\VehicleEffectsService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Транспортная система (story transport-05) — стоимость одиночного шага
 * (`MoveCharacterToDirectionAction::computeStepCost()`). Чистый статический метод:
 * без БД, без Telegram — тест зовёт его напрямую (memory `feedback_source_scan_tests_are_not_coverage`).
 *
 * Байт-идентичность: килсвитч транспорта off / нет активной машины → нейтральный профиль
 * (`tired_factor=1.0`) и дефолты `world.move.*` (0.1 / 3.35 / 1.15) обязаны дать РОВНО те же
 * числа, что были захардкожены в `MoveCharacterToDirectionAction` до этой story.
 *
 * @internal
 */
final class SingleStepMoveCostTest extends CIUnitTestCase
{
    /** @return array{health_cost_base: float, tired_cost_base: float, danger_health_surcharge: float} */
    private function defaultSettings(): array
    {
        return [
            'health_cost_base'       => 0.1,
            'tired_cost_base'        => 3.35,
            'danger_health_surcharge' => 1.15,
        ];
    }

    /** @return array<string, mixed> */
    private function neutralProfile(): array
    {
        return (new VehicleEffectsService())->neutralProfile();
    }

    /**
     * Настоящий профиль машины через VehicleEffectsService (не дублируем числа контракта
     * вручную) — killswitch включён оверрайдом, читаем tired_factor из «активной» конфигурации.
     */
    private function profileFor(string $vehicleKey, float $tiredFactor): array
    {
        $svc = new VehicleEffectsService(null, [
            'world.vehicle.enabled'                         => true,
            'world.vehicle.cargo.max_share'                 => 0.33,
            'world.march.cells_per_tick'                     => 3,
            'world.march.max_steps_per_order'                => 60,
            "world.vehicle.{$vehicleKey}.cells_per_tick_explored"   => 4,
            "world.vehicle.{$vehicleKey}.cells_per_tick_unexplored" => 4,
            "world.vehicle.{$vehicleKey}.cells_per_tick_cold"       => 4,
            "world.vehicle.{$vehicleKey}.tired_factor"              => $tiredFactor,
            "world.vehicle.{$vehicleKey}.max_steps_per_order"       => 90,
            "world.vehicle.{$vehicleKey}.cargo_share"               => 0.0,
            "world.vehicle.{$vehicleKey}.wear_per_cell"             => 1,
        ]);

        return $svc->profileFor($vehicleKey, VehicleEffectsService::TERRAIN_EXPLORED);
    }

    // ── байт-идентичность ──────────────────────────────────────────────

    public function testDefaultsWithoutVehicleNoDangerMatchTodaysHardcodedNumbers(): void
    {
        $cost = MoveCharacterToDirectionAction::computeStepCost(
            $this->defaultSettings(),
            false,
            $this->neutralProfile(),
            1.0
        );

        $this->assertSame(0.1, $cost['health']);
        $this->assertSame(3.35, $cost['tired']);
    }

    public function testDangerBiomeAddsSurchargeToHealthOnlyLikeTodaysCode(): void
    {
        $cost = MoveCharacterToDirectionAction::computeStepCost(
            $this->defaultSettings(),
            true,
            $this->neutralProfile(),
            1.0
        );

        // 0.1 + 1.15 = 1.25 — ровно то, что делал hardcoded `$healthCost += 1.15;`.
        $this->assertSame(1.25, $cost['health']);
        $this->assertSame(3.35, $cost['tired']);
    }

    public function testRedsIfAnyOfTheThreeDefaultsChanges(): void
    {
        $settings = $this->defaultSettings();

        $this->assertSame(0.1, $settings['health_cost_base']);
        $this->assertSame(3.35, $settings['tired_cost_base']);
        $this->assertSame(1.15, $settings['danger_health_surcharge']);
    }

    // ── новичок ────────────────────────────────────────────────────────

    public function testEarlyFactorAppliesToTiredOnlyAndNotHealth(): void
    {
        $cost = MoveCharacterToDirectionAction::computeStepCost(
            $this->defaultSettings(),
            false,
            $this->neutralProfile(),
            0.80 // EarlyProgressionService::moveCostFactor() дефолт для новичка
        );

        $this->assertSame(0.1, $cost['health']);
        $this->assertSame(3.35 * 0.80, $cost['tired']);
    }

    // ── профиль транспорта ────────────────────────────────────────────

    public function testDraftCartProfileGivesStrictlyLessTiredThanPedestrian(): void
    {
        $pedestrian = MoveCharacterToDirectionAction::computeStepCost(
            $this->defaultSettings(), false, $this->neutralProfile(), 1.0
        );
        $draftCart = MoveCharacterToDirectionAction::computeStepCost(
            $this->defaultSettings(), false, $this->profileFor('draft_cart', 0.75), 1.0
        );

        $this->assertLessThan($pedestrian['tired'], $draftCart['tired']);
        $this->assertSame($pedestrian['health'], $draftCart['health'], 'здоровье профилем не меняется');
    }

    public function testDroneAutoProfileGivesStrictlyLessTiredThanPedestrian(): void
    {
        $pedestrian = MoveCharacterToDirectionAction::computeStepCost(
            $this->defaultSettings(), false, $this->neutralProfile(), 1.0
        );
        $droneAuto = MoveCharacterToDirectionAction::computeStepCost(
            $this->defaultSettings(), false, $this->profileFor('drone_auto', 0.75), 1.0
        );

        $this->assertLessThan($pedestrian['tired'], $droneAuto['tired']);
        $this->assertSame($pedestrian['health'], $droneAuto['health'], 'здоровье профилем не меняется');
    }

    public function testSnowmobileProfileGivesStrictlyMoreTiredThanPedestrian(): void
    {
        $pedestrian = MoveCharacterToDirectionAction::computeStepCost(
            $this->defaultSettings(), false, $this->neutralProfile(), 1.0
        );
        $snowmobile = MoveCharacterToDirectionAction::computeStepCost(
            $this->defaultSettings(), false, $this->profileFor('snowmobile', 1.10), 1.0
        );

        $this->assertGreaterThan($pedestrian['tired'], $snowmobile['tired']);
        $this->assertSame($pedestrian['health'], $snowmobile['health'], 'здоровье профилем не меняется');
    }

    // ── комбинация новичок + транспорт ────────────────────────────────

    public function testNewbieWithVehicleCombinesFactorsInOneMultiplicationNoDoubleRoundingToZero(): void
    {
        $profile = $this->profileFor('draft_cart', 0.75);

        $cost = MoveCharacterToDirectionAction::computeStepCost(
            $this->defaultSettings(),
            false,
            $profile,
            0.80
        );

        // Один продукт: 3.35 × 0.75 × 0.80 — не два отдельных округления.
        $expected = 3.35 * 0.75 * 0.80;
        $this->assertSame($expected, $cost['tired']);
        $this->assertGreaterThan(0.0, $cost['tired'], 'усталость новичка на транспорте не должна схлопнуться в ноль');
    }

    public function testDangerAndVehicleAndEarlyCombineWithoutInterference(): void
    {
        $cost = MoveCharacterToDirectionAction::computeStepCost(
            $this->defaultSettings(),
            true,
            $this->profileFor('snowmobile', 1.10),
            0.80
        );

        $this->assertSame(0.1 + 1.15, $cost['health']);
        $this->assertSame(3.35 * 1.10 * 0.80, $cost['tired']);
    }
}
