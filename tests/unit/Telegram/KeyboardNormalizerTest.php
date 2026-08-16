<?php

declare(strict_types=1);

namespace Tests\Unit\Telegram;

use App\Services\Telegram\KeyboardNormalizer;
use CodeIgniter\Test\CIUnitTestCase;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * 🔴 Правило владельца (2026-08-16): кнопка не занимает ряд одна, если по длине
 * подписей в ряд влезают две или три. Правило включено централизованно — в точке
 * отправки ({@see \App\Services\Telegram\Request}), поэтому и держим его здесь.
 *
 * @internal
 */
final class KeyboardNormalizerTest extends CIUnitTestCase
{
    /** @param list<list<string>> $rows */
    private function kb(array $rows): array
    {
        return ['reply_markup' => json_encode(['inline_keyboard' => array_map(
            static fn (array $row): array => array_map(
                static fn (string $t): array => ['text' => $t, 'callback_data' => 'x'],
                $row,
            ),
            $rows,
        )])];
    }

    /** @return list<list<string>> */
    private function shapeOf(array $data): array
    {
        $markup = json_decode((string) $data['reply_markup'], true);

        return array_map(
            static fn (array $row): array => array_map(static fn (array $b): string => $b['text'], $row),
            $markup['inline_keyboard'],
        );
    }

    public function testColumnOfLoneRowsGetsPacked(): void
    {
        $out = KeyboardNormalizer::normalize($this->kb([['А'], ['Б'], ['В'], ['Г'], ['Д']]));

        $this->assertSame([['А', 'Б', 'В'], ['Г', 'Д']], $this->shapeOf($out));
    }

    public function testExistingMultiButtonRowsAreNeverSplit(): void
    {
        $rows = [['Купить', 'Продать'], ['Инвентарь', 'Назад']];
        $out  = KeyboardNormalizer::normalize($this->kb($rows));

        $this->assertSame($rows, $this->shapeOf($out), 'осознанную группировку не переставляем');
    }

    public function testLoneRowJoinsNeighbourWhenItFits(): void
    {
        $out = KeyboardNormalizer::normalize($this->kb([['Купить', 'Продать'], ['Назад']]));

        $this->assertSame([['Купить', 'Продать', 'Назад']], $this->shapeOf($out));
    }

    /**
     * Одиночка между ПОЛНЫМИ рядами — занимаем ей соседа.
     *
     * До 2026-08-16 этот случай считался неустранимым и ряд из одной кнопки
     * оставался. Замер показал цену: на 31 экране крафта хвост
     * `array_chunk($quantityButtons, 3)` + `[Инвентарь]` + `[Продать, Купить]` +
     * `[Назад]` при 3 и 6 кнопках количества оставлял «⬅️ Назад» одного.
     * Правило владельца безусловно → тройка уступает.
     */
    public function testLoneRowBorrowsFromFullNeighbour(): void
    {
        $out = $this->shapeOf(KeyboardNormalizer::normalize($this->kb([['А', 'Б', 'В'], ['Назад']])));

        $this->assertSame([['А', 'Б'], ['В', 'Назад']], $out, 'кнопка переезжает через границу рядов, порядок чтения не меняется');
    }

    public function testLoneRowBorrowsFromNextRowWhenThereIsNoPrevious(): void
    {
        $out = $this->shapeOf(KeyboardNormalizer::normalize($this->kb([['Назад'], ['А', 'Б', 'В']])));

        $this->assertSame([['Назад', 'А'], ['Б', 'В']], $out);
    }

    /**
     * Главный инвариант, проверенный перебором: после нормализации ряда из одной
     * кнопки НЕ существует — кроме вырожденного случая «кнопка всего одна».
     * Порядок чтения слева-направо-сверху-вниз при этом обязан сохраниться.
     */
    public function testNoLoneRowSurvivesAnyShape(): void
    {
        $shapes = $this->allShapes(4, 3);
        $this->assertGreaterThan(100, count($shapes), 'перебор обязан быть содержательным');

        foreach ($shapes as $shape) {
            $flatIn = array_merge(...$shape);
            if (count($flatIn) === 1) {
                continue; // вырожденный случай — одиночка неизбежна
            }

            $out = $this->shapeOf(KeyboardNormalizer::normalize($this->kb($shape)));

            foreach ($out as $row) {
                $this->assertGreaterThan(1, count($row), 'одиночка выжила на форме ' . json_encode($shape));
                $this->assertLessThanOrEqual(3, count($row), 'ряд разросся больше трёх на форме ' . json_encode($shape));
            }

            $this->assertSame($flatIn, array_merge(...$out), 'порядок кнопок изменился на форме ' . json_encode($shape));
        }
    }

