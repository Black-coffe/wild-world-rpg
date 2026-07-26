<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Buildings;

use App\Services\Buildings\BuildingGateService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Buildings;

/**
 * ADR-155 — единый источник «на каком уровне открывается постройка».
 *
 * Тест держит инвариант, ради которого сервис и появился: игроку показывается ТОТ ЖЕ уровень,
 * который реально проверяет стройка (`Config\Buildings.level_required`). До ADR-155 показывалась
 * колонка `buildings.min_character_level`, разошедшаяся с конфигом у 9 построек из 16.
 *
 * @internal
 */
final class BuildingGateServiceTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        BuildingGateService::resetCache();
    }

    private function svc(): BuildingGateService
    {
        return new BuildingGateService(new Buildings());
    }

    /**
     * 🔴 Якорь аудита: Спортзал открывается на 5-м уровне. В БД у него стояло 0, из-за чего
     * строка «что откроется» (ADR-153) не показывала его НИ НА КАКОМ уровне.
     */
    public function testGymGateIsFive(): void
    {
        $this->assertSame(5, $this->svc()->requiredLevel('Gym'));
        $this->assertContains('Спортзал', $this->svc()->unlockedAt(5));
    }

    /** Постройки, которые БД объявляла поздними, на деле доступны с первого уровня. */
    public function testEarlyBuildingsAreActuallyFirstLevel(): void
    {
        $svc = $this->svc();

        foreach (['Workshop', 'Warehouse', 'Greenhouse', 'BlastFurnace', 'SolarStation'] as $key) {
            $this->assertSame(1, $svc->requiredLevel($key), $key);
        }
    }

    public function testLateBuildingsKeepTheirGates(): void
    {
        $svc = $this->svc();

        $this->assertSame(10, $svc->requiredLevel('RoboticsWorkshop'));
        $this->assertSame(12, $svc->requiredLevel('TeleportationCenter'));
        $this->assertSame(15, $svc->requiredLevel('Arsenal'));
    }

    public function testUnknownKeyDoesNotInventAGate(): void
    {
        $this->assertSame(1, $this->svc()->requiredLevel('НетТакойПостройки'));
        $this->assertSame([], $this->svc()->unlockedAt(777));
        $this->assertSame(0, $this->svc()->countUnlockedAt(777));
    }

    public function testEveryConfiguredBuildingLandsInTheMap(): void
    {
        $svc     = $this->svc();
        $total   = 0;
        foreach ($svc->map() as $level => $names) {
            $this->assertGreaterThanOrEqual(1, $level, 'уровень не может быть ниже первого');
            $total += count($names);
        }

        $this->assertSame(count((new Buildings())->recipes), $total, 'ни одна постройка не потеряна');
    }

    public function testMapIsSortedAndNamesAreRussian(): void
    {
        $map = $this->svc()->map();

        $this->assertSame(array_keys($map), array_values(array_unique(array_keys($map))));
        foreach ($map as $names) {
            $sorted = $names;
            sort($sorted);
            $this->assertSame($sorted, $names, 'имена внутри уровня отсортированы');
            foreach ($names as $name) {
                $this->assertNotSame('', trim($name));
            }
        }
    }

    public function testCountMatchesNamesForEveryLevel(): void
    {
        $svc = $this->svc();

        foreach (array_keys($svc->map()) as $level) {
            $this->assertSame(
                count($svc->unlockedAt($level)),
                $svc->countUnlockedAt($level),
                "уровень {$level}"
            );
        }
    }

    // ── ADR-156: фактический порог по материалам ────────────────────────

    /**
     * 🔴 Якорь слайса 4: Спортзал объявлен с L5, но требует Стекло пакеты (крафт с L25) —
     * значит своими руками он собирается только к 25-му. Эмпирика прода сходится: зал стоит
     * у L11, L29, L50, L51, L64, L223 и ни у кого на пятом.
     */
    public function testGymEffectiveLevelIsDrivenByItsHardestMaterial(): void
    {
        $svc = new FakeGateService(new Buildings());

        $this->assertSame(5, $svc->requiredLevel('Gym'), 'объявленный гейт не меняется');
        $this->assertSame(25, $svc->effectiveLevel('Gym'), 'фактический — по Стеклу пакетам');
    }

    public function testEffectiveLevelNeverBelowDeclaredGate(): void
    {
        $svc = new FakeGateService(new Buildings());

        // У Навеса материалы простые — фактический порог равен объявленному.
        $this->assertSame($svc->requiredLevel('LeanTo'), $svc->effectiveLevel('LeanTo'));
        // У Арсенала объявленный гейт выше части материалов — берётся больший.
        $this->assertGreaterThanOrEqual($svc->requiredLevel('Arsenal'), $svc->effectiveLevel('Arsenal'));
    }

    public function testMapIsBuiltOnEffectiveLevels(): void
    {
        $svc = new FakeGateService(new Buildings());

        $this->assertContains('Спортзал', $svc->unlockedAt(25), 'обещаем там, где реально собирается');
        $this->assertNotContains('Спортзал', $svc->unlockedAt(5), 'на пятом обещать нельзя');
    }

    public function testOutOfReachMaterialsListedForLowLevelPlayer(): void
    {
        $svc = new FakeGateService(new Buildings());

        $out = $svc->outOfReachMaterials('Gym', 5);
        $names = array_column($out, 'name');
        $this->assertContains('Стекло пакеты', $names);
        $this->assertContains('Янтарь', $names);
        $this->assertSame(25, $out[0]['level'], 'самое труднодоступное — первым');
    }

    public function testNothingOutOfReachForHighLevelPlayer(): void
    {
        $this->assertSame([], (new FakeGateService(new Buildings()))->outOfReachMaterials('Gym', 30));
    }

    public function testMissingContentTablesDegradeToDeclaredGate(): void
    {
        // Тестовая БД контент-таблиц не содержит: сервис обязан вернуть объявленный гейт,
        // а не упасть и не выдумать порог.
        $svc = new EmptyMaterialsGateService(new Buildings());

        $this->assertSame(5, $svc->effectiveLevel('Gym'));
        $this->assertSame([], $svc->outOfReachMaterials('Gym', 1));
    }
}

/**
 * Test-double: материалы фиксированы (реальные значения прода 2026-07-26), без БД.
 *
 * @internal
 */
class FakeGateService extends BuildingGateService
{
    protected function materialDetails(): array
    {
        return [
            'Gym' => [
                ['name' => 'Стекло пакеты', 'level' => 25, 'kind' => 'крафт'],
                ['name' => 'Янтарь', 'level' => 20, 'kind' => 'добыча'],
                ['name' => 'Минералы', 'level' => 10, 'kind' => 'добыча'],
            ],
            'LeanTo'  => [['name' => 'Древесина', 'level' => 1, 'kind' => 'добыча']],
            'Arsenal' => [['name' => 'Нефть', 'level' => 20, 'kind' => 'добыча']],
        ];
    }
}

/**
 * Test-double: контент-таблиц нет (как в тестовой БД).
 *
 * @internal
 */
final class EmptyMaterialsGateService extends BuildingGateService
{
    protected function materialDetails(): array
    {
        return [];
    }
}
