<?php

namespace Tests\Unit\Services\Events;

use App\Services\Events\EventCloseHandler;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * F7.4 — smoke на EventCloseHandler.
 *
 * Глибокі тести на side-effects (Telegram send) виходять за межі unit-area.
 * Перевіряємо: інстантіювання + closeEvent повертає stats array з очікуваною
 * shape для no-op cases.
 *
 * @internal
 */
final class EventCloseHandlerSmokeTest extends CIUnitTestCase
{
    public function testInstantiatesWithDefaults(): void
    {
        $handler = new EventCloseHandler();
        $this->assertInstanceOf(EventCloseHandler::class, $handler);
    }

    public function testCloseEventOnNonExistentReturnsZeroStats(): void
    {
        $db = \Config\Database::connect();
        if (!$db->tableExists('active_events')) {
            $this->markTestSkipped('Test DB не має active_events.');
            return;
        }

        $handler = new EventCloseHandler();
        $stats   = $handler->closeEvent([
            'id'       => 999999,  // безсумнівно неіснуючий
            'event_id' => 999999,
        ]);

        $this->assertSame(0, $stats['summaries_sent']);
        $this->assertSame(0, $stats['summaries_skipped']);
        $this->assertSame(0, $stats['errors']);
    }
}
