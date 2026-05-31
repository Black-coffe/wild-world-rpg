<?php

namespace Tests\Unit\Services\Notifications;

use App\Services\Notifications\BroadcastDeliveryClassifier;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Тесты классификатора результата broadcast-отправки (находка 2026-05-31:
 * Longman не бросает на 403/429 → нужно классифицировать getDescription).
 *
 * @internal
 */
final class BroadcastDeliveryClassifierTest extends CIUnitTestCase
{
    public function testBlockedByUser(): void
    {
        $this->assertTrue(BroadcastDeliveryClassifier::isBlocked('Forbidden: bot was blocked by the user'));
        $this->assertSame('blocked', BroadcastDeliveryClassifier::classify('Forbidden: bot was blocked by the user'));
    }

    public function testUserDeactivated(): void
    {
        $this->assertTrue(BroadcastDeliveryClassifier::isBlocked('Forbidden: user is deactivated'));
        $this->assertSame('blocked', BroadcastDeliveryClassifier::classify('Forbidden: user is deactivated'));
    }

    public function testChatNotFound(): void
    {
        $this->assertTrue(BroadcastDeliveryClassifier::isBlocked('Bad Request: chat not found'));
    }

    public function testForbiddenGeneric(): void
    {
        $this->assertTrue(BroadcastDeliveryClassifier::isBlocked('Forbidden: bot can\'t initiate conversation with a user'));
    }

    public function testRateLimitedNotBlocked(): void
    {
        $desc = 'Too Many Requests: retry after 5';
        $this->assertFalse(BroadcastDeliveryClassifier::isBlocked($desc));
        $this->assertTrue(BroadcastDeliveryClassifier::isRateLimited($desc));
        $this->assertSame('rate_limited', BroadcastDeliveryClassifier::classify($desc));
    }

    public function testRetryAfterAlsoRateLimited(): void
    {
        $this->assertTrue(BroadcastDeliveryClassifier::isRateLimited('retry after 10'));
    }

    public function testOtherErrorIsOther(): void
    {
        $desc = 'Bad Request: message text is too long';
        $this->assertFalse(BroadcastDeliveryClassifier::isBlocked($desc));
        $this->assertFalse(BroadcastDeliveryClassifier::isRateLimited($desc));
        $this->assertSame('other', BroadcastDeliveryClassifier::classify($desc));
    }

    public function testEmptyDescriptionIsOtherNotBlocked(): void
    {
        $this->assertFalse(BroadcastDeliveryClassifier::isBlocked(''));
        $this->assertSame('other', BroadcastDeliveryClassifier::classify(''));
    }

    public function testCaseInsensitive(): void
    {
        $this->assertTrue(BroadcastDeliveryClassifier::isBlocked('FORBIDDEN: BOT WAS BLOCKED'));
        $this->assertTrue(BroadcastDeliveryClassifier::isRateLimited('TOO MANY REQUESTS'));
    }
}
