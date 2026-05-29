<?php

declare(strict_types=1);

namespace Tests\Unit\Language;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * W26 (ADR-081) — i18n foundation PoC: ru/en паритет ключей Economy + ICU-рендер.
 *
 * Anti-drift: оба locale обязаны иметь идентичный набор ключей (иначе en-fallback
 * молча покажет ru). Проверяем загрузку обоих файлов + корректную интерполяцию.
 *
 * @internal
 */
final class EconomyLangTest extends CIUnitTestCase
{
    /** @return array<string,mixed> */
    private function file(string $locale): array
    {
        /** @var array<string,mixed> $data */
        $data = require APPPATH . "Language/{$locale}/Economy.php";
        return $data;
    }

    public function testRuAndEnHaveIdenticalKeySets(): void
    {
        $ru = array_keys($this->file('ru'));
        $en = array_keys($this->file('en'));
        sort($ru);
        sort($en);
        $this->assertSame($ru, $en, 'ru и en/Economy.php должны иметь одинаковые ключи (иначе тихий fallback)');
    }

    public function testRuStringsRenderAsCurrentProdText(): void
    {
        // ru-рендер должен совпадать с прежним hardcoded-текстом (byte-identical).
        $this->assertSame('💰 *Моя экономика*', lang('Economy.title', [], 'ru'));
        $this->assertSame('💵 Золото', lang('Economy.gold', [], 'ru'));
        $this->assertSame('💎 *Чистая стоимость: 703 422*', lang('Economy.net_worth', ['703 422'], 'ru'));
        $this->assertSame('• Лианы — 281 шт. (2 804)', lang('Economy.holding_line', ['Лианы', '281', '2 804'], 'ru'));
        $this->assertSame('Богаче *100%* выживших · позиция *#1* из 3', lang('Economy.comparison_server', ['100', '1', '3'], 'ru'));
    }

    public function testEnStringsRenderTranslated(): void
    {
        $this->assertSame('💰 *My Economy*', lang('Economy.title', [], 'en'));
        $this->assertSame('💎 *Net worth: 703 422*', lang('Economy.net_worth', ['703 422'], 'en'));
        $this->assertSame('• Lianas — 281 pcs. (2 804)', lang('Economy.holding_line', ['Lianas', '281', '2 804'], 'en'));
    }

    public function testNoStringIntPlaceholderReformatting(): void
    {
        // Числа передаём строками → ICU не должен добавлять группировку/менять вид.
        $this->assertSame('Медиана сервера: 1 234 567', lang('Economy.comparison_median', ['1 234 567'], 'ru'));
    }
}
