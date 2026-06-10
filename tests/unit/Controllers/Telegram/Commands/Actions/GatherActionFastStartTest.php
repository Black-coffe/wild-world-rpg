<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers\Telegram\Commands\Actions;

use App\Controllers\Telegram\Commands\Actions\GatherAction;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-104 Ф2 — «быстрый первый цикл». Чистое решение decideFastGatherMinutes
 * (показать ли быструю опцию добычи новичку и на сколько минут).
 *
 * @internal
 */
final class GatherActionFastStartTest extends CIUnitTestCase
{
    public function testDormantWhenDisabled(): void
    {
        // enabled=false → всегда null, какой бы ни был уровень.
        $this->assertNull(GatherAction::decideFastGatherMinutes(false, 1, 3, 2));
        $this->assertNull(GatherAction::decideFastGatherMinutes(false, 99, 3, 2));
    }

    public function testEligibleNewbieGetsFastMinutes(): void
    {
        $this->assertSame(2, GatherAction::decideFastGatherMinutes(true, 1, 3, 2));
        $this->assertSame(2, GatherAction::decideFastGatherMinutes(true, 3, 3, 2));
        $this->assertSame(5, GatherAction::decideFastGatherMinutes(true, 2, 3, 5));
    }

    public function testVeteranAboveMaxLevelExcluded(): void
    {
        $this->assertNull(GatherAction::decideFastGatherMinutes(true, 4, 3, 2));
        $this->assertNull(GatherAction::decideFastGatherMinutes(true, 50, 3, 2));
    }

    public function testZeroOrNegativeMinutesIsNull(): void
    {
        // hard_min 1 в GameSettings, но защищаемся и в коде.
        $this->assertNull(GatherAction::decideFastGatherMinutes(true, 1, 3, 0));
        $this->assertNull(GatherAction::decideFastGatherMinutes(true, 1, 3, -5));
    }

    public function testMaxLevelBoundary(): void
    {
        // level == maxLevel → попадает; level == maxLevel+1 → нет.
        $this->assertSame(2, GatherAction::decideFastGatherMinutes(true, 6, 6, 2));
        $this->assertNull(GatherAction::decideFastGatherMinutes(true, 7, 6, 2));
    }
}
