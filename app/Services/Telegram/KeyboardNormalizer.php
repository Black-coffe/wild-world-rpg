<?php

declare(strict_types=1);

namespace App\Services\Telegram;

/**
 * Приводит инлайн-клавиатуру исходящего сообщения к правилу «ноль одиночек в ряду».
 *
 * 🔴 Правило владельца (2026-08-16): кнопка не занимает ряд одна, если по длине
 * подписей в ряд влезают две или три. Нарушено оно было не в одном экране —
 * инвентаризация показала 365 одиночных рядов в 151 файле. Поэтому правило
 * включено ЦЕНТРАЛЬНО, в точке отправки ({@see Request}), а не 365 правками:
 * одна реализация, один тест, и новый экран не может «забыть» про правило.
 *
 * Осторожность важнее максимализма — трогаем только очевидное:
 *
 * - **Колонка** (два и более одиночных ряда подряд) — то, из-за чего правило и
 *   появилось: список, отрисованный по кнопке в строке. Пакуем {@see ButtonPacker}.
 * - **Одинокий ряд рядом с обычным** (например «⬅️ Назад» после ряда из двух) —
 *   подсаживаем к соседнему ряду, если там меньше трёх кнопок и подписи влезают.
 * - **Ряды из двух-трёх кнопок не трогаем вообще**: там уже есть осознанная
 *   группировка, и переставлять её мы не вправе.
 *
 * Не трогаем: reply-клавиатуру (`keyboard` — её раскладка зафиксирована ADR-150),
 * `remove_keyboard`, `force_reply`, а также клавиатуру из одной-единственной кнопки.
 */
final class KeyboardNormalizer
{
    /**
     * Нормализует `reply_markup` в данных исходящего запроса.
     *
     * @param  array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function normalize(array $data): array
    {
        $markup = $data['reply_markup'] ?? null;
        if ($markup === null) {
            return $data;
        }

        // Markup приходит либо JSON-строкой (доминирующая идиома в проекте),
        // либо массивом. Entity-объекты Longman не трогаем — их единицы.
        $wasJson = is_string($markup);
        if ($wasJson) {
            $decoded = json_decode($markup, true);
            if (! is_array($decoded)) {
                return $data;
            }
            $markup = $decoded;
        }

        if (! is_array($markup) || ! isset($markup['inline_keyboard']) || ! is_array($markup['inline_keyboard'])) {
            return $data;
        }

        $rows = self::normalizeRows(array_values($markup['inline_keyboard']));
        if ($rows === null) {
            return $data;
        }

        $markup['inline_keyboard'] = $rows;
        $data['reply_markup']      = $wasJson ? json_encode($markup) : $markup;

        return $data;
    }

    /**
     * @param  list<mixed>                             $rows
     * @return list<list<array<string,string>>>|null   null — трогать нечего
     */
    private static function normalizeRows(array $rows): ?array
    {
        // Валидация формы: каждый ряд — список кнопок-массивов со строковым 'text'.
        /** @var list<list<array<string,string>>> $typed */
        $typed = [];
        foreach ($rows as $row) {
            if (! is_array($row) || $row === []) {
                return null;
            }
            $typedRow = [];
            foreach ($row as $btn) {
                if (! is_array($btn) || ! isset($btn['text']) || ! is_string($btn['text'])) {
                    return null;
                }
                /** @var array<string,string> $btn */
                $typedRow[] = $btn;
            }
            $typed[] = $typedRow;
        }

        // Клавиатура из одной кнопки — одиночка неизбежна.
        if (count($typed) === 1 && count($typed[0]) === 1) {
            return null;
        }

        /** @var list<list<array<string,string>>> $out */
        $out = [];
        /** @var list<array<string,string>> $run накопленная колонка из одиночных рядов */
        $run     = [];
        $changed = false;

        foreach ($typed as $row) {
            if (count($row) === 1) {
                $run[] = $row[0];
                continue;
            }
            self::flushRun($run, $out, $changed);
            $out[] = $row;
        }
        self::flushRun($run, $out, $changed);

        // Второй проход: оставшиеся одиночки подсаживаем к соседнему ряду.
        $out = self::attachLoneRows($out, $changed);

        return $changed ? $out : null;
    }