    /**
     * Живая форма экрана крафта: кнопки количества чанками по три плюс хвост.
     * Именно она и вскрыла дыру, поэтому проверяем её отдельно и на всех
     * размерах — новое количество вариантов крафта не вернёт одиночку молча.
     */
    public function testCraftQuantityScreenHasNoLoneRowAtAnyQuantity(): void
    {
        foreach (range(1, 6) as $n) {
            $shape   = array_chunk(array_map(static fn (int $i): string => "Крафт {$i} шт", range(1, $n)), 3);
            $shape[] = ['Инвентарь'];
            $shape[] = ['Продать', 'Купить'];
            $shape[] = ['Назад'];

            foreach ($this->shapeOf(KeyboardNormalizer::normalize($this->kb($shape))) as $row) {
                $this->assertGreaterThan(1, count($row), "одиночка на экране крафта при {$n} кнопках количества");
            }
        }
    }

    /**
     * Все раскладки из $maxRows рядов по 1..$maxPerRow кнопок, с уникальными подписями.
     *
     * @return list<list<list<string>>>
     */
    private function allShapes(int $maxRows, int $maxPerRow): array
    {
        $shapes = [];

        for ($rows = 1; $rows <= $maxRows; $rows++) {
            foreach ($this->sizeCombos($rows, $maxPerRow) as $sizes) {
                $shape = [];
                $n     = 1;
                foreach ($sizes as $size) {
                    $row = [];
                    for ($k = 0; $k < $size; $k++) {
                        $row[] = 'к' . $n++;
                    }
                    $shape[] = $row;
                }
                $shapes[] = $shape;
            }
        }

        return $shapes;
    }

    /**
     * @return list<list<int>>
     */
    private function sizeCombos(int $rows, int $maxPerRow): array
    {
        if ($rows === 0) {
            return [[]];
        }

        $out = [];
        foreach ($this->sizeCombos($rows - 1, $maxPerRow) as $tail) {
            for ($size = 1; $size <= $maxPerRow; $size++) {
                $out[] = array_merge([$size], $tail);
            }
        }

        return $out;
    }

    /**
     * Длинные подписи одиночку НЕ спасают: правило безусловно, а перенос подписи
     * в две строки лучше кнопки-одиночки (решение владельца, 2026-08-16).
     */
    public function testLongLabelsDoNotExcuseALoneRow(): void
    {
        $wide = [[str_repeat('я', 20), str_repeat('ю', 20)], [str_repeat('э', 20)]];
        $out  = $this->shapeOf(KeyboardNormalizer::normalize($this->kb($wide)));

        $this->assertCount(1, $out, 'одиночка обязана подсесть к соседу');
        $this->assertCount(3, $out[0]);
    }

    public function testSingleButtonKeyboardIsLeftAlone(): void
    {
        $one = [['Единственная']];
        $this->assertSame($one, $this->shapeOf(KeyboardNormalizer::normalize($this->kb($one))));
    }

    public function testOrderIsPreserved(): void
    {
        $out  = KeyboardNormalizer::normalize($this->kb([['1'], ['2'], ['3'], ['4'], ['5'], ['6'], ['7']]));
        $flat = [];
        foreach ($this->shapeOf($out) as $row) {
            foreach ($row as $t) {
                $flat[] = $t;
            }
        }

        $this->assertSame(['1', '2', '3', '4', '5', '6', '7'], $flat);
    }

    public function testReplyKeyboardIsNotTouched(): void
    {
        // Раскладка постоянного меню зафиксирована ADR-150 — нормализатор к ней не лезет.
        $data = ['reply_markup' => json_encode(['keyboard' => [[['text' => '🌍 Мир']], [['text' => '🧑 Я']]]])];
        $this->assertSame($data, KeyboardNormalizer::normalize($data));
    }

    public function testNonKeyboardPayloadsPassThrough(): void
    {
        foreach ([
            ['text' => 'без клавиатуры'],
            ['reply_markup' => json_encode(['force_reply' => true])],
            ['reply_markup' => json_encode(['remove_keyboard' => true])],
            ['reply_markup' => 'не json'],
        ] as $data) {
            $this->assertSame($data, KeyboardNormalizer::normalize($data));
        }
    }

    public function testWorksWithArrayMarkupToo(): void
    {
        $data = ['reply_markup' => ['inline_keyboard' => [
            [['text' => 'А', 'callback_data' => 'a']],
            [['text' => 'Б', 'callback_data' => 'b']],
        ]]];

        $out = KeyboardNormalizer::normalize($data);
        $this->assertIsArray($out['reply_markup'], 'массив остаётся массивом, не превращается в JSON');
        $this->assertCount(1, $out['reply_markup']['inline_keyboard']);
    }

    /**
     * 🔴 Анти-дрейф: экраны обязаны слать через надстройку `App\Services\Telegram\Request`,
     * иначе клавиатура уйдёт мимо нормализатора и правило снова начнёт разъезжаться.
     */
    public function testNoAppFileImportsLongmanRequestDirectly(): void
    {
        $offenders = [];
        $iterator  = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(APPPATH, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = str_replace('\\', '/', (string) $file->getRealPath());
            if (str_ends_with($path, 'app/Services/Telegram/Request.php')) {
                continue; // сама надстройка — ей можно
            }
            if (str_contains((string) file_get_contents($path), 'use Longman\TelegramBot\Request;')) {
                $offenders[] = $path;
            }
        }

        $this->assertSame([], $offenders, "Эти файлы шлют мимо нормализатора клавиатуры:\n" . implode("\n", $offenders));
    }
}
