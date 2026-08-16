<?php

declare(strict_types=1);

namespace Tests\Unit\Telegram;

use App\Controllers\Telegram\Commands\Actions\Craft\Cooking\CampfireCookingSelect;
use App\Services\Telegram\ButtonPacker;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * 🔴 Правило владельца (2026-08-16): кнопка не занимает ряд одна, если по длине
 * подписей в ряд влезают две или три. Повод — скриншот меню готовки: семь блюд
 * колонкой, семь экранов прокрутки на телефоне вместо трёх рядов.
 *
 * @internal
 */
final class ButtonPackerTest extends CIUnitTestCase
{
    /** @param list<string> $labels */
    private function btns(array $labels): array
    {
        return array_map(static fn (string $t): array => ['text' => $t, 'callback_data' => 'x'], $labels);
    }

    /** @param list<list<array<string,string>>> $rows */
    private function shape(array $rows): array
    {
        return array_map('count', $rows);
    }

    public function testNeverLeavesALoneButtonInARow(): void
    {
        // Любой набор от 2 до 12 кнопок с любой длиной подписи.
        foreach (range(2, 12) as $n) {
            foreach ([4, 10, 16, 22] as $len) {
                $rows = ButtonPacker::pack($this->btns(array_fill(0, $n, str_repeat('я', $len))));

                foreach ($rows as $i => $row) {
                    $this->assertGreaterThan(
                        1,
                        count($row),
                        "n={$n}, длина подписи={$len}: ряд #{$i} остался с одной кнопкой",
                    );
                }
            }
        }
    }

    public function testSingleButtonStaysAlone(): void
    {
        $this->assertSame([1], $this->shape(ButtonPacker::pack($this->btns(['один']))));
    }

    public function testKeepsOrder(): void
    {
        $labels = ['а', 'б', 'в', 'г', 'д'];
        $flat   = [];
        foreach (ButtonPacker::pack($this->btns($labels)) as $row) {
            foreach ($row as $b) {
                $flat[] = $b['text'];
            }
        }

        $this->assertSame($labels, $flat, 'порядок кнопок несёт смысл и не должен меняться');
    }

    public function testNeverMoreThanThreePerRow(): void
    {
        foreach (ButtonPacker::pack($this->btns(array_fill(0, 9, 'к'))) as $row) {
            $this->assertLessThanOrEqual(ButtonPacker::MAX_PER_ROW, count($row));
        }
    }

    /** Длинные подписи не должны склеиваться по три — иначе перенос в две строки. */
    public function testLongLabelsPairUp(): void
    {
        $rows = ButtonPacker::pack($this->btns([
            '🍄 Грибная похлёбка', '🫐 Ягодный взвар', '🍎 Печёные фрукты', '🥣 Зерновая каша',
        ]));

        $this->assertSame([2, 2], $this->shape($rows));
    }

    /**
     * Живой набор: меню горячего при включённых рыбных блюдах (как на проде).
     * Семь блюд обязаны лечь в три ряда, а не в семь.
     */
    public function testRealHotMenuPacksIntoThreeRows(): void
    {
        $cfg     = config('CraftRecipes');
        $labels  = [];
        foreach (array_merge(CampfireCookingSelect::HOT_RECIPES, CampfireCookingSelect::FISH_HOT_RECIPES) as $key) {
            $r        = $cfg->get($key);
            $labels[] = $r['icon_emoji'] . ' ' . $r['item_name_rus'];
        }

        $rows = ButtonPacker::pack($this->btns($labels));
        $this->assertLessThanOrEqual(3, count($rows), 'семь блюд должны лечь максимум в три ряда');

        foreach ($rows as $row) {
            $this->assertGreaterThan(1, count($row));
        }
    }

    /** Меню консервов (3 при живой рыбе) — один ряд из трёх, без одиночек. */
    public function testRealPreserveMenuHasNoLoneRow(): void
    {
        $cfg    = config('CraftRecipes');
        $labels = [];
        foreach (array_merge(CampfireCookingSelect::PRESERVE_RECIPES, CampfireCookingSelect::FISH_PRESERVE_RECIPES) as $key) {
            $r        = $cfg->get($key);
            $labels[] = $r['icon_emoji'] . ' ' . $r['item_name_rus'];
        }

        foreach (ButtonPacker::pack($this->btns($labels)) as $row) {
            $this->assertGreaterThan(1, count($row));
        }
    }
}
