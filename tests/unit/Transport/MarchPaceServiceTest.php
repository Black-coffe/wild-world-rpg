<?php

declare(strict_types=1);

namespace Tests\Unit\Transport;

use App\Services\World\MarchPaceService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * transport-01 (tracer) — MarchPaceService — единственный источник арифметики
 * темпа Похода. Чистый сервис (без БД, без Telegram): проверяем возвращаемые
 * значения, не наличие строк формулы (memory `feedback_source_scan_tests_are_not_coverage`).
 *
 * Байт-идентичность: сегодняшние дефолты `world.march.cells_per_tick=3`,
 * `world.march.minutes_per_cell=1`, `world.march.health_cost_per_cell=0.02`,
 * `world.march.tired_cost_per_cell=0.5` (см. MarchAction/MarchingTaskHandler fallback'ы).
 */
final class MarchPaceServiceTest extends CIUnitTestCase
{
    private MarchPaceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MarchPaceService();
    }

    /** @return array<string,mixed> */
    private function neutralProfile(int $cellsPerTickBase): array
    {
        return [
            'key'                 => null,
            'cells_per_tick'      => $cellsPerTickBase,
            'tired_factor'        => 1.0,
            'max_steps_per_order' => 60,
            'cargo_share'         => 0.0,
            'wear_per_cell'       => 0,
        ];
    }

    // ------------------------------------------------------------------ cellsPerTick

    public function testCellsPerTickReturnsExactlyBaseOnNeutralProfile(): void
    {
        $base = 3;
        $this->assertSame($base, $this->service->cellsPerTick($base, $this->neutralProfile($base)));
    }

    public function testCellsPerTickIsClampedToHardMaxFive(): void
    {
        $profile = $this->neutralProfile(3);
        $profile['cells_per_tick'] = 9;

        $this->assertSame(5, $this->service->cellsPerTick(3, $profile));
    }

    public function testCellsPerTickNeverGoesBelowBase(): void
    {
        $profile = $this->neutralProfile(3);
        $profile['cells_per_tick'] = 1;

        $this->assertSame(3, $this->service->cellsPerTick(3, $profile));
    }

    // ------------------------------------------------------------------ etaMinutes: превью == обработчик

    /**
     * ETA превью (`MarchAction::showRouteSetup()`) и ETA обработчика
     * (`MarchingTaskHandler::etaMinutes()`) — один и тот же вызов сервиса.
     * Таблица клеток × перТик=3, минут/клетку=1 — сегодняшние дефолты.
     *
     * @return array<string, array{int,int}>
     */
    public static function etaCellsProvider(): array
    {
        return [
            '1 клетка'  => [1, 1],
            '2 клетки'  => [2, 1],
            '3 клетки'  => [3, 1],
            '4 клетки'  => [4, 2],
            '5 клеток'  => [5, 2],
            '7 клеток'  => [7, 3],
            '10 клеток' => [10, 4],
            '17 клеток' => [17, 6],
            '60 клеток' => [60, 20],
        ];
    }

    /** @dataProvider etaCellsProvider */
    public function testEtaMinutesMatchesTodayFormulaForBothCallSites(int $cells, int $expectedMinutes): void
    {
        $perTick       = 3;
        $minutesPerCell = 1;

        // Путь превью (MarchAction::showRouteSetup): cellsPerTick(base, neutral) → etaMinutes.
        $previewPerTick = $this->service->cellsPerTick($perTick, $this->neutralProfile($perTick));
        $previewEta     = $this->service->etaMinutes($cells, $previewPerTick, $minutesPerCell);

        // Путь обработчика (MarchingTaskHandler::etaMinutes): тот же вызов.
        $handlerPerTick = $this->service->cellsPerTick($perTick, $this->neutralProfile($perTick));
        $handlerEta     = $this->service->etaMinutes($cells, $handlerPerTick, $minutesPerCell);

        $this->assertSame($previewEta, $handlerEta, 'превью и обработчик разошлись на клетках=' . $cells);
        $this->assertSame($expectedMinutes, $previewEta);
    }

    public function testEtaMinutesUsesCeilNotFloor(): void
    {
        // 4 клетки, перТик 3 → 2 тика (ceil(4/3)=2), не 1 (floor).
        $this->assertSame(2, $this->service->etaMinutes(4, 3, 1));
    }

    public function testEtaMinutesZeroCellsIsZeroMinutes(): void
    {
        $this->assertSame(0, $this->service->etaMinutes(0, 3, 1));
    }

    // ------------------------------------------------------------------ stepDueInterval

    public function testStepDueIntervalIsMinutesPerCellMinusOne(): void
    {
        $this->assertSame(0, $this->service->stepDueInterval(1));
        $this->assertSame(4, $this->service->stepDueInterval(5));
    }

    public function testStepDueIntervalNeverNegative(): void
    {
        $this->assertSame(0, $this->service->stepDueInterval(0));
    }

    public function testStepDueIntervalSignatureHasNoProfileParameter(): void
    {
        $method = new \ReflectionMethod(MarchPaceService::class, 'stepDueInterval');
        $this->assertSame(1, $method->getNumberOfParameters());
        $this->assertSame('minutesPerCell', $method->getParameters()[0]->getName());
    }

    // ------------------------------------------------------------------ costs

    public function testTiredCostPerCellOnNeutralProfileEqualsBase(): void
    {
        $base    = 0.5;
        $profile = $this->neutralProfile(3);

        $this->assertSame($base, $this->service->tiredCostPerCell($base, $profile));
    }

    public function testHealthCostPerCellIgnoresProfileAlways(): void
    {
        $base    = 0.02;
        $profile = $this->neutralProfile(3);
        $profile['tired_factor'] = 0.5; // произвольный чужой параметр — не должен влиять

        $this->assertSame($base, $this->service->healthCostPerCell($base, $profile));
    }

    /** Байт-идентичность сегодняшних оценок на экране превью: n=5, база 0.02/0.5. */
    public function testEstimateCostsMatchTodaysPreviewNumbersForFiveCells(): void
    {
        $n       = 5;
        $profile = $this->neutralProfile(3);

        $hpEst    = round($n * $this->service->healthCostPerCell(0.02, $profile), 2);
        $tiredEst = round($n * $this->service->tiredCostPerCell(0.5, $profile), 2);

        $this->assertSame(0.1, $hpEst);
        $this->assertSame(2.5, $tiredEst);
    }

    // ------------------------------------------------------------------ constants

    public function testTerrainConstantsExist(): void
    {
        $this->assertSame('explored', MarchPaceService::TERRAIN_EXPLORED);
        $this->assertSame('unexplored', MarchPaceService::TERRAIN_UNEXPLORED);
        $this->assertSame('cold', MarchPaceService::TERRAIN_COLD);
    }

    // ------------------------------------------------------------------ purity

    public function testServiceConstructsWithoutDbOrTelegram(): void
    {
        $service = new MarchPaceService();
        $this->assertInstanceOf(MarchPaceService::class, $service);
    }
}
