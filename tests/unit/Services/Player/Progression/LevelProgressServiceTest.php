<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Player\Progression;

use App\Services\Player\Progression\LevelProgressService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Слайс «Видимая лестница L1→L10» — расчёт и рендер прогресса к следующему уровню.
 *
 * Формула уровня продублирована из HealthRegenerationHandler::calculateLevel() — тест
 * страхует ИМЕННО совпадение (иначе строка «до уровня 2» противоречила бы цифре «Уровень»
 * в той же карточке). Killswitch-ветка проверяется отдельно: dormant обязан давать null,
 * иначе карточка Персонажа перестаёт быть byte-identical.
 *
 * @internal
 */
final class LevelProgressServiceTest extends CIUnitTestCase
{
    /**
     * @param array<string, mixed> $overrides
     */
    private function svc(array $overrides): LevelProgressService
    {
        return new LevelProgressService(null, $overrides);
    }

    /** @return array<string, mixed> */
    private function character(float $exp, float $str = 0.0, float $agi = 0.0, float $intl = 0.0): array
    {
        return [
            'experience' => $exp,
            'strength'   => $str,
            'agility'    => $agi,
            'intellect'  => $intl,
        ];
    }

    // ── Killswitch ──────────────────────────────────────────────────────

    public function testDisabledReturnsNullLine(): void
    {
        $svc = $this->svc(['progression.ladder.enabled' => false]);

        $this->assertFalse($svc->isEnabled());
        $this->assertNull($svc->cardLine($this->character(5.0)));
    }

    public function testEmptyConfigDefaultsToDisabled(): void
    {
        $this->assertNull($this->svc([])->cardLine($this->character(5.0)));
    }

    // ── Сумма статов ────────────────────────────────────────────────────

    public function testStatSumAddsFourFormulaFields(): void
    {
        $this->assertSame(5.48, round(LevelProgressService::statSum(
            $this->character(3.80, 0.41, 0.45, 0.82)
        ), 2));
    }

    public function testStatSumIgnoresNonNumericAndMissingFields(): void
    {
        $this->assertSame(2.0, LevelProgressService::statSum([
            'experience' => 2.0,
            'strength'   => 'нет',
            // agility / intellect отсутствуют
        ]));
    }

    public function testStatSumDoesNotCountHealthOrGold(): void
    {
        // Здоровье, усталость и золото в формулу уровня не входят — частая ошибка ожиданий.
        $this->assertSame(1.0, LevelProgressService::statSum([
            'experience' => 1.0,
            'health'     => 100.0,
            'tired'      => 100.0,
            'gold'       => 63000000,
        ]));
    }

    // ── Формула уровня (копия крона) ────────────────────────────────────

    public function testLevelForSumMatchesCronFormula(): void
    {
        $this->assertSame(1, LevelProgressService::levelForSum(0.04));   // старт: 4×0.01
        $this->assertSame(1, LevelProgressService::levelForSum(5.48));   // живой пример с прода
        $this->assertSame(1, LevelProgressService::levelForSum(7.99));
        $this->assertSame(2, LevelProgressService::levelForSum(8.0));    // ровно порог L2
        $this->assertSame(5, LevelProgressService::levelForSum(23.99));
        $this->assertSame(6, LevelProgressService::levelForSum(24.0));
    }

    public function testLevelNeverDropsBelowOne(): void
    {
        $this->assertSame(1, LevelProgressService::levelForSum(0.0));
        $this->assertSame(1, LevelProgressService::levelForSum(-5.0));
    }

    // ── Полоса прогресса ────────────────────────────────────────────────

    public function testFirstLevelSpanStartsAtZero(): void
    {
        // L1 особый: floor даёт 0, но уровень поднят до 1 — полоса считается от нуля до 8.
        $this->assertSame([0.0, 8.0], LevelProgressService::spanFor(1));
    }

    public function testHigherLevelSpanIsFourWide(): void
    {
        $this->assertSame([8.0, 12.0], LevelProgressService::spanFor(2));
        $this->assertSame([40.0, 44.0], LevelProgressService::spanFor(10));
    }

    /**
     * 🔴 Главный инвариант рендера: процент НИКОГДА не убывает при росте суммы.
     * Наивная реализация (база = level*4 всегда) даёт откат 97% → 0% на sum=4.0,
     * потому что уровень там ещё 1. Бар, который прыгает назад, читается как поломка.
     */
    public function testPercentNeverDecreasesWithinOneLevel(): void
    {
        $prevLevel   = null;
        $prevPercent = -1;

        for ($step = 0; $step <= 300; $step++) {
            $sum   = round($step * 0.1, 2);
            $level = LevelProgressService::levelForSum($sum);
            $pct   = LevelProgressService::percentToNext($sum);

            if ($level === $prevLevel) {
                $this->assertGreaterThanOrEqual(
                    $prevPercent,
                    $pct,
                    "процент откатился внутри уровня {$level} на сумме {$sum}"
                );
            }

            $prevLevel   = $level;
            $prevPercent = $pct;
        }
    }

