<?php

declare(strict_types=1);

namespace Tests\Unit\Services\World;

use App\Services\World\IslandPulseService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * S7 (ROADMAP-RETENTION-10, ADR-145) — «живой остров» (IslandPulseService).
 *
 * Лочит чистые форматтеры (agoLabel), сборку правдивого текста (zero-omit, «тихо»-fallback,
 * markdown-баланс), тизер и snapshot-оркестрацию через test-double (без БД). 🔴 Инвариант
 * честности: только true-data — здесь проверяем, что 0 не показывается как пустая строка, а
 * пустое окно честно говорит «тихо» с опорой на кумулятив. Tier-3 — живой тап кнопки на карте.
 *
 * @internal
 */
final class IslandPulseServiceTest extends CIUnitTestCase
{
    // ── agoLabel (чистый) ───────────────────────────────────────────────────────

    public function testAgoLabel(): void
    {
        $this->assertSame('только что', IslandPulseService::agoLabel(0));
        $this->assertSame('5 мин назад', IslandPulseService::agoLabel(5));
        $this->assertSame('59 мин назад', IslandPulseService::agoLabel(59));
        $this->assertSame('1 ч назад', IslandPulseService::agoLabel(60));
        $this->assertSame('4 ч назад', IslandPulseService::agoLabel(283));
        $this->assertSame('1 дн назад', IslandPulseService::agoLabel(1440));
        $this->assertSame('3 дн назад', IslandPulseService::agoLabel(4320));
    }

    // ── buildIslandText ──────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private static function fullSnap(): array
    {
        return [
            'cells'            => 850,
            'movers'           => 22,
            'survivors_period' => 13,
            'survivors_total'  => 548,
            'last_base'        => ['mins' => 283, 'x' => 574, 'y' => 930],
            'skirmishes'       => 1,
            'window_hours'     => 24,
            'survivors_days'   => 7,
        ];
    }

    public function testBuildTextFullContainsAllTruthfulLines(): void
    {
        $t = (new IslandPulseService())->buildIslandText(self::fullSnap());
        $this->assertStringContainsString('850', $t);
        $this->assertStringContainsString('за сутки', $t);
        $this->assertStringContainsString('22', $t);
        $this->assertStringContainsString('4 ч назад', $t);
        $this->assertStringContainsString('574/930', $t);
        $this->assertStringContainsString('За неделю', $t);
        $this->assertStringContainsString('548', $t);
        $this->assertStringContainsString('настоящие', $t);
    }

    public function testBuildTextOmitsZeroLines(): void
    {
        $s = self::fullSnap();
        $s['cells']      = 0;
        $s['skirmishes'] = 0;
        $s['last_base']  = null;
        $t = (new IslandPulseService())->buildIslandText($s);
        // нулевые строки НЕ показываются (честно — не пишем «0 клеток / 0 стычек / 0 баз»)
        $this->assertStringNotContainsString('клеток пустоши', $t);
        $this->assertStringNotContainsString('Стычек', $t);
        $this->assertStringNotContainsString('Последняя база', $t);
        // ненулевые остаются
        $this->assertStringContainsString('13', $t);
        $this->assertStringContainsString('548', $t);
    }

    public function testBuildTextQuietFallbackWhenAllZero(): void
    {
        $s = [
            'cells' => 0, 'movers' => 0, 'survivors_period' => 0, 'survivors_total' => 548,
            'last_base' => null, 'skirmishes' => 0, 'window_hours' => 24, 'survivors_days' => 7,
        ];
        $t = (new IslandPulseService())->buildIslandText($s);
        $this->assertStringContainsString('тихо', $t);
        $this->assertStringContainsString('548', $t, 'fallback опирается на кумулятив (всегда > 0)');
        $this->assertStringContainsString('не один', $t);
    }

