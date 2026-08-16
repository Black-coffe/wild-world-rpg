<?php

declare(strict_types=1);

namespace App\Services\Telegram;

/**
 * Упаковщик инлайн-кнопок в ряды.
 *
 * 🔴 ПРАВИЛО (2026-08-16, требование владельца): кнопка НИКОГДА не занимает ряд
 * одна, если по длине подписей в ряд влезают две или три. Список из семи блюд
 * по кнопке в строке — это семь экранов прокрутки на телефоне вместо трёх рядов.
 *
 * Как пакует: жадно, СОХРАНЯЯ ПОРЯДОК (он несёт смысл — от простого к сытному),
 * до трёх кнопок в ряд, пока суммарная длина подписей влезает в бюджет строки.
 * Если последним остаётся один — он уходит в предыдущий ряд, даже сверх бюджета:
 * ряд из трёх с переносом подписи всё равно лучше одинокой кнопки.
 */
final class ButtonPacker
{
    /**
     * Бюджет строки в символах. 36 подобран под 375px: три коротких подписи
     * («🍲 Сытное рагу», «🐟 Уха», «🔥 Жареная рыба») дают 34 и встают в ряд,
     * а две длинных («🍄 Грибная похлёбка» + «🍎 Печёные фрукты») третью уже
     * не пускают.
     */
    public const ROW_BUDGET = 36;

    /** Максимум кнопок в ряду — больше трёх Telegram сжимает до нечитаемого. */
    public const MAX_PER_ROW = 3;

    /**
     * Точная форма кнопки проносится через упаковщик: вызывающий код часто
     * объявляет `array{text: string, callback_data: string}`, и без шаблона
     * он терял бы её на `pack()`.
     *
     * @template TButton of array<string,string>
     *
     * @param  list<TButton>       $buttons кнопки в порядке показа
     * @return list<list<TButton>> ряды для inline_keyboard
     */
    public static function pack(array $buttons, int $rowBudget = self::ROW_BUDGET): array
    {
        if ($buttons === []) {
            return [];
        }

        $rows = [];
        $i    = 0;
        $n    = count($buttons);

        while ($i < $n) {
            $rows[] = array_slice($buttons, $i, $take = self::takeCount($buttons, $i, $n, $rowBudget));
            $i += $take;
        }

        return $rows;
    }

    /**
     * Сколько кнопок забрать в очередной ряд.
     *
     * Пол — ДВЕ кнопки: одиночный ряд недопустим, даже если подписи длинные
     * (Telegram перенесёт подпись в две строки — это всё равно лучше колонки).
     * Тройку берём, когда она влезает в бюджет, ИЛИ когда иначе в хвосте
     * останется одинокая кнопка. Остаток из четырёх режем 2+2, а не 3+1.
     *
     * @param list<array<string,string>> $buttons
     */
    private static function takeCount(array $buttons, int $i, int $n, int $rowBudget): int
    {
        $remaining = $n - $i;

        if ($remaining <= self::MAX_PER_ROW) {
            // 1 — вырожденный случай «кнопка всего одна»; 2 и 3 — как есть,
            // потому что дробить их пришлось бы с одиночкой в хвосте.
            return $remaining;
        }

        if ($remaining === 4) {
            return 2; // 3+1 запрещено, 2+2 ровно
        }

        $tripleFits = self::width($buttons[$i]) + self::width($buttons[$i + 1]) + self::width($buttons[$i + 2]) <= $rowBudget;

        return $tripleFits ? 3 : 2;
    }

    /**
     * Видимая ширина подписи в символах (эмодзи считаем за один знакоместо).
     *
     * @param array<string,string> $btn
     */
    private static function width(array $btn): int
    {
        return mb_strlen($btn['text'] ?? '');
    }
}
