<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

/**
 * ADR-103 Часть B — каталог контекстных обучающих подсказок (just-in-time).
 *
 * Ключ → { text (Markdown), reply_markup? }. Расширяется с КАЖДОЙ новой механикой
 * (конституционное правило ONBOARDING-COVERAGE, CLAUDE.md) — это «живой документ».
 * Подсказки самодостаточны в тексте (media-off инвариант): несут весь смысл словами.
 */
class OnboardingHintCatalog
{
    /** Первая база: куда строить (триггер — новичок без базы в мире). */
    public const FIRST_BASE = 'first_base';

    /**
     * @return array{text: string, reply_markup?: string}|null
     */
    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /**
     * @return array<string, array{text: string, reply_markup?: string}>
     */
    public static function all(): array
    {
        return [
            self::FIRST_BASE => [
                'text' => "💡 *Подсказка новичку*\n\n"
                    . "Чтобы что-то строить (Склад, Теплицу, Мастерскую и др.) — нужна *своя база*.\n\n"
                    . "1️⃣ Открой *«База»* в нижнем меню (или команда /base)\n"
                    . "2️⃣ Нажми *«🏕 Разбить лагерь»* на подходящей клетке\n"
                    . "3️⃣ Затем *«🏗 Строить»* — там и Склад, и Теплица, и всё остальное\n\n"
                    . "_Постройки возводятся на базе, а не покупаются в магазине._",
            ],
        ];
    }
}
