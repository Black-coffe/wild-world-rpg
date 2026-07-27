<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Duration;

use App\Services\Duration\StatDurationInterpolator;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-160 — общая математика «время задачи по статам».
 *
 * Главная страховка: новая форма выражения (`min + (max-min)*(1-ratio)`) обязана после
 * округления совпадать с ОБЕИМИ прежними — крафтовой (та же форма, потолок 1000) и
 * строечной (`max - (max-min)*ratio`, потолок 2000). Иначе «рефактор без изменения
 * поведения» тихо сдвинул бы время у живых игроков на минуту.
 *
 * @internal
 */
final class StatDurationInterpolatorTest extends CIUnitTestCase
{
    /** @return array<string,int|float> */
    private function character(float $exp, float $agi = 0.0, float $int = 0.0): array
    {
        return ['id' => 1, 'experience' => $exp, 'agility' => $agi, 'intellect' => $int];
    }

    public function testNoviceGetsMaxAndVeteranGetsMin(): void
    {
        $novice  = $this->character(0);
        $veteran = $this->character(10000, 10000, 10000);

        $this->assertSame(180, StatDurationInterpolator::minutes($novice, 60, 180, 2000.0));
        $this->assertSame(60, StatDurationInterpolator::minutes($veteran, 60, 180, 2000.0));
    }

    public function testResultAlwaysInsideRange(): void
    {
        foreach ([0.0, 250.0, 1999.0, 2000.0, 5000.0] as $score) {
            $minutes = StatDurationInterpolator::minutes($this->character($score / 0.3), 45, 70, 2000.0);
            $this->assertGreaterThanOrEqual(45, $minutes);
            $this->assertLessThanOrEqual(70, $minutes);
        }
    }

    /** Порченые данные (max < min) — отдаём min, как делали обе прежние копии. */
    public function testBrokenRangeFallsBackToMin(): void
    {
        $this->assertSame(90, StatDurationInterpolator::minutes($this->character(100), 90, 30, 2000.0));
    }

    /** Потолок 0 — делить нельзя; трактуем как «ветеран» (минимум), без деления на ноль. */
    public function testZeroScoreCapYieldsMinWithoutDivisionByZero(): void
    {
        $this->assertSame(60, StatDurationInterpolator::minutes($this->character(0), 60, 180, 0.0));
    }

    /** Нечисловые статы не роняют расчёт и трактуются как 0 (новичок). */
    public function testNonNumericStatsTreatedAsZero(): void
    {
        $weird = ['id' => 1, 'experience' => null, 'agility' => 'нет', 'intellect' => false];

        $this->assertSame(180, StatDurationInterpolator::minutes($weird, 60, 180, 2000.0));
    }

    /**
     * ЯДРО РЕФАКТОРА: сверяем с прежними формулами на широкой сетке значений, включая
     * границы округления .5. Обе прежние копии воспроизведены здесь буквально.
     */
    public function testMatchesBothLegacyFormulasAcrossSweep(): void
    {
        $checked   = 0;
        $mismatchB = [];
        $mismatchC = [];

        for ($minD = 0; $minD <= 200; $minD += 7) {
            for ($span = 0; $span <= 300; $span += 11) {
                $maxD = $minD + $span;
                for ($score = 0.0; $score <= 2600.0; $score += 37.0) {
                    // Персонаж, дающий ровно этот счёт (весь счёт через опыт: вес 0.3).
                    $character = $this->character($score / StatDurationInterpolator::EXP_WEIGHT);

                    // --- прежняя формула СТРОЙКИ (потолок 2000) ---
                    $ratio  = min(1.0, $score / 2000.0);
                    $legacyBuild = max($minD, min($maxD, (int) round($maxD - ($maxD - $minD) * $ratio)));
                    $actualBuild = StatDurationInterpolator::minutes($character, $minD, $maxD, 2000.0);
                    if ($legacyBuild !== $actualBuild) {
                        $mismatchB[] = "min={$minD} max={$maxD} score={$score}: {$legacyBuild} != {$actualBuild}";
                    }

                    // --- прежняя формула КРАФТА (потолок 1000, norm НЕ клампился) ---
                    $norm        = $score / 1000.0;
                    $legacyCraft = max($minD, min($maxD, (int) round($minD + ($maxD - $minD) * (1 - $norm))));
                    $actualCraft = StatDurationInterpolator::minutes($character, $minD, $maxD, 1000.0);
                    if ($legacyCraft !== $actualCraft) {
                        $mismatchC[] = "min={$minD} max={$maxD} score={$score}: {$legacyCraft} != {$actualCraft}";
                    }

                    $checked++;
                }
            }
        }

        $this->assertGreaterThan(10000, $checked, 'сетка должна быть широкой, иначе страховка слабая');
        $this->assertSame([], array_slice($mismatchB, 0, 5), 'расхождение с прежней формулой СТРОЙКИ');
        $this->assertSame([], array_slice($mismatchC, 0, 5), 'расхождение с прежней формулой КРАФТА');
    }
}
