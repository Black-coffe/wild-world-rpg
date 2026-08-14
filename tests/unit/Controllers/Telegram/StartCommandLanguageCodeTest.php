<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers\Telegram;

use App\Controllers\Telegram\Commands\StartCommand;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Нормализация `from.language_code` из Telegram-апдейта.
 *
 * Зачем поле вообще есть (аудит «молчунов» 2026-08-14): ~22% регистраций нажимают `/start`
 * и больше ничего, хотя у них всё отработало и их четырежды догоняли без единого возврата.
 * Среди них видны заведомо не-русскоязычные аккаунты, а игра существует только на русском —
 * но проверить долю нечитающих было нечем. Pure-хелпер, без БД/Telegram.
 *
 * @internal
 */
final class StartCommandLanguageCodeTest extends CIUnitTestCase
{
    /** Поле у Telegram ОПЦИОНАЛЬНОЕ: его отсутствие — норма, а не ошибка. */
    public function testMissingCodeGivesNull(): void
    {
        $this->assertNull(StartCommand::normalizeLanguageCode(null));
        $this->assertNull(StartCommand::normalizeLanguageCode(''));
        $this->assertNull(StartCommand::normalizeLanguageCode('   '));
    }

    public function testKeepsSimpleTagsLowercased(): void
    {
        $this->assertSame('ru', StartCommand::normalizeLanguageCode('ru'));
        $this->assertSame('en', StartCommand::normalizeLanguageCode('EN'));
        $this->assertSame('id', StartCommand::normalizeLanguageCode('id'));
    }

    /** Региональные варианты не схлопываем: `pt-br` и `pt` — разная аудитория. */
    public function testKeepsRegionalVariants(): void
    {
        $this->assertSame('en-gb', StartCommand::normalizeLanguageCode('en-GB'));
        $this->assertSame('zh-hans', StartCommand::normalizeLanguageCode('zh-hans'));
    }

    public function testTrimsAndStripsUnsafeChars(): void
    {
        $this->assertSame('ru', StartCommand::normalizeLanguageCode('  ru  '));
        // Вырезаются НЕбуквенные символы (кавычка, `;`, пробел) — буквы остаются, их
        // безопасность обеспечивает не фильтр, а ширина колонки и параметризованный запрос.
        $this->assertSame('rudropru', StartCommand::normalizeLanguageCode("ru';DROP ru"));
        $this->assertNull(StartCommand::normalizeLanguageCode('123'), 'цифры не язык');
        $this->assertNull(StartCommand::normalizeLanguageCode('рус'), 'кириллица — не IETF-тег');
    }

    /** Не длиннее ширины колонки VARCHAR(16), иначе STRICT обрежет молча. */
    public function testTruncatedToColumnWidth(): void
    {
        $this->assertSame(16, mb_strlen((string) StartCommand::normalizeLanguageCode(str_repeat('ab', 20))));
    }
}
