<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Onboarding;

use App\Services\Onboarding\ColdOpenSignalService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * S4 (ROADMAP-RETENTION-10, ADR-139, спина-слайс 3) — cold-open радио-крючок + bait у спавна.
 *
 * DB/настройки/Telegram подменены seam'ами (CI без БД); проверяется логика гейтов, выбора
 * направления, маркера, выдачи находки и тексты (media-off/markdown). Tier-3 — живой тап на testbot.
 *
 * @internal
 */
final class ColdOpenSignalServiceTest extends CIUnitTestCase
{
    private function svc(bool $enabled = true): FakeColdOpenSignalService
    {
        $s = new FakeColdOpenSignalService();
        $s->on = $enabled;

        return $s;
    }

    // ── byte-identical при OFF ────────────────────────────────────────────────

    public function testDisabledIsByteIdentical(): void
    {
        $s = $this->svc(false);
        $s->landCells['10_917'] = ['id' => 50, 'biome_id' => 1];

        $this->assertNull($s->placeBaitForNewChar(7, ['coordinate_x' => 10, 'coordinate_y' => 920]));
        $this->assertSame([], $s->markerCellsInViewport(1, 0, 20, 900, 999));
        $this->assertFalse($s->tryReachBait(['id' => 7, 'level' => 1], ['id' => 50], 123));

        $this->assertSame([], $s->inserted);
        $this->assertSame([], $s->grantedGold);
        $this->assertSame([], $s->cleared);
        $this->assertSame([], $s->sent);
    }

    // ── placeBaitForNewChar ───────────────────────────────────────────────────

    public function testPlaceBaitReturnsNarrativeAndInserts(): void
    {
        $s = $this->svc();
        $s->landCells['10_917'] = ['id' => 50, 'biome_id' => 3]; // north (dist 3): (10, 917)

        $text = $s->placeBaitForNewChar(7, ['coordinate_x' => 10, 'coordinate_y' => 920]);

        $this->assertNotNull($text);
        $this->assertStringContainsString('🎯', (string) $text);
        $this->assertStringContainsString('к северу', (string) $text);
        $this->assertCount(1, $s->inserted);
        $this->assertSame(99, $s->inserted[0]['anchorId']);
        $this->assertSame(50, $s->inserted[0]['cell']['id']);
    }

    public function testPlaceBaitNullWhenNoAnchor(): void
    {
        $s = $this->svc();
        $s->anchor = 0; // не засеян
        $s->landCells['10_917'] = ['id' => 50, 'biome_id' => 1];

        $this->assertNull($s->placeBaitForNewChar(7, ['coordinate_x' => 10, 'coordinate_y' => 920]));
        $this->assertSame([], $s->inserted);
    }

    public function testPlaceBaitNullWhenNoLandCell(): void
    {
        $s = $this->svc(); // landCells пуст → ни один кандидат не резолвится
        $this->assertNull($s->placeBaitForNewChar(7, ['coordinate_x' => 10, 'coordinate_y' => 920]));
        $this->assertSame([], $s->inserted);
    }

    public function testPlaceBaitTriesDirectionsInOrder(): void
    {
        $s = $this->svc();
        // north (10,917) отсутствует, northeast (13,917) есть → берётся второй кандидат.
        $s->landCells['13_917'] = ['id' => 60, 'biome_id' => 2];

        $text = $s->placeBaitForNewChar(7, ['coordinate_x' => 10, 'coordinate_y' => 920]);

        $this->assertNotNull($text);
        $this->assertStringContainsString('к северо-востоку', (string) $text);
        $this->assertSame(60, $s->inserted[0]['cell']['id']);
    }

    // ── markerCellsInViewport ─────────────────────────────────────────────────

    public function testMarkerVeteranEmpty(): void
    {
        $s = $this->svc();
        $s->baitMarker = ['5_10' => true];
        // level 7 > cap 6 → ветеран не платит за маркер.
        $this->assertSame([], $s->markerCellsInViewport(7, 0, 20, 0, 20));
    }

    public function testMarkerMapsCells(): void
    {
        $s = $this->svc();
        $s->baitMarker = ['5_10' => true, '12_8' => true];

        $cells = $s->markerCellsInViewport(1, 0, 20, 0, 20);

        $this->assertSame(['5_10' => true, '12_8' => true], $cells);
    }

    // ── tryReachBait ──────────────────────────────────────────────────────────

