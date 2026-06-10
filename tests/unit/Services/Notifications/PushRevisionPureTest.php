<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Notifications;

use App\Services\Notifications\NotificationDedupService;
use App\Services\Notifications\SilentNotificationPolicy;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * E6 (ADR-108) Фаза 4 — чистые хелперы ревизии push:
 * SilentNotificationPolicy::isHourInWindow + NotificationDedupService::cacheKey.
 *
 * @internal
 */
final class PushRevisionPureTest extends CIUnitTestCase
{
    // ── isHourInWindow ────────────────────────────────────────────────────────

    public function testWindowSameDay(): void
    {
        // 9..17 — рабочее окно (не через полночь).
        $this->assertTrue(SilentNotificationPolicy::isHourInWindow(9, 9, 17));
        $this->assertTrue(SilentNotificationPolicy::isHourInWindow(16, 9, 17));
        $this->assertFalse(SilentNotificationPolicy::isHourInWindow(17, 9, 17)); // end эксклюзивен
        $this->assertFalse(SilentNotificationPolicy::isHourInWindow(8, 9, 17));
    }

    public function testWindowAcrossMidnight(): void
    {
        // 23..8 — ночь (через полночь).
        $this->assertTrue(SilentNotificationPolicy::isHourInWindow(23, 23, 8));
        $this->assertTrue(SilentNotificationPolicy::isHourInWindow(0, 23, 8));
        $this->assertTrue(SilentNotificationPolicy::isHourInWindow(3, 23, 8));
        $this->assertTrue(SilentNotificationPolicy::isHourInWindow(7, 23, 8));
        $this->assertFalse(SilentNotificationPolicy::isHourInWindow(8, 23, 8)); // end эксклюзивен
        $this->assertFalse(SilentNotificationPolicy::isHourInWindow(12, 23, 8));
        $this->assertFalse(SilentNotificationPolicy::isHourInWindow(22, 23, 8));
    }

    public function testWindowEmptyWhenStartEqualsEnd(): void
    {
        $this->assertFalse(SilentNotificationPolicy::isHourInWindow(5, 0, 0));
        $this->assertFalse(SilentNotificationPolicy::isHourInWindow(12, 12, 12));
    }

    public function testWindowNormalizesOutOfRange(): void
    {
        // Защита от мусора (час 24 → 0, отрицательный → mod).
        $this->assertTrue(SilentNotificationPolicy::isHourInWindow(24, 23, 8)); // 24→0, ночь
        $this->assertTrue(SilentNotificationPolicy::isHourInWindow(-1, 23, 8)); // -1→23, ночь
    }

    // ── dedup cacheKey ────────────────────────────────────────────────────────

    public function testCacheKeyDeterministicAndSafe(): void
    {
        $k1 = NotificationDedupService::cacheKey(123, 'Крафт готов');
        $k2 = NotificationDedupService::cacheKey(123, 'Крафт готов');
        $this->assertSame($k1, $k2);
        $this->assertMatchesRegularExpression('/^notifdedup_123_[a-f0-9]{32}$/', $k1);
    }

    public function testCacheKeyDiffersByChatAndBody(): void
    {
        $this->assertNotSame(
            NotificationDedupService::cacheKey(1, 'X'),
            NotificationDedupService::cacheKey(2, 'X'),
        );
        $this->assertNotSame(
            NotificationDedupService::cacheKey(1, 'X'),
            NotificationDedupService::cacheKey(1, 'Y'),
        );
    }
}