    /**
     * Сбрасывает накопленную колонку одиночек в выходные ряды: две и более —
     * пакуем, ровно одна — оставляем как есть (её добьёт второй проход).
     *
     * @param list<array<string,string>>       $run
     * @param list<list<array<string,string>>> $out
     */
    private static function flushRun(array &$run, array &$out, bool &$changed): void
    {
        if ($run === []) {
            return;
        }

        if (count($run) === 1) {
            $out[] = [$run[0]];
        } else {
            foreach (ButtonPacker::pack($run) as $packedRow) {
                $out[] = $packedRow;
            }
            $changed = true;
        }

        $run = [];
    }

    /**
     * @param  list<list<array<string,string>>> $rows
     * @return list<list<array<string,string>>>
     */
    private static function attachLoneRows(array $rows, bool &$changed): array
    {
        $count = count($rows);
        if ($count < 2) {
            return $rows;
        }

        for ($i = 0; $i < count($rows); $i++) {
            if (count($rows[$i]) !== 1) {
                continue;
            }

            // Сначала пробуем предыдущий ряд (кнопка встаёт в конец — порядок сохранён),
            // затем следующий (кнопка встаёт в начало).
            $attached = false;
            foreach ([['prev', $i - 1], ['next', $i + 1]] as [$where, $j]) {
                // Единственное препятствие — ряд уже полон. Ширину НЕ проверяем:
                // правило «ноль одиночек» безусловно, а длинную подпись Telegram
                // перенесёт в две строки — это всё равно лучше кнопки-одиночки.
                if (! isset($rows[$j]) || count($rows[$j]) >= ButtonPacker::MAX_PER_ROW) {
                    continue;
                }

                if ($where === 'prev') {
                    $rows[$j][] = $rows[$i][0];
                } else {
                    array_unshift($rows[$j], $rows[$i][0]);
                }
                array_splice($rows, $i, 1);
                $changed  = true;
                $attached = true;
                $i--;
                break;
            }

            if (! $attached) {
                $changed = self::borrowFromFullNeighbour($rows, $i) || $changed;
            }
        }

        return $rows;
    }

    /**
     * Последнее средство: одиночка зажата между ПОЛНЫМИ рядами — занимаем ей соседа.
     *
     * Изначально такой случай считался неустранимым: ряды из двух-трёх кнопок
     * трогать не хотелось, там осознанная группировка. Но замер (2026-08-16)
     * показал цену этой осторожности: на 31 экране крафта хвост собран как
     * `array_chunk($quantityButtons, 3)` + `[Инвентарь]` + `[Продать, Купить]` +
     * `[Назад]`, и при 3 или 6 кнопках количества «⬅️ Назад» остаётся один в
     * ряду — оба соседа полны. Правило владельца безусловно, поэтому одинокий
     * ряд перевешивает сохранность тройки.
     *
     * Двигаем ровно одну кнопку и только через границу рядов, так что чтение
     * слева-направо-сверху-вниз не меняется: `[a|b|c] / [x]` → `[a|b] / [c|x]`.
     *
     * @param list<list<array<string,string>>> $rows
     */
    private static function borrowFromFullNeighbour(array &$rows, int $i): bool
    {
        $prev = $i - 1;
        if (isset($rows[$prev]) && count($rows[$prev]) === ButtonPacker::MAX_PER_ROW) {
            /** @var array<string,string> $moved */
            $moved = array_pop($rows[$prev]);
            array_unshift($rows[$i], $moved);

            return true;
        }

        $next = $i + 1;
        if (isset($rows[$next]) && count($rows[$next]) === ButtonPacker::MAX_PER_ROW) {
            /** @var array<string,string> $moved */
            $moved = array_shift($rows[$next]);
            $rows[$i][] = $moved;

            return true;
        }

        return false;
    }

}
