<?php

namespace Tests\Unit\Services\Events;

use App\Services\Events\NotificationPolicy;
use CodeIgniter\Test\CIUnitTestCase;
use Config\WorldEvents;

/**
 * F7.5 — тести NotificationPolicy для throttle/mute/magnitude logic.
 *
 * Pure-logic частини (readUserPref, isMuted, isThrottled, magnitudeOverrides)
 * тестуються тут. sendStart/sendEnd — потребують Telegram + DB → smoke на testbot.
 *
 * @internal
 */
final class NotificationPolicyTest extends CIUnitTestCase
{
    private NotificationPolicy $policy;
    private WorldEvents $cfg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cfg    = new WorldEvents();
        $this->policy = new NotificationPolicy($this->cfg);
    }

    // ============================================================
    // readUserPref — resilience
    // ============================================================

    public function testReadUserPrefHandlesNull(): void
    {
        $this->assertSame([], $this->policy->readUserPref(['event_pref' => null]));
        $this->assertSame([], $this->policy->readUserPref([]));
    }

    public function testReadUserPrefHandlesEmptyString(): void
    {
        $this->assertSame([], $this->policy->readUserPref(['event_pref' => '']));
    }

    public function testReadUserPrefHandlesInvalidJson(): void
    {
        $this->assertSame([], $this->policy->readUserPref(['event_pref' => '{not valid json']));
    }

    public function testReadUserPrefDecodesValid(): void
    {
        $json = json_encode(['silenced_until' => '2026-12-31', 'muted_kinds' => ['damage_health']]);
        $pref = $this->policy->readUserPref(['event_pref' => $json]);

        $this->assertSame('2026-12-31', $pref['silenced_until']);
        $this->assertSame(['damage_health'], $pref['muted_kinds']);
    }

    // ============================================================
    // isMuted
    // ============================================================

    public function testIsMutedFalseWhenNoPref(): void
    {
        $this->assertFalse($this->policy->isMuted([]));
        $this->assertFalse($this->policy->isMuted([], 'damage_health'));
    }

    public function testIsMutedSilencedUntilFuture(): void
    {
        $future = date('Y-m-d H:i:s', time() + 3600);
        $this->assertTrue($this->policy->isMuted(['silenced_until' => $future]));
    }

    public function testIsMutedSilencedUntilPastIsNotMuted(): void
    {
        $past = date('Y-m-d H:i:s', time() - 3600);
        $this->assertFalse($this->policy->isMuted(['silenced_until' => $past]));
    }

    public function testIsMutedKindMatch(): void
    {
        $pref = ['muted_kinds' => ['damage_health', 'gather_debuff']];
        $this->assertTrue($this->policy->isMuted($pref, 'damage_health'));
        $this->assertTrue($this->policy->isMuted($pref, 'gather_debuff'));
        $this->assertFalse($this->policy->isMuted($pref, 'heal'));
        $this->assertFalse($this->policy->isMuted($pref, ''));
    }

    // ============================================================
    // isThrottled
    // ============================================================

    public function testIsThrottledFalseWhenNeverNotified(): void
    {
        $this->assertFalse($this->policy->isThrottled([]));
    }

    public function testIsThrottledTrueRecent(): void
    {
        $recent = date('Y-m-d H:i:s', time() - 300);  // 5 хв тому, throttle=60min
        $this->assertTrue($this->policy->isThrottled(['last_event_notification_at' => $recent]));
    }

    public function testIsThrottledFalseAfterThrottle(): void
    {
        $old = date('Y-m-d H:i:s', time() - 7200);  // 2 год тому
        $this->assertFalse($this->policy->isThrottled(['last_event_notification_at' => $old]));
    }

    // ============================================================
    // magnitudeOverrides — критичні події пробивають throttle
    // ============================================================

    public function testMagnitudeOverridesHpLossBigEnough(): void
    {
        $this->assertTrue($this->policy->magnitudeOverrides([
            'health_loss_percent' => 30,  // > 25
        ]));
        $this->assertFalse($this->policy->magnitudeOverrides([
            'health_loss_percent' => 10,
        ]));
    }

    public function testMagnitudeOverridesGoldGainBigEnough(): void
    {
        $this->assertTrue($this->policy->magnitudeOverrides([
            'gold_gain' => 7000,  // > 5000
        ]));
        $this->assertFalse($this->policy->magnitudeOverrides([
            'gold_gain' => 100,
        ]));
    }

    public function testMagnitudeOverridesGoldDeltaBigEnough(): void
    {
        // Aggregate-формат використовує gold_delta
        $this->assertTrue($this->policy->magnitudeOverrides([
            'gold_delta' => 8000,
        ]));
    }

    public function testMagnitudeOverridesRareItemDrop(): void
    {
        $this->assertTrue($this->policy->magnitudeOverrides([
            'rare_item_drop' => true,
        ]));
        $this->assertTrue($this->policy->magnitudeOverrides([
            'resource_grants' => [['keyword' => 'berry', 'amount' => 5]],
        ]));
    }

    public function testMagnitudeOverridesHealthAfterCritical(): void
    {
        $this->assertTrue($this->policy->magnitudeOverrides([
            'health_after' => 3,  // < 5
        ]));
        $this->assertFalse($this->policy->magnitudeOverrides([
            'health_after' => 50,
        ]));
    }

    public function testMagnitudeOverridesResourceLossPercent(): void
    {
        $this->assertTrue($this->policy->magnitudeOverrides([
            'resource_loss_percent' => 60,  // > 50
        ]));
    }

    public function testMagnitudeOverridesEmptyAggregate(): void
    {
        $this->assertFalse($this->policy->magnitudeOverrides([]));
    }
}
