<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Buildings;

use App\Services\Buildings\ProductionEffectService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\GameBalance;

/**
 * ADR-155 — строка «что даёт ИМЕННО ТВОЯ постройка».
 *
 * Главное, ради чего сервис существует: числа берутся из тех же источников, что использует крон,
 * поэтому строка не может разъехаться с игрой. До правки экран Спортзала печатал рукописное
 * «каждые 5ть минут» при фактических 30 — завышение отдачи в 6 раз.
 *
 * @internal
 */
final class ProductionEffectServiceTest extends CIUnitTestCase
{
    private function svc(): FakeProductionEffectService
    {
        return new FakeProductionEffectService(new GameBalance());
    }

    // ── Спортзал ────────────────────────────────────────────────────────

    public function testGymLineUsesRealIntervalNotTheOldLie(): void
    {
        $line = (string) $this->svc()->gymLine(1);

        $this->assertStringContainsString('каждые 30 мин', $line, 'интервал обязан быть боевым (30), а не легендарным «5ть минут»');
        $this->assertStringContainsString('+0.01 силы', $line);
        $this->assertStringNotContainsString('5 мин', $line);
    }

    public function testGymDailyMathIsHonest(): void
    {
        // L1: 0.01 каждые 30 минут = 48 тиков в сутки = 0.48.
        $this->assertStringContainsString('0.48 силы в сутки', (string) $this->svc()->gymLine(1));
        // L4: 0.04 × 48 = 1.92.
        $this->assertStringContainsString('1.92 силы в сутки', (string) $this->svc()->gymLine(4));
    }

    public function testGymLineScalesWithBuildingLevel(): void
    {
        $svc = $this->svc();

        $this->assertStringContainsString('+0.01 силы', (string) $svc->gymLine(1));
        $this->assertStringContainsString('+0.07 силы', (string) $svc->gymLine(5));
        $this->assertStringContainsString('+0.15 силы', (string) $svc->gymLine(10));
    }

    public function testGymLineFollowsLiveInterval(): void
    {
        // Админ подвинул интервал — строка обязана поехать следом (иначе снова ложь).
        $svc                = $this->svc();
        $svc->tickInterval  = 60;

        $line = (string) $svc->gymLine(1);
        $this->assertStringContainsString('каждые 60 мин', $line);
        $this->assertStringContainsString('0.24 силы в сутки', $line); // 24 тика вместо 48
    }

    /**
     * ADR-156: множитель отдачи — admin-tunable. Строка обязана показывать ровно то, что
     * начислит крон: иначе снова обещание мимо факта (класс ошибки «каждые 5ть минут»).
     */
    public function testGymLineFollowsStrengthMultiplier(): void
    {
        $svc                     = $this->svc();
        $svc->strengthMultiplier = 2.0;

        $line = (string) $svc->gymLine(1);
        $this->assertStringContainsString('+0.02 силы', $line);
        $this->assertStringContainsString('0.96 силы в сутки', $line);
    }

    public function testDefaultMultiplierKeepsLegacyNumbers(): void
    {
        $svc                     = $this->svc();
        $svc->strengthMultiplier = 1.0;

        $this->assertStringContainsString('+0.01 силы', (string) $svc->gymLine(1));
        $this->assertStringContainsString('0.48 силы в сутки', (string) $svc->gymLine(1));
    }

    public function testUnknownGymLevelSaysNothing(): void
    {
        $this->assertNull($this->svc()->gymLine(99));
        $this->assertNull($this->svc()->gymLine(0));
    }

    // ── Скважина и Теплица ──────────────────────────────────────────────

    public function testHandPumpLine(): void
    {
        $line = (string) $this->svc()->handPumpLine(1);

        $this->assertStringContainsString('1 воды в минуту', $line);
        $this->assertStringContainsString('1440 в сутки', $line);
        $this->assertStringContainsString('сухих биомах меньше', $line, 'биом-множитель обязан быть назван');
    }

    public function testGreenhouseLineCountsHarvestAndWater(): void
    {
        // L1: Fruit 2 и Berries 1 за минуту, вода 1 → 2880 / 1440 / расход 1440.
        $line = (string) $this->svc()->greenhouseLine(1);

        $this->assertStringContainsString('2880 фруктов', $line);
        $this->assertStringContainsString('1440 ягод', $line);
        $this->assertStringContainsString('расход 1440 в сутки', $line);
    }

    public function testGreenhouseHigherLevelAddsMushroomsAndCrops(): void
    {
        $line = (string) $this->svc()->greenhouseLine(10);

        $this->assertStringContainsString('грибов', $line);
        $this->assertStringContainsString('злаков', $line);
    }

    // ── Общее ───────────────────────────────────────────────────────────

    public function testBuildingsWithoutFormulaSayNothing(): void
    {
        $svc = $this->svc();

        $this->assertNull($svc->lineFor('Warehouse', 1));
        $this->assertNull($svc->lineFor('Arsenal', 3));
        $this->assertNull($svc->lineFor('НетТакой', 1));
    }

    public function testLineForRoutesToTheRightFormula(): void
    {
        $svc = $this->svc();

        $this->assertSame($svc->gymLine(2), $svc->lineFor('Gym', 2));
        $this->assertSame($svc->handPumpLine(2), $svc->lineFor('HandPump', 2));
        $this->assertSame($svc->greenhouseLine(2), $svc->lineFor('Greenhouse', 2));
    }

    public function testAllLinesMarkdownBalanced(): void
    {
        $svc = $this->svc();

        foreach (['Gym', 'HandPump', 'Greenhouse'] as $key) {
            for ($lvl = 1; $lvl <= 10; $lvl++) {
                $line = (string) $svc->lineFor($key, $lvl);
                $this->assertSame(0, substr_count($line, '*') % 2, "{$key} L{$lvl}: непарные *");
                $this->assertSame(0, substr_count($line, '_') % 2, "{$key} L{$lvl}: непарные _");
            }
        }
    }
}

/**
 * Test-double: подменяет чтение live-интервала из GameSettings (без БД).
 *
 * @internal
 */
final class FakeProductionEffectService extends ProductionEffectService
{
    public int $tickInterval = 30;
    public float $strengthMultiplier = 1.0;

    protected function gsInt(string $key, int $default): int
    {
        return $this->tickInterval;
    }

    protected function gsFloat(string $key, float $default): float
    {
        return $this->strengthMultiplier;
    }
}
