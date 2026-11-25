<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Events;

use App\Services\Events\EventDispatcher;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-200 — `event_effects_log` и итог события несут ФАКТИЧЕСКИ применённый урон (после пассивного пола),
 * а не запрошенный эффектом: по этим строкам S4 называет причину смерти и число HP.
 *
 * @internal
 */
final class EventDispatcherAppliedHealthTest extends CIUnitTestCase
{
    public function testLoggedHealthDeltaIsTheAppliedOne(): void
    {
        $out = EventDispatcher::resultWithAppliedHealth(
            ['applied' => true, 'health_delta' => -9.0, 'tired_delta' => -2.0, 'log_summary' => 'x'],
            ['health' => -4.0]
        );

        $this->assertSame(-4.0, $out['health_delta'], 'в лог — фактический урон');
        $this->assertSame(-9.0, $out['health_delta_requested'], 'запрошенный сохраняется рядом');
        $this->assertSame(-2.0, $out['tired_delta'], 'прочие поля не тронуты');
    }

    public function testBelowFloorHitLogsZero(): void
    {
        $out = EventDispatcher::resultWithAppliedHealth(['health_delta' => -3.0], ['health' => 0.0]);

        $this->assertSame(0.0, $out['health_delta']);
        $this->assertSame(-3.0, $out['health_delta_requested']);
    }

    public function testResultWithoutHealthIsUnchanged(): void
    {
        $result = ['applied' => true, 'gold_delta' => 5];

        $this->assertSame($result, EventDispatcher::resultWithAppliedHealth($result, []));
    }
}
