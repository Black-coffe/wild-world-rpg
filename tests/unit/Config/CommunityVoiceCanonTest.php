<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Services\Onboarding\GuideCatalog;
use CodeIgniter\Test\CIUnitTestCase;
use Config\CommunityVoice;

/**
 * community-chat-bot-04 — канон голоса Роби в общем чате. Банк утверждённых строк
 * не проверяется на слово: тест ловит имя с двумя «б», отказ без маршрута, числа
 * баланса (цифрой или словами), самопротиворечие с FORBIDDEN_PATTERNS и непарный `*`.
 *
 * @internal
 */
final class CommunityVoiceCanonTest extends CIUnitTestCase
{
    /** @return array<string,array<int,string>> */
    private function cases(): array
    {
        return [
            'REFUSAL_WITH_ROUTE' => CommunityVoice::REFUSAL_WITH_ROUTE,
            'RECEIPT'            => CommunityVoice::RECEIPT,
            'UNKNOWN'            => CommunityVoice::UNKNOWN,
            'PRIVATE_REDIRECT'   => CommunityVoice::PRIVATE_REDIRECT,
            'GREETING_NEWCOMER'  => CommunityVoice::GREETING_NEWCOMER,
            'META_QUESTION'      => CommunityVoice::META_QUESTION,
            'STOP_TOPIC'         => CommunityVoice::STOP_TOPIC,
        ];
    }

    /** @return array<int,string> */
    private function allLines(): array
    {
        $all = [];
        foreach ($this->cases() as $lines) {
            foreach ($lines as $line) {
                $all[] = $line;
            }
        }

        return $all;
    }

    public function testAllSevenCasesHaveAtLeastOneLine(): void
    {
        $cases = $this->cases();
        $this->assertCount(7, $cases, 'Контракт story называет ровно семь случаев — пустых быть не должно');

        foreach ($cases as $name => $lines) {
            $this->assertNotEmpty($lines, "{$name} не может быть пустым — пустых случаев нет");
        }
    }

    public function testRobiNameHasOneB(): void
    {
        foreach ($this->allLines() as $line) {
            $this->assertStringNotContainsStringIgnoringCase(
                'Робби',
                $line,
                "«Робби» с двумя «б» запрещено — канон один: Роби. Строка: {$line}"
            );
        }
    }

    /** Маршрут = либо кавычки с меткой экрана/раздела/кнопки, либо явная команда. */
    private function hasRoute(string $line): bool
    {
        $hasRoute = str_contains($line, '«') && str_contains($line, '»');

        return $hasRoute || str_contains($line, '/guide') || str_contains($line, '/tips');
    }

    public function testRefusalWithRouteNamesADestination(): void
    {
        foreach (CommunityVoice::REFUSAL_WITH_ROUTE as $line) {
            $this->assertTrue($this->hasRoute($line), "Отказ обязан называть маршрут (раздел/кнопка/команда): {$line}");
        }
    }

    /**
     * Ремонтный круг 1, пункт 1: «тема закрыта» без продолжения — тот же запрещённый
     * класс REFUSAL без маршрута. STOP_TOPIC обязан нести тот же маршрут, что и
     * REFUSAL_WITH_ROUTE, иначе это голый отказник, а не лор с интригой.
     */
    public function testStopTopicNamesARouteToo(): void
    {
        foreach (CommunityVoice::STOP_TOPIC as $line) {
            $this->assertTrue($this->hasRoute($line), "Стоп-тема тоже обязана называть маршрут — голый отказ запрещён как класс: {$line}");
        }
    }

    /**
     * Ремонтный круг 1, пункт 4: отказ не должен характеризовать то, в чём отказывает.
     * «тут точные цифры, они меняются» / «да, там есть порог» сводят поиск игрока из
     * континуума к бинарному перебору так же надёжно, как само число — это утечка
     * отдельного класса от голых цифр (§5 плана, рубеж 3), и REFUSAL_WITH_ROUTE/STOP_TOPIC
     * не имеют права её допускать.
     */
    public function testRefusalDoesNotConfirmExistenceOfHiddenThreshold(): void
    {
        $confirmationPhrases = [
            'точные цифры', 'точный процент', 'точная цифра', 'есть порог', 'есть предел',
            'реальные цифры', 'настоящие цифры', 'скрытый порог', 'скрытые цифры',
        ];

        $refusalLines = array_merge(CommunityVoice::REFUSAL_WITH_ROUTE, CommunityVoice::STOP_TOPIC);

        foreach ($refusalLines as $line) {
            $lower = mb_strtolower($line);
            foreach ($confirmationPhrases as $phrase) {
                $this->assertStringNotContainsString(
                    $phrase,
                    $lower,
                    "Отказ подтверждает существование скрытого порога/точных цифр «{$phrase}»: {$line}"
                );
            }
        }
    }

