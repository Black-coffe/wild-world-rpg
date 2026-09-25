<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Web;

use App\Services\Web\TelegramMarkupRenderer as R;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * web-bridge-p1-02 — bot text → safe HTML for `/play`.
 *
 * @internal
 */
final class TelegramMarkupRendererTest extends CIUnitTestCase
{
    /** Output contains no live tag outside the allow-list and no event/JS attribute. */
    private function assertInert(string $html): void
    {
        $this->assertDoesNotMatchRegularExpression('~<(?!/?(?:b|i|u|s|code|pre|br|a)[\s>])~i', $html);
        $this->assertDoesNotMatchRegularExpression('~<[^>]*\son[a-z]+\s*=~i', $html);
        $this->assertStringNotContainsStringIgnoringCase('href="javascript', $html);
    }

    public function testEmptyAndNull(): void
    {
        $this->assertSame('', R::toHtml(null, 'Markdown'));
        $this->assertSame('', R::toHtml('', null));
    }

    public function testPlainModeEscapesAndBreaksLines(): void
    {
        $this->assertSame('a &lt;b&gt;<br>c', R::toHtml("a <b>\r\nc", null));
    }

    // ---------------------------------------------------------------- XSS

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function xssProvider(): iterable
    {
        foreach (['Markdown', 'MarkdownV2', 'HTML', null] as $mode) {
            $k = $mode ?? 'plain';
            yield "{$k} script"   => ['<script>alert(1)</script>', $mode];
            yield "{$k} img"      => ['<img src=x onerror="alert(1)">', $mode];
            yield "{$k} svg"      => ['<svg/onload=alert(1)>', $mode];
            yield "{$k} iframe"   => ['<iframe src="https://x"></iframe>', $mode];
        }
        yield 'md js link'          => ['[x](javascript:alert(1))', 'Markdown'];
        yield 'md2 js link'         => ['[x](javascript:alert\\(1\\))', 'MarkdownV2'];
        yield 'md quote in href'    => ['[t](https://x.io/"onmouseover="alert(1))', 'Markdown'];
        yield 'html js link'        => ['<a href="javascript:alert(1)">x</a>', 'HTML'];
        yield 'html entity js link' => ['<a href="&#106;avascript:alert(1)">x</a>', 'HTML'];
        yield 'html quote in href'  => ['<a href=\'https://x.io/" onclick="alert(1)\'>x</a>', 'HTML'];
        yield 'html b with attr'    => ['<b onclick="alert(1)">x</b>', 'HTML'];
        yield 'html div style'      => ['<div style="x">y</div><br><p>z</p>', 'HTML'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('xssProvider')]
    public function testXssIsInert(string $text, ?string $mode): void
    {
        $this->assertInert(R::toHtml($text, $mode));
    }

    public function testHtmlDisallowedTagsBecomeVisibleText(): void
    {
        $out = R::toHtml('<script>alert(1)</script>', 'HTML');
        $this->assertStringContainsString('&lt;script&gt;', $out);
        $this->assertStringNotContainsString('<script', $out);
    }

    public function testQuoteInsideHrefDropsTheLink(): void
    {
        $this->assertSame('t', R::toHtml('<a href="https://x.io/?q=&quot;a">t</a>', 'HTML'));
        $this->assertStringNotContainsString('<a', R::toHtml('[t](https://x.io/"a)', 'Markdown'));
    }

    // ---------------------------------------------------------------- Legacy Markdown

    public function testLegacyMarkdownRenders(): void
    {
        $out = R::toHtml("*жир* _кур_ `код` [t](https://x.io/a_b)\nдальше", 'Markdown');
        $this->assertSame(
            '<b>жир</b> <i>кур</i> <code>код</code> '
            . '<a href="https://x.io/a_b" rel="nofollow noopener noreferrer" target="_blank">t</a><br>дальше',
            $out,
        );
    }

    public function testLegacyPreBlock(): void
    {
        $this->assertSame("<pre>a &lt; b\nc</pre>", R::toHtml("```a < b\nc```", 'Markdown'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function loneMarkerProvider(): iterable
    {
        yield 'lone star'       => ['5 * 3 = 15'];
        yield 'lone underscore' => ['file_name'];
        yield 'trailing star'   => ['ok *'];
        yield 'double star'     => ['**'];
        yield 'lone backtick'   => ['a ` b'];
        yield 'open bracket'    => ['[не ссылка'];
        yield 'broken link'     => ['[t](https://x'];
        yield 'bad utf8'        => ["\xC3\x28 *x*"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('loneMarkerProvider')]
    public function testLoneMarkersDoNotBreak(string $text): void
    {
        foreach (['Markdown', 'MarkdownV2'] as $mode) {
            $out = R::toHtml($text, $mode);
            $this->assertNotSame('', $out);
            $this->assertInert($out);
        }
        $this->assertSame('5 * 3 = 15', R::toHtml('5 * 3 = 15', 'Markdown'));
        $this->assertSame('file_name', R::toHtml('file_name', 'Markdown'));
    }

    // ---------------------------------------------------------------- MarkdownV2

    public function testMarkdownV2(): void
    {
        $out = R::toHtml('*жир _кур_* __под__ ~зач~ \\*не\\* ||спойлер|| [t](https://x.io/\\(1\\))', 'MarkdownV2');
        $this->assertSame(
            '<b>жир <i>кур</i></b> <u>под</u> <s>зач</s> *не* спойлер '
            . '<a href="https://x.io/(1)" rel="nofollow noopener noreferrer" target="_blank">t</a>',
            $out,
        );
    }

    // ---------------------------------------------------------------- HTML

    public function testHtmlAllowList(): void
    {
        $out = R::toHtml("<strong>a</strong> <em>b</em> <ins>c</ins> <del>d</del>\n<pre><code class=\"language-php\">x\ny</code></pre> &lt;tag&gt; AT&T <tg-spoiler>s</tg-spoiler>", 'HTML');
        $this->assertSame(
            "<b>a</b> <i>b</i> <u>c</u> <s>d</s><br><pre><code>x\ny</code></pre> &lt;tag&gt; AT&amp;T s",
            $out,
        );
    }

    public function testHtmlUnbalancedTagsAreClosed(): void
    {
        $this->assertSame('<b><i>x</i></b>y', R::toHtml('<b><i>x</b>y</i>', 'HTML'));
        $this->assertSame('<b>open</b>', R::toHtml('<b>open', 'HTML'));
        $this->assertSame('stray', R::toHtml('stray</b>', 'HTML'));
    }

    public function testHtmlLinkWithSafeUrl(): void
    {
        $this->assertSame(
            '<a href="https://wildworld.fun/a?b=1&amp;c=2" rel="nofollow noopener noreferrer" target="_blank">сайт</a>',
            R::toHtml('<a href="https://wildworld.fun/a?b=1&amp;c=2">сайт</a>', 'HTML'),
        );
    }

    // ---------------------------------------------------------------- Ask 3: no truncation

    public function testLongCaptionRendersInFull(): void
    {
        $caption = str_repeat('Ржавая труба. ', 120) . 'КОНЕЦ';
        $this->assertGreaterThan(1024, mb_strlen($caption));

        foreach (['Markdown', 'MarkdownV2', 'HTML', null] as $mode) {
            $out = R::toHtml($caption, $mode);
            $this->assertSame($caption, $out, (string) $mode);
        }
    }
}
