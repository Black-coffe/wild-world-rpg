<?php

namespace Tests\Unit\Services\Events;

use App\Services\Events\EventMessageFormatter;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Тесты на текст кнопок event-уведомлений. Главное — кнопки приглушения
 * уведомлений НЕ должны читаться как «отключить событие в игре для меня»
 * (фикс misleading-текста: «🚫 Без событий ...» → «🔕 Не уведомлять о таких событиях»).
 *
 * @internal
 */
final class EventMessageFormatterTest extends CIUnitTestCase
{
    private EventMessageFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new EventMessageFormatter();
    }

    public function testStartKeyboardMuteButtonsAreAboutNotificationsNotEvents(): void
    {
        $kb   = $this->formatter->buildStartKeyboard('damage_resources');
        $rows = $kb['inline_keyboard'];

        // собираем все тексты кнопок
        $texts = [];
        foreach ($rows as $row) {
            foreach ($row as $btn) {
                $texts[] = $btn['text'];
            }
        }
        $joined = implode(' | ', $texts);

        // ❌ старый misleading-текст ушёл
        $this->assertStringNotContainsString('Без событий', $joined);
        $this->assertStringNotContainsString('🚫', $joined);

        // ✅ новый текст про уведомления
        $this->assertContains('🔕 Тишина на 1 час', $texts);
        $this->assertContains('🔕 Не уведомлять о таких событиях', $texts);
    }

    public function testStartKeyboardMuteKindCallbackCarriesEffectKind(): void
    {
        $kb = $this->formatter->buildStartKeyboard('gather_debuff');

        $callbacks = [];
        foreach ($kb['inline_keyboard'] as $row) {
            foreach ($row as $btn) {
                $callbacks[] = $btn['callback_data'];
            }
        }

        $this->assertContains('eventPref_mute_1h', $callbacks);
        $this->assertContains('eventPref_muteKind_gather_debuff', $callbacks);
    }

    public function testEndKeyboardMuteButtonRenamed(): void
    {
        $kb = $this->formatter->buildEndKeyboard('heal', ['health_delta' => 0.0]);

        $texts = [];
        foreach ($kb['inline_keyboard'] as $row) {
            foreach ($row as $btn) {
                $texts[] = $btn['text'];
            }
        }

        $this->assertContains('🔕 Тишина на 1 час', $texts);
        $this->assertStringNotContainsString('🚫', implode(' ', $texts));
    }

    public function testEndKeyboardAddsHealButtonOnBigHpLoss(): void
    {
        $kb = $this->formatter->buildEndKeyboard('damage_health', ['health_delta' => -25.0]);

        $hasHeal = false;
        foreach ($kb['inline_keyboard'] as $row) {
            foreach ($row as $btn) {
                if ($btn['callback_data'] === 'craftBandage') {
                    $hasHeal = true;
                }
            }
        }
        $this->assertTrue($hasHeal, 'при HP loss > 10 должна появиться кнопка «Лечиться»');
    }
}
