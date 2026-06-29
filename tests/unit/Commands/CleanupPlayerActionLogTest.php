<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use App\Commands\CleanupPlayerActionLog;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-148 (ротация) — чистая логика кап кольцевого буфера player_action_log.
 * SQL-удаление (TTL + кап) проверяется Tier-3 на testbot (вставка old/over-cap → cleanup).
 *
 * @internal
 */
final class CleanupPlayerActionLogTest extends CIUnitTestCase
{
    public function testExcessWhenOverCap(): void
    {
        $this->assertSame(50, CleanupPlayerActionLog::excessRows(150, 100));
    }

    public function testNoExcessWhenUnderCap(): void
    {
        $this->assertSame(0, CleanupPlayerActionLog::excessRows(80, 100));
    }

    public function testNoExcessWhenExactlyAtCap(): void
    {
        $this->assertSame(0, CleanupPlayerActionLog::excessRows(100, 100));
    }

    public function testCapDisabledWhenMaxRowsZeroOrNegative(): void
    {
        $this->assertSame(0, CleanupPlayerActionLog::excessRows(999999, 0));
        $this->assertSame(0, CleanupPlayerActionLog::excessRows(999999, -10));
    }

    public function testEmptyTableNoExcess(): void
    {
        $this->assertSame(0, CleanupPlayerActionLog::excessRows(0, 100));
    }
}