    public function testBuildTextMarkdownBalanced(): void
    {
        foreach ([self::fullSnap(), ['cells' => 0, 'movers' => 0, 'survivors_period' => 0, 'survivors_total' => 9, 'last_base' => null, 'skirmishes' => 0, 'window_hours' => 24, 'survivors_days' => 7]] as $s) {
            $t = (new IslandPulseService())->buildIslandText($s);
            $this->assertSame(0, substr_count($t, '*') % 2, 'парные *');
            $this->assertSame(0, substr_count($t, '_') % 2, 'парные _');
        }
    }

    public function testWindowLabelNonDefaultHours(): void
    {
        $s = self::fullSnap();
        $s['window_hours'] = 12;
        $t = (new IslandPulseService())->buildIslandText($s);
        $this->assertStringContainsString('за 12 ч', $t);
    }

    // ── teaserLine ────────────────────────────────────────────────────────────────

    public function testTeaserPrefersFreshCells(): void
    {
        $teaser = (new IslandPulseService())->teaserLine(self::fullSnap());
        $this->assertNotNull($teaser);
        $this->assertStringContainsString('850', $teaser);
        $this->assertSame(0, substr_count($teaser, '*') % 2);
    }

    public function testTeaserFallsBackToTotal(): void
    {
        $s = self::fullSnap();
        $s['cells'] = 0;
        $teaser = (new IslandPulseService())->teaserLine($s);
        $this->assertNotNull($teaser);
        $this->assertStringContainsString('548', $teaser);
    }

    public function testTeaserNullWhenNothing(): void
    {
        $s = self::fullSnap();
        $s['cells'] = 0;
        $s['survivors_total'] = 0;
        $this->assertNull((new IslandPulseService())->teaserLine($s));
    }

    // ── Гейт + snapshot оркестрация (через test-double) ─────────────────────────

    public function testEnabledGate(): void
    {
        $on = new FakeIslandPulseService();
        $on->on = true;
        $this->assertTrue($on->enabled());
        $off = new FakeIslandPulseService();
        $off->on = false;
        $this->assertFalse($off->enabled());
    }

    public function testSnapshotAssemblesSeams(): void
    {
        $svc = new FakeIslandPulseService();
        $snap = $svc->snapshot();
        $this->assertSame(850, $snap['cells']);
        $this->assertSame(22, $snap['movers']);
        $this->assertSame(13, $snap['survivors_period']);
        $this->assertSame(548, $snap['survivors_total']);
        $this->assertSame(1, $snap['skirmishes']);
        $this->assertSame(['mins' => 283, 'x' => 574, 'y' => 930], $snap['last_base']);
        $this->assertSame(24, $snap['window_hours']);
        $this->assertSame(7, $snap['survivors_days']);
    }

    public function testPayloadHasBackToMapButton(): void
    {
        $svc = new FakeIslandPulseService();
        $p = $svc->payload();
        $this->assertArrayHasKey('text', $p);
        $this->assertStringContainsString('move', $p['reply_markup'], 'кнопка ⬅️ К карте → callback move');
    }
}

/**
 * Test-double: детерминированные агрегаты + killswitch без БД.
 *
 * @internal
 */
final class FakeIslandPulseService extends IslandPulseService
{
    public bool $on = true;

    protected function gsBool(string $key, bool $default): bool
    {
        return $this->on;
    }

    protected function gsInt(string $key, int $default): int
    {
        return $default; // окна = дефолтные (24ч / 7д)
    }

    protected function cellsExplored(int $hours): int
    {
        return 850;
    }

    protected function distinctMovers(int $hours): int
    {
        return 22;
    }

    protected function survivorsLanded(int $days): int
    {
        return 13;
    }

    protected function survivorsTotal(): int
    {
        return 548;
    }

    protected function skirmishes(int $hours): int
    {
        return 1;
    }

    protected function lastBase(): ?array
    {
        return ['mins' => 283, 'x' => 574, 'y' => 930];
    }
}
