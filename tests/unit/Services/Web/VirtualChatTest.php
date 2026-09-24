<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Web;

use App\Services\Web\DeliveryContext;
use App\Services\Web\VirtualChat;
use CodeIgniter\Test\CIUnitTestCase;
use Config\WebPlay;

/**
 * web-bridge-p1-01 (ADR-189 §3) — виртуальный диапазон Telegram-id, держатель актёра доставки и
 * числа `Config\WebPlay`.
 *
 * @internal
 */
final class VirtualChatTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        DeliveryContext::reset();
        parent::tearDown();
    }

    public function testIdForAccountIsVirtualAndBelowTwoToFiftyThree(): void
    {
        foreach ([1, 2, 42, 1000000, 2147483647] as $accountId) {
            $id = VirtualChat::idForAccount($accountId);
            $this->assertTrue(VirtualChat::is($id), "account {$accountId}");
            $this->assertLessThan(2 ** 53, abs($id));
            $this->assertSame($id, (int) (float) $id, 'точно представим в double');
        }
        $this->assertNotSame(VirtualChat::idForAccount(1), VirtualChat::idForAccount(2));
    }

    public function testRealTelegramShapesAreNotVirtual(): void
    {
        $this->assertFalse(VirtualChat::is(0));
        $this->assertFalse(VirtualChat::is(1));
        $this->assertFalse(VirtualChat::is(555000222));
        $this->assertFalse(VirtualChat::is(2 ** 52 - 1), 'максимум 52 значащих бит');
        $this->assertFalse(VirtualChat::is(2 ** 52 + 5), 'положительный — никогда не виртуальный');
        $this->assertFalse(VirtualChat::is(-1001234567890), 'супергруппа');
        $this->assertFalse(VirtualChat::is(-123456789), 'группа');
        $this->assertFalse(VirtualChat::is(-(2 ** 52 - 1)));
        $this->assertFalse(VirtualChat::is(-(2 ** 53)));
    }

    public function testIdForAccountRejectsNonPositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        VirtualChat::idForAccount(0);
    }

    public function testDeliveryContextRoundTrip(): void
    {
        $this->assertNull(DeliveryContext::actor());
        DeliveryContext::setActor(-4503599627370497);
        $this->assertSame(-4503599627370497, DeliveryContext::actor());
        DeliveryContext::setActor(null);
        $this->assertNull(DeliveryContext::actor());
        DeliveryContext::setActor(555);
        DeliveryContext::reset();
        $this->assertNull(DeliveryContext::actor());
    }

    public function testWebPlayConfigCarriesPlanValues(): void
    {
        $c = new WebPlay();
        $this->assertSame(10, $c->historySize);
        $this->assertSame(200, $c->inboxKeep);
        $this->assertSame(30, $c->inboxPollSeconds);
        $this->assertSame(10, $c->inboxPollMinSeconds);
        $this->assertSame(60, $c->actsPerMinute);
        $this->assertSame(12, $c->inboxReadsPerMinute);
        $this->assertSame(4096, $c->textMaxLength);
        $this->assertSame(1000000000, $c->firstMessageId);
    }
}
