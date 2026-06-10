<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Player;

use App\Services\Player\LastSeenService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ROADMAP-100 E6 (ADR-108) Фаза 1 — pure-хелперы LastSeenService
 * (extractTelegramId / hoursAgo). Без БД/Telegram-моков.
 *
 * @internal
 */
final class LastSeenServiceTest extends CIUnitTestCase
{
    // ---- extractTelegramId ----

    public function testExtractFromMessage(): void
    {
        $u = ['update_id' => 1, 'message' => ['from' => ['id' => 12345]]];
        $this->assertSame(12345, LastSeenService::extractTelegramId($u));
    }

    public function testExtractFromCallbackQuery(): void
    {
        $u = ['update_id' => 2, 'callback_query' => ['from' => ['id' => 678], 'data' => 'move_dir_north']];
        $this->assertSame(678, LastSeenService::extractTelegramId($u));
    }

    public function testExtractFromEditedMessage(): void
    {
        $u = ['edited_message' => ['from' => ['id' => 999]]];
        $this->assertSame(999, LastSeenService::extractTelegramId($u));
    }

    public function testExtractNumericStringId(): void
    {
        // Telegram присылает id числом, но защищаемся и от строкового.
        $u = ['message' => ['from' => ['id' => '4242']]];
        $this->assertSame(4242, LastSeenService::extractTelegramId($u));
    }

    public function testExtractReturnsNullWhenNoFrom(): void
    {
        $this->assertNull(LastSeenService::extractTelegramId(['update_id' => 5]));
        $this->assertNull(LastSeenService::extractTelegramId(['message' => ['text' => 'hi']]));
        $this->assertNull(LastSeenService::extractTelegramId([]));
    }

    public function testExtractRejectsNonPositiveOrNonNumeric(): void
    {
        $this->assertNull(LastSeenService::extractTelegramId(['message' => ['from' => ['id' => 0]]]));
        $this->assertNull(LastSeenService::extractTelegramId(['message' => ['from' => ['id' => 'abc']]]));
    }

    // ---- hoursAgo ----

    public function testHoursAgoNullForEmpty(): void
    {
        $this->assertNull(LastSeenService::hoursAgo(null));
        $this->assertNull(LastSeenService::hoursAgo(''));
        $this->assertNull(LastSeenService::hoursAgo('не-дата'));
    }

    public function testHoursAgoCountsFullHours(): void
    {
        $now = strtotime('2026-06-10 20:00:00');
        $this->assertSame(0, LastSeenService::hoursAgo('2026-06-10 19:30:00', $now)); // 30 мин = 0 полных часов
        $this->assertSame(1, LastSeenService::hoursAgo('2026-06-10 19:00:00', $now));
        $this->assertSame(25, LastSeenService::hoursAgo('2026-06-09 19:00:00', $now)); // > сутки
    }

    public function testHoursAgoFutureClampsToZero(): void
    {
        $now = strtotime('2026-06-10 20:00:00');
        $this->assertSame(0, LastSeenService::hoursAgo('2026-06-10 22:00:00', $now));
    }

    public function testHoursAgoSleepingThresholdBoundary(): void
    {
        // NightAttacks sleeping_player_skip:12 — спящий = last_seen > 12ч.
        $now = strtotime('2026-06-10 03:00:00');
        $this->assertSame(13, LastSeenService::hoursAgo('2026-06-09 14:00:00', $now)); // > 12 → спит
        $this->assertSame(12, LastSeenService::hoursAgo('2026-06-09 15:00:00', $now)); // == 12 → ещё бьём (не > 12)
    }
}
