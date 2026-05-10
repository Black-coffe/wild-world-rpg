<?php

namespace Tests\Unit\Controllers\Telegram;

use App\Controllers\Telegram\Commands\Actions\EventPrefAction;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * F7.5b — pure-logic тести EventPrefAction (parsing callback_data + pref update).
 *
 * Реальний handle() з Telegram I/O — smoke на testbot.
 *
 * @internal
 */
final class EventPrefActionTest extends CIUnitTestCase
{
    // ============================================================
    // computeUpdate — mute_1h
    // ============================================================

    public function testMute1hSetsSilencedUntilFuture(): void
    {
        $update = EventPrefAction::computeUpdate('eventPref_mute_1h', []);
        $this->assertNotNull($update);

        $until = strtotime($update['pref']['silenced_until']);
        $this->assertGreaterThan(time() + 3500, $until,
            'silenced_until должен быть ~+1час');
        $this->assertLessThanOrEqual(time() + 3700, $until);

        $this->assertStringContainsString('🔕', $update['confirm']);
        $this->assertStringContainsString('отключены', $update['confirm']);
        $this->assertStringContainsString('как обычно', $update['confirm']);
    }

    public function testMute1hPreservesOtherFields(): void
    {
        $current = ['muted_kinds' => ['heal'], 'last_event_notification_at' => '2026-05-05 10:00:00'];
        $update  = EventPrefAction::computeUpdate('eventPref_mute_1h', $current);

        $this->assertSame(['heal'], $update['pref']['muted_kinds']);
        $this->assertSame('2026-05-05 10:00:00', $update['pref']['last_event_notification_at']);
    }

    // ============================================================
    // computeUpdate — muteKind toggle
    // ============================================================

    public function testMuteKindAddsToMutedList(): void
    {
        $update = EventPrefAction::computeUpdate('eventPref_muteKind_damage_health', []);
        $this->assertNotNull($update);
        $this->assertSame(['damage_health'], $update['pref']['muted_kinds']);
        // confirm — про УВЕДОМЛЕНИЯ, не про сам ивент (фикс misleading-текста)
        $this->assertStringContainsString('🔕', $update['confirm']);
        $this->assertStringContainsString('Уведомления о таких событиях отключены', $update['confirm']);
        $this->assertStringContainsString('влияет как обычно', $update['confirm']);
    }

    public function testMuteKindTogglesOff(): void
    {
        $current = ['muted_kinds' => ['damage_health', 'heal']];
        $update  = EventPrefAction::computeUpdate('eventPref_muteKind_damage_health', $current);

        $this->assertSame(['heal'], $update['pref']['muted_kinds'],
            'damage_health должен быть удалён из muted_kinds');
        $this->assertStringContainsString('🔔', $update['confirm']);
        $this->assertStringContainsString('Снова будем уведомлять', $update['confirm']);
    }

    public function testMuteKindParsesUnderscoresInKind(): void
    {
        // kind='gather_debuff' має 1 underscore — суфікс після prefix'а 'eventPref_muteKind_'
        $update = EventPrefAction::computeUpdate('eventPref_muteKind_gather_debuff', []);
        $this->assertNotNull($update);
        $this->assertContains('gather_debuff', $update['pref']['muted_kinds']);
    }

    public function testMuteKindEmptyReturnsNull(): void
    {
        $update = EventPrefAction::computeUpdate('eventPref_muteKind_', []);
        $this->assertNull($update);
    }

    // ============================================================
    // computeUpdate — unmute (full reset)
    // ============================================================

    public function testUnmuteResetsBoth(): void
    {
        $current = [
            'silenced_until' => '2026-12-31',
            'muted_kinds'    => ['damage_health', 'heal'],
            'last_event_notification_at' => '2026-05-05 10:00:00',
        ];
        $update = EventPrefAction::computeUpdate('eventPref_unmute', $current);

        $this->assertNull($update['pref']['silenced_until']);
        $this->assertSame([], $update['pref']['muted_kinds']);
        $this->assertSame('2026-05-05 10:00:00', $update['pref']['last_event_notification_at'],
            'last_event_notification_at не очищаем');
        $this->assertStringContainsString('включены', $update['confirm']);
    }

    // ============================================================
    // computeUpdate — unknown
    // ============================================================

    public function testUnknownCallbackReturnsNull(): void
    {
        $this->assertNull(EventPrefAction::computeUpdate('foo_bar', []));
        $this->assertNull(EventPrefAction::computeUpdate('eventPref_typo', []));
        $this->assertNull(EventPrefAction::computeUpdate('eventPref_', []));
    }

    // ============================================================
    // readPref resilience
    // ============================================================

    public function testReadPrefHandlesNull(): void
    {
        $this->assertSame([], EventPrefAction::readPref(['event_pref' => null]));
        $this->assertSame([], EventPrefAction::readPref([]));
    }

    public function testReadPrefHandlesEmptyString(): void
    {
        $this->assertSame([], EventPrefAction::readPref(['event_pref' => '']));
    }

    public function testReadPrefHandlesInvalidJson(): void
    {
        $this->assertSame([], EventPrefAction::readPref(['event_pref' => '{not json']));
    }

    public function testReadPrefDecodes(): void
    {
        $json = json_encode(['silenced_until' => '2026-12-31']);
        $pref = EventPrefAction::readPref(['event_pref' => $json]);
        $this->assertSame('2026-12-31', $pref['silenced_until']);
    }

    // ============================================================
    // Round-trip (computeUpdate → encode → decode)
    // ============================================================

    public function testRoundTripMuteThenUnmute(): void
    {
        // 1. mute_1h
        $u1 = EventPrefAction::computeUpdate('eventPref_mute_1h', []);
        $json1 = json_encode($u1['pref']);

        // 2. read back
        $pref2 = EventPrefAction::readPref(['event_pref' => $json1]);
        $this->assertNotNull($pref2['silenced_until']);

        // 3. unmute
        $u2 = EventPrefAction::computeUpdate('eventPref_unmute', $pref2);
        $this->assertNull($u2['pref']['silenced_until']);
    }
}