    /**
     * Точечная страховка от наивной реализации (база = level*4 всегда): на сумме 3.9
     * уровень ещё 1, и такая формула дала бы 97%, а на 4.0 — 0% при том же первом уровне.
     */
    public function testFirstLevelDoesNotResetAtSumFour(): void
    {
        $this->assertSame(1, LevelProgressService::levelForSum(3.9));
        $this->assertSame(1, LevelProgressService::levelForSum(4.0));
        $this->assertSame(48, LevelProgressService::percentToNext(3.9));
        $this->assertSame(50, LevelProgressService::percentToNext(4.0));
    }

    public function testPercentOnRealProdCase(): void
    {
        // lynxtux, 292 действия: сумма 5.48 из 8.0 = 68% пути к L2.
        $this->assertSame(68, LevelProgressService::percentToNext(5.48));
    }

    public function testPercentNeverReachesHundred(): void
    {
        // 100% при неизменившемся уровне читалось бы как «готово, но не выдали».
        $this->assertSame(99, LevelProgressService::percentToNext(7.999));
        $this->assertSame(0, LevelProgressService::percentToNext(8.0));
    }

    public function testRemainingToNext(): void
    {
        $this->assertSame(2.52, round(LevelProgressService::remainingToNext(5.48), 2));
        $this->assertSame(4.0, LevelProgressService::remainingToNext(8.0));
        $this->assertSame(8.0, LevelProgressService::remainingToNext(0.0));
    }

    // ── Бар ─────────────────────────────────────────────────────────────

    public function testBarLengthIsAlwaysTenCells(): void
    {
        foreach ([0, 1, 37, 68, 99, 100] as $pct) {
            $this->assertSame(10, mb_strlen(LevelProgressService::bar($pct)), "percent {$pct}");
        }
    }

    public function testBarFillsProportionally(): void
    {
        $this->assertSame('▱▱▱▱▱▱▱▱▱▱', LevelProgressService::bar(0));
        $this->assertSame('▰▰▰▰▰▰▱▱▱▱', LevelProgressService::bar(68));
        $this->assertSame('▰▰▰▰▰▰▰▰▰▱', LevelProgressService::bar(99));
    }

    // ── Строка карточки ─────────────────────────────────────────────────

    public function testCardLineRendersProdCase(): void
    {
        $svc = $this->svc([
            'progression.ladder.enabled' => true,
            'progression.early.enabled'  => false, // подсказки нет → одна строка
        ]);

        $this->assertSame(
            '🪜 *До уровня 2:* ▰▰▰▰▰▰▱▱▱▱ 68% — набрано 5.5 из 8.0',
            $svc->cardLine($this->character(3.80, 0.41, 0.45, 0.82))
        );
    }

    public function testCardLineMarkdownBalanced(): void
    {
        $svc = $this->svc(['progression.ladder.enabled' => true, 'progression.early.enabled' => true]);

        foreach ([0.0, 5.48, 8.0, 40.0] as $sum) {
            $line = (string) $svc->cardLine($this->character($sum));
            $this->assertSame(0, substr_count($line, '*') % 2, "непарные * на сумме {$sum}");
            $this->assertSame(0, substr_count($line, '_') % 2, "непарные _ на сумме {$sum}");
        }
    }

    /**
     * Подсказка «больше всего даёт добыча» — правда ТОЛЬКО пока работает ранняя полоса
     * ADR-138 (там начисляется gather_xp). Ветерану добыча опыта не даёт, обещание было бы ложью.
     */
    public function testHintShownOnlyWhileEarlyProgressionActuallyGivesGatherXp(): void
    {
        $active = [
            'progression.ladder.enabled'       => true,
            'progression.early.enabled'        => true,
            'progression.early.level_cap'      => 5,
            'progression.early.gather_xp'      => 0.30,
            'progression.early.gain_multiplier' => 2.0,
        ];

        $newbie = (string) $this->svc($active)->cardLine($this->character(5.48));
        $this->assertStringContainsString('добыча', $newbie);

        // Уровень 6 (сумма 24.0) — за порогом ранней полосы.
        $veteran = (string) $this->svc($active)->cardLine($this->character(24.0));
        $this->assertStringNotContainsString('добыча', $veteran);

        // Ранняя полоса выключена — подсказки нет и у новичка.
        $dormant = (string) $this->svc([
            'progression.ladder.enabled' => true,
            'progression.early.enabled'  => false,
        ])->cardLine($this->character(5.48));
        $this->assertStringNotContainsString('добыча', $dormant);
    }

    public function testCardLineHandlesFreshCharacterWithoutCrash(): void
    {
        $svc  = $this->svc(['progression.ladder.enabled' => true]);
        $line = (string) $svc->cardLine(['experience' => 0.01, 'strength' => 0.01, 'agility' => 0.01, 'intellect' => 0.01]);

        $this->assertStringContainsString('До уровня 2', $line);
        $this->assertStringContainsString('0%', $line);
    }
}