    /**
     * Ремонтный круг 1, пункт 5: раздела «⚔️ Бой» нет — есть «⚔️ Бой и PvE». Названия
     * разделов в кавычках сверяются дословно с `GuideCatalog::sections()` (title/button),
     * а не по памяти — иначе отказ отправляет игрока в несуществующий раздел.
     */
    public function testQuotedGuideSectionNamesExistInGuideCatalog(): void
    {
        $sections     = GuideCatalog::sections();
        $knownLabels  = ['📖 Путь новичка'];
        foreach ($sections as $section) {
            $knownLabels[] = $section['title'];
            $knownLabels[] = $section['button'];
        }

        foreach ($this->allLines() as $line) {
            if (! preg_match_all('/«([^»]+)»/u', $line, $matches)) {
                continue;
            }

            foreach ($matches[1] as $quoted) {
                $this->assertContains(
                    $quoted,
                    $knownLabels,
                    "«{$quoted}» не совпадает ни с одним title/button из GuideCatalog::sections() дословно: {$line}"
                );
            }
        }
    }

    public function testNoPercentDigitsOrSpelledOutNumerals(): void
    {
        $numeralWords = [
            'ноль', 'один', 'одна', 'одно', 'два', 'две', 'три', 'четыре', 'пять',
            'шесть', 'семь', 'восемь', 'девять', 'десят', 'дцать', 'сорок', 'сто',
            'тысяч', 'миллион', 'процент', 'вдвое', 'втрое',
        ];

        foreach ($this->allLines() as $line) {
            $this->assertDoesNotMatchRegularExpression('/\d/', $line, "Цифры запрещены в тексте банка: {$line}");
            $this->assertDoesNotMatchRegularExpression('/%/', $line, "Знак процента запрещён: {$line}");

            $lower = mb_strtolower($line);
            foreach ($numeralWords as $word) {
                $this->assertStringNotContainsString($word, $lower, "Числительное словами запрещено: «{$word}» в «{$line}»");
            }
        }
    }

    public function testNoLineMatchesForbiddenPatterns(): void
    {
        foreach ($this->allLines() as $line) {
            $lower = mb_strtolower($line);
            foreach (CommunityVoice::FORBIDDEN_PATTERNS as $pattern) {
                $this->assertStringNotContainsString(
                    mb_strtolower($pattern),
                    $lower,
                    "Строка матчится собственным FORBIDDEN_PATTERNS «{$pattern}»: {$line}"
                );
            }
        }
    }

    public function testForbiddenPatternsAreNonEmptyAndDistinct(): void
    {
        $patterns = CommunityVoice::FORBIDDEN_PATTERNS;
        $this->assertNotEmpty($patterns);

        foreach ($patterns as $pattern) {
            $this->assertNotSame('', trim($pattern), 'Пустой паттерн ничего не запрещает');
        }

        $this->assertCount(
            count($patterns),
            array_unique(array_map('mb_strtolower', $patterns)),
            'Набор не должен содержать дублей — иначе он противоречит сам себе на чтении'
        );
    }

    public function testMarkdownAsterisksArePaired(): void
    {
        foreach ($this->allLines() as $line) {
            $this->assertSame(
                0,
                substr_count($line, '*') % 2,
                "Непарная «*» ломает Telegram-markdown: {$line}"
            );
        }
    }

    public function testLinesAreSelfContainedNotEmpty(): void
    {
        // media-off (ADR-020): банк никогда не сопровождается картинкой, но каждая
        // строка обязана быть непустой и не сводиться к одному пробелу/эмодзи.
        foreach ($this->allLines() as $line) {
            $this->assertGreaterThan(5, mb_strlen(trim($line)), "Строка банка выглядит пустой: «{$line}»");
        }
    }
}
