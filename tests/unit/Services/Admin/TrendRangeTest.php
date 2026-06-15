<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Admin;

use App\Services\Admin\TrendRange;
use CodeIgniter\Test\CIUnitTestCase;
use DateTimeImmutable;

/**
 * Окно тренда дашборда: пресеты + произвольный диапазон с валидацией/нормализацией.
 *
 * @internal
 */
final class TrendRangeTest extends CIUnitTestCase
{
    private function today(): string
    {
        return (new DateTimeImmutable('today'))->format('Y-m-d');
    }

    public function testPresetEndsTodayWithInclusiveCount(): void
    {
        $r = TrendRange::preset(7);

        $this->assertFalse($r->custom);
        $this->assertSame(7, $r->days);
        $this->assertSame($this->today(), $r->end);
        $this->assertSame((new DateTimeImmutable('today'))->modify('-6 days')->format('Y-m-d'), $r->start);
        $this->assertCount(7, $r->dayKeys());
    }

    public function testPresetClampsBounds(): void
    {
        $this->assertSame(1, TrendRange::preset(0)->days);
        $this->assertSame(1, TrendRange::preset(-5)->days);
        $this->assertSame(TrendRange::MAX_SPAN_DAYS, TrendRange::preset(99999)->days);
    }

    public function testFromDatesValidPastRange(): void
    {
        $r = TrendRange::fromDates('2000-06-01', '2000-06-10');

        $this->assertNotNull($r);
        $this->assertTrue($r->custom);
        $this->assertSame('2000-06-01', $r->start);
        $this->assertSame('2000-06-10', $r->end);
        $this->assertSame(10, $r->days);
        $this->assertCount(10, $r->dayKeys());
        $this->assertSame('2000-06-01', $r->dayKeys()[0]);
        $this->assertSame('2000-06-10', $r->dayKeys()[9]);
    }

    public function testFromDatesSwapsReversedOrder(): void
    {
        $r = TrendRange::fromDates('2000-06-10', '2000-06-01');

        $this->assertNotNull($r);
        $this->assertSame('2000-06-01', $r->start);
        $this->assertSame('2000-06-10', $r->end);
        $this->assertSame(10, $r->days);
    }

    public function testFromDatesRejectsInvalid(): void
    {
        $this->assertNull(TrendRange::fromDates('abc', '2000-06-10'));
        $this->assertNull(TrendRange::fromDates('2026-13-40', '2026-06-10')); // переполнение
        $this->assertNull(TrendRange::fromDates('', '2000-06-10'));
        $this->assertNull(TrendRange::fromDates(null, null));
        $this->assertNull(TrendRange::fromDates('2000/06/01', '2000-06-10')); // не тот формат
    }

    public function testFromDatesCapsSpanAndClampsFutureToToday(): void
    {
        $r = TrendRange::fromDates('2000-01-01', '2999-12-31');

        $this->assertNotNull($r);
        $this->assertSame($this->today(), $r->end);           // будущее → сегодня
        $this->assertSame(TrendRange::MAX_SPAN_DAYS, $r->days); // ширина → потолок
        $this->assertSame(
            (new DateTimeImmutable($r->end))->modify('-' . (TrendRange::MAX_SPAN_DAYS - 1) . ' days')->format('Y-m-d'),
            $r->start
        );
    }

    public function testResolveFallsBackToPreset(): void
    {
        $this->assertFalse(TrendRange::resolve(null, null, 30)->custom);
        $this->assertSame(30, TrendRange::resolve('bad', 'bad', 30)->days);
        $this->assertTrue(TrendRange::resolve('2000-06-01', '2000-06-03', 30)->custom);
    }

    public function testSqlBoundsAndLabel(): void
    {
        $r = TrendRange::fromDates('2000-06-01', '2000-06-10');
        $this->assertNotNull($r);

        $this->assertSame('2000-06-01 00:00:00', $r->startSql());
        $this->assertSame('2000-06-11 00:00:00', $r->endExclusiveSql()); // end + 1 день
        $this->assertSame('01.06.2000 – 10.06.2000 (10 дн.)', $r->label());
        $this->assertSame('за 7 дн.', TrendRange::preset(7)->label());
    }
}
