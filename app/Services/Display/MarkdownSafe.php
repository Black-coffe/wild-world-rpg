<?php

declare(strict_types=1);

namespace App\Services\Display;

/**
 * Санитайзер пользовательских строк для legacy-`parse_mode: 'Markdown'`.
 *
 * Telegram-Markdown (не MarkdownV2) НЕ поддерживает экранирование обратным слэшем
 * ([[feedback_legacy_markdown_no_backslash_escape]]): непарная `*` или `_` в имени игрока
 * или базы валит парсинг ВСЕГО сообщения → HTTP 400 → тихий не-сенд. Поэтому спец-символы
 * не экранируем, а вырезаем.
 *
 * Раньше эта логика жила приватной копией в `TaxCollectionHandler::markdownSafeName()`
 * (per-base разбивка налога). Топ игроков рендерит имена десятками — второй копии не заводим.
 */
final class MarkdownSafe
{
    /** Символы, ломающие legacy-Markdown Telegram. */
    private const BREAKING = ['*', '_', '`', '[', ']'];

    /**
     * Имя (игрока / базы) → безопасное для Markdown. Пустой результат → $fallback.
     */
    public static function name(string $name, string $fallback = 'Выживший'): string
    {
        $clean = trim(str_replace(self::BREAKING, '', $name));

        return $clean === '' ? $fallback : $clean;
    }
}
