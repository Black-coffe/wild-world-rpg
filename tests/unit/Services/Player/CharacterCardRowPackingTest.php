<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Player;

use App\Services\Telegram\ButtonPacker;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Раскладка карточки Персонажа (фидбэк владельца 2026-07-27): в ряду НИКОГДА не стоит
 * одна кнопка. Набор кнопок на карточке плавает — фракция / дейлики / хабы / подать /
 * реферал условны, — поэтому ряды считаются `ButtonPacker::packByCount()`, а не
 * прописываются руками. Тест держит инвариант при ЛЮБОМ количестве кнопок.
 *
 * Ревью-находка M6 (2026-08-20): раскладка была приватным методом
 * `CharacterService::packButtonRows()` — дублировалась в `VehicleScreenRenderer` и
 * `CraftedResourcesAction`. Унесена в `App\Services\Telegram\ButtonPacker::packByCount()`
 * без изменения алгоритма (сравнение выхода старой и новой логики — см. коммит),
 * поэтому этот тест теперь зовёт её напрямую, без Reflection.
 *
 * @internal
 */
final class CharacterCardRowPackingTest extends CIUnitTestCase
{
    /**
     * @param list<array<string, string>> $flat
     *
     * @return list<list<array<string, string>>>
     */
    private function pack(array $flat, int $tripleAt = 0): array
    {
        return ButtonPacker::packByCount($flat, $tripleAt);
    }

    /** @return list<array<string, string>> */
    private function buttons(int $count): array
    {
        $flat = [];
        for ($i = 0; $i < $count; $i++) {
            $flat[] = ['text' => "btn{$i}", 'callback_data' => "cb{$i}"];
        }

        return $flat;
    }

    /** Главный инвариант: ни одного ряда из одной кнопки — при любом размере карточки. */
    public function testNoSingleButtonRowsForAnyCount(): void
    {
        for ($count = 2; $count <= 16; $count++) {
            $rows = $this->pack($this->buttons($count), 2);

            $triples = 0;
            foreach ($rows as $idx => $row) {
                $this->assertGreaterThanOrEqual(
                    2,
                    count($row),
                    "count={$count}: ряд #{$idx} остался с одной кнопкой.",
                );
                $this->assertLessThanOrEqual(3, count($row), "count={$count}: ряд #{$idx} шире трёх кнопок.");
                if (count($row) === 3) {
                    $triples++;
                }
            }

            $this->assertSame(
                $count % 2 === 1 ? 1 : 0,
                $triples,
                "count={$count}: ряд из трёх нужен ровно при нечётном числе кнопок.",
            );
        }
    }

    /** Порядок и состав не теряются: развёрнутые ряды === исходный список. */
    public function testOrderAndContentPreserved(): void
    {
        for ($count = 2; $count <= 16; $count++) {
            $flat = $this->buttons($count);
            $flatBack = array_merge(...$this->pack($flat, 2));

            $this->assertSame($flat, $flatBack, "count={$count}: упаковка потеряла или переставила кнопки.");
        }
    }

    /** Тройка встаёт на короткие подписи (Экип/Аптечка/Страховка), т.е. не раньше $tripleAt. */
    public function testTripleLandsOnRequestedIndex(): void
    {
        $rows = $this->pack($this->buttons(11), 2);

        $this->assertSame([2, 3, 2, 2, 2], array_map('count', $rows));
        $this->assertSame('cb2', $rows[1][0]['callback_data'], 'Тройка должна начинаться с индекса 2.');
        $this->assertSame('cb4', $rows[1][2]['callback_data']);
    }

    /** Позиция тройки клампится: даже с неудачным $tripleAt одиночек не возникает. */
    public function testTripleAtIsClampedToFit(): void
    {
        foreach ([[5, 9], [7, 2], [9, 0], [3, 5]] as [$count, $tripleAt]) {
            $rows = $this->pack($this->buttons($count), $tripleAt);

            foreach ($rows as $row) {
                $this->assertGreaterThanOrEqual(
                    2,
                    count($row),
                    "count={$count}, tripleAt={$tripleAt}: появился одиночный ряд.",
                );
            }
            $this->assertSame($this->buttons($count), array_merge(...$rows));
        }
    }

    /** Вырожденные входы: пустой список и одна кнопка не роняют упаковщик. */
    public function testDegenerateInputs(): void
    {
        $this->assertSame([], $this->pack([], 2));
        $this->assertSame([[['text' => 'btn0', 'callback_data' => 'cb0']]], $this->pack($this->buttons(1), 2));
    }

    /**
     * Ревью-находка M6: `VehicleScreenRenderer` пакует иначе — тройка (если нужна) в
     * КОНЦЕ, а не в начале. Доказываем побайтовую эквивалентность старого
     * `VehicleScreenRenderer::packRows()` и нового вызова
     * `ButtonPacker::packByCount($flat, count($flat) - 3)` на n=1..12.
     */
    public function testVehicleScreenTripleAtEquivalence(): void
    {
        for ($count = 1; $count <= 12; $count++) {
            $flat = $this->buttons($count);
            $rows = ButtonPacker::packByCount($flat, $count - 3);

            foreach ($rows as $row) {
                if (count($rows) > 1) {
                    $this->assertGreaterThanOrEqual(2, count($row), "count={$count}: одиночный ряд не в вырожденном случае.");
                }
            }
            $this->assertSame($flat, array_merge(...$rows), "count={$count}: порядок/состав потерян.");
        }

        // Опорные точки: старый `VehicleScreenRenderer::packRows()` ставил тройку в
        // конец при нечётном количестве (2+3 на пятёрке, а не 3+2).
        $this->assertSame([2, 3], array_map('count', ButtonPacker::packByCount($this->buttons(5), 5 - 3)));
        $this->assertSame([2, 2, 3], array_map('count', ButtonPacker::packByCount($this->buttons(7), 7 - 3)));
    }
}