    public function testTryReachAwardsClearsAndSends(): void
    {
        $s = $this->svc();
        $s->baitAt = 777; // активная приманка на клетке

        $ok = $s->tryReachBait(['id' => 7, 'level' => 1], ['id' => 50], 123);

        $this->assertTrue($ok);
        $this->assertSame([[7, 200]], $s->grantedGold);
        $this->assertSame([[7, 2, 5]], $s->grantedResource);
        $this->assertSame([777], $s->cleared);
        $this->assertCount(1, $s->sent);
        $sentText = $s->sent[0]['text'] ?? null;
        $this->assertIsString($sentText);
        $this->assertStringContainsString('Источник найден', $sentText);
    }

    public function testTryReachNoBaitOnCell(): void
    {
        $s = $this->svc();
        $s->baitAt = 0; // нет активной приманки

        $this->assertFalse($s->tryReachBait(['id' => 7, 'level' => 1], ['id' => 50], 123));
        $this->assertSame([], $s->grantedGold);
        $this->assertSame([], $s->cleared);
    }

    public function testTryReachVeteranSkips(): void
    {
        $s = $this->svc();
        $s->baitAt = 777;
        $this->assertFalse($s->tryReachBait(['id' => 7, 'level' => 9], ['id' => 50], 123));
        $this->assertSame([], $s->cleared);
    }

    // ── Тексты (media-off / markdown) ─────────────────────────────────────────

    public function testNarrativeMarkdownBalanced(): void
    {
        $s = $this->svc();
        $text = $s->buildSignalNarrative('к северу', '⬆️');
        $this->assertSame(0, substr_count($text, '*') % 2, 'непарные звёздочки в нарративе');
        $this->assertStringContainsString('🎯', $text);
    }

    public function testFoundTextContentAndBalance(): void
    {
        $s = $this->svc();
        $text = $s->buildFoundText(200, 2, 5);
        $this->assertStringContainsString('🪙 Монеты ×200', $text);
        $this->assertStringContainsString('×5', $text);
        $this->assertSame(0, substr_count($text, '*') % 2, 'непарные звёздочки в находке');
    }

    public function testFoundTextGoldOnly(): void
    {
        $s = $this->svc();
        $text = $s->buildFoundText(200, 0, 0); // ресурс выключен
        $this->assertStringContainsString('🪙 Монеты ×200', $text);
        $this->assertStringNotContainsString('📦', $text);
    }
}

/**
 * Test-double: все DB/настройки/Telegram-seam'ы подменены детерминированными значениями + захват.
 *
 * @internal
 */
final class FakeColdOpenSignalService extends ColdOpenSignalService
{
    public bool $on = true;
    public int $anchor = 99;

    /** @var array<string, array<string, mixed>> ключ "x_y" → map-row */
    public array $landCells = [];

    /** @var array<string, true> прибранный маркер-результат для viewport */
    public array $baitMarker = [];

    public int $baitAt = 0;

    /** @var list<array{anchorId:int, cell:array<int|string, mixed>}> */
    public array $inserted = [];
    /** @var list<array{0:int,1:int}> */
    public array $grantedGold = [];
    /** @var list<array{0:int,1:int,2:int}> */
    public array $grantedResource = [];
    /** @var list<int> */
    public array $cleared = [];
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    protected function enabled(): bool
    {
        return $this->on;
    }

    protected function maxLevel(): int
    {
        return 6;
    }

    protected function gold(): int
    {
        return 200;
    }

    protected function resourceId(): int
    {
        return 2;
    }

    protected function resourceQty(): int
    {
        return 5;
    }

    protected function distance(): int
    {
        return 3;
    }

    protected function anchorObjectId(): int
    {
        return $this->anchor;
    }

    protected function findLandCell(int $x, int $y): ?array
    {
        return $this->landCells["{$x}_{$y}"] ?? null;
    }

    protected function insertBait(int $anchorId, array $cell): void
    {
        $this->inserted[] = ['anchorId' => $anchorId, 'cell' => $cell];
    }

    protected function baitMarkerCells(int $xMin, int $xMax, int $yMin, int $yMax): array
    {
        return $this->baitMarker;
    }

    protected function activeBaitAt(int $cellId): int
    {
        return $this->baitAt;
    }

    protected function clearBait(int $baitMapRowId): void
    {
        $this->cleared[] = $baitMapRowId;
    }

    protected function grantGold(int $charId, int $gold): void
    {
        $this->grantedGold[] = [$charId, $gold];
    }

    protected function grantResource(int $charId, int $resourceId, int $amount): void
    {
        $this->grantedResource[] = [$charId, $resourceId, $amount];
    }

    protected function resourceTitle(int $resourceId): string
    {
        return 'Древесина';
    }

    protected function send(array $payload): void
    {
        $this->sent[] = $payload;
    }
}
