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

    public function testLoneRowStaysWhenNeighbourIsFullOrTooWide(): void
    {
        // Сосед уже из трёх — подсаживать некуда.
        $full = [['А', 'Б', 'В'], ['Назад']];
        $this->assertSame($full, $this->shapeOf(KeyboardNormalizer::normalize($this->kb($full))));

        // Подписи слишком длинные — ряд не влезет.
        $wide = [[str_repeat('я', 20), str_repeat('ю', 20)], [str_repeat('э', 20)]];
        $this->assertSame($wide, $this->shapeOf(KeyboardNormalizer::normalize($this->kb($wide))));
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
