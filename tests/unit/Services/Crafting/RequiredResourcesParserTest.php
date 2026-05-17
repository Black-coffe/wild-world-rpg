<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Crafting;

use App\Services\Crafting\RequiredResourcesParser;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 * @covers \App\Services\Crafting\RequiredResourcesParser
 */
final class RequiredResourcesParserTest extends CIUnitTestCase
{
    private RequiredResourcesParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new RequiredResourcesParser();
    }

    // ── empty / null / corrupt ──────────────────────────────────────────

    public function testNullReturnsEmpty(): void
    {
        $this->assertSame([], $this->parser->parse(null));
    }

    public function testEmptyStringReturnsEmpty(): void
    {
        $this->assertSame([], $this->parser->parse(''));
        $this->assertSame([], $this->parser->parse('   '));
        $this->assertSame([], $this->parser->parse('[]'));
        $this->assertSame([], $this->parser->parse('{}'));
    }

    public function testCorruptInputReturnsEmpty(): void
    {
        // Just punctuation / no parseable structure
        $this->assertSame([], $this->parser->parse('!!! @@@ ###'));
    }

    // ── v1a free-text (newline-separated, "Имя - N шт.") ────────────────

    public function testV1aFreeTextNewlineSeparated(): void
    {
        $raw = "Ягоды - 2 шт.\nТравы - 1 шт.";
        $this->assertSame(['Ягоды' => 2, 'Травы' => 1], $this->parser->parse($raw));
    }

    public function testV1aWithMilliliters(): void
    {
        $raw = "Ягоды - 5 шт.\nТравы - 3 шт.\nВода - 200 мл.";
        $this->assertSame(['Ягоды' => 5, 'Травы' => 3, 'Вода' => 200], $this->parser->parse($raw));
    }

    public function testV1aMultiwordResource(): void
    {
        $raw = "Кора деревьев - 3 шт.\nЯдовитые растения - 1 шт.";
        $this->assertSame(['Кора деревьев' => 3, 'Ядовитые растения' => 1], $this->parser->parse($raw));
    }

    // ── v1b free-text (comma-separated) ─────────────────────────────────

    public function testV1bFreeTextCommaSeparated(): void
    {
        $raw = "Wool - 4 шт., Leather - 2 шт.";
        $this->assertSame(['Wool' => 4, 'Leather' => 2], $this->parser->parse($raw));
    }

    public function testV1bThreeComponentsComma(): void
    {
        $raw = "Cloth - 3 шт., Plant fibers - 4 шт., Dye - 1 шт.";
        $this->assertSame(['Cloth' => 3, 'Plant fibers' => 4, 'Dye' => 1], $this->parser->parse($raw));
    }

    // ── v2 PHP-array-like (legacy) ──────────────────────────────────────

    public function testV2PhpArrayLike(): void
    {
        $raw = "'Кактус' => 4,\n'Грибы' => 6,\n'Вода' => 50,";
        $this->assertSame(['Кактус' => 4, 'Грибы' => 6, 'Вода' => 50], $this->parser->parse($raw));
    }

    public function testV2WithDoubleQuotes(): void
    {
        $raw = '"Древесина" => 100, "Вода" => 200';
        $this->assertSame(['Древесина' => 100, 'Вода' => 200], $this->parser->parse($raw));
    }

    // ── v3 JSON object (canonical) ──────────────────────────────────────

    public function testV3JsonObjectPassthrough(): void
    {
        $raw = '{"Древесина": 100, "Вода": 200, "Глина": 60}';
        $this->assertSame(['Древесина' => 100, 'Вода' => 200, 'Глина' => 60], $this->parser->parse($raw));
    }

    public function testV3SanitizeNegativeQuantity(): void
    {
        $raw = '{"Древесина": 100, "Вода": -5, "Глина": 0}';
        // Negative/zero qty filtered out
        $this->assertSame(['Древесина' => 100], $this->parser->parse($raw));
    }

    public function testV3SanitizeNonNumericQuantity(): void
    {
        $raw = '{"Древесина": "abc", "Вода": 50}';
        $this->assertSame(['Вода' => 50], $this->parser->parse($raw));
    }

    // ── encode (roundtrip) ──────────────────────────────────────────────

    public function testEncodeCanonical(): void
    {
        $this->assertSame('{}', $this->parser->encode([]));
        $this->assertSame(
            '{"Древесина":100,"Вода":200}',
            $this->parser->encode(['Древесина' => 100, 'Вода' => 200]),
        );
    }

    public function testRoundtripV1(): void
    {
        $raw      = "Ягоды - 2 шт.\nТравы - 1 шт.";
        $parsed   = $this->parser->parse($raw);
        $encoded  = $this->parser->encode($parsed);
        $reparsed = $this->parser->parse($encoded);
        $this->assertSame($parsed, $reparsed);
    }

    // ── Idempotent check (canonical → canonical) ────────────────────────

    public function testIdempotent(): void
    {
        $raw = '{"Травы": 2, "Кора деревьев": 3}';
        $this->assertSame(
            $this->parser->parse($raw),
            $this->parser->parse($this->parser->encode($this->parser->parse($raw))),
        );
    }
}
