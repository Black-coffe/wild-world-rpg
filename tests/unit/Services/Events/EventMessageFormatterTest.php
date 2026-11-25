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

        // N1: callback heal-кнопки переведён с мёртвого `craftBandage`
        // на рабочий маршрут `pharmacy` (экран аптечки — использовать лечилки).
        $hasHeal = false;
        foreach ($kb['inline_keyboard'] as $row) {
            foreach ($row as $btn) {
                if ($btn['callback_data'] === 'pharmacy') {
                    $hasHeal = true;
                }
            }
        }
        $this->assertTrue($hasHeal, 'при HP loss > 10 должна появиться рабочая кнопка «Лечиться» (pharmacy)');
    }

    // ── ADR-200 (sudden-death-1125 S3): старт события с уроном говорит, что ранит и что база укрывает ──

    public function testDamageEventStartNamesHealthHitAndSafeBase(): void
    {
        $cfg = $this->config('Tremor'); // health_loss_range [3, 9], база укрывает полностью
        $msg = $this->formatter->buildStartMessage(['name' => 'Подземные толчки', 'description' => 'Трясёт.', 'duration' => 60], $cfg);

        $this->assertStringContainsString('Ранит здоровье', $msg);
        $this->assertStringContainsString('3–9', $msg, 'примерный урон за удар — из конфига события');
        $this->assertStringContainsString('база', $msg);
        $this->assertStringContainsString('не достанет', $msg, 'база укрывает от толчков полностью');
    }

    public function testEventThatOnlySoftensOnBaseDoesNotPromiseFullShelter(): void
    {
        $msg = $this->formatter->buildStartMessage(['name' => 'Эпидемия', 'description' => '', 'duration' => 90], $this->config('Epidemic'));

        $this->assertStringContainsString('Ранит здоровье', $msg);
        $this->assertStringContainsString('база', $msg);
        $this->assertStringNotContainsString('не достанет', $msg, 'на базе эпидемия бьёт слабее, но бьёт — не обещать укрытие');
    }

    public function testBiomeSpecificAndSideEffectHealthDamageAreNamed(): void
    {
        $fever  = $this->formatter->buildStartMessage(['name' => 'Лихорадка', 'description' => ''], $this->config('Fever'));
        $mirage = $this->formatter->buildStartMessage(['name' => 'Миражи', 'description' => ''], $this->config('MirageOases'));

        $this->assertStringContainsString('1–7', $fever, 'диапазон по всем типам биомов');
        $this->assertStringContainsString('Ранит здоровье', $mirage, 'побочный урон здоровью миражей — тоже урон');
    }

    public function testLootAndTiredOnlyEventsCarryNoHealthLine(): void
    {
        $loot = $this->formatter->buildStartMessage(['name' => 'Ягодный бум', 'description' => 'Ягоды.'], $this->config('BerryBoom'));
        $sand = $this->formatter->buildStartMessage(['name' => 'Песчаная буря', 'description' => ''], $this->config('Sandstorm'));

        foreach ([$loot, $sand] as $msg) {
            $this->assertStringNotContainsString('Ранит здоровье', $msg);
            $this->assertStringNotContainsString('база', $msg);
        }
    }

    public function testStartCaptionStaysWithinPhotoLimitForAnyDamageEvent(): void
    {
        $cfg = new \Config\WorldEvents();
        foreach ($cfg->keys() as $key) {
            $row = ['name' => str_repeat('Я', 255), 'description' => str_repeat('Длинное описание события. ', 200), 'duration' => 600];
            $msg = $this->formatter->buildStartMessage($row, (array) $cfg->get($key));

            $this->assertLessThanOrEqual(1024, $this->tgLength($msg), "{$key}: подпись фото старта ≤ 1024");
            $this->assertStringContainsString('Продлится', $msg, "{$key}: срез описания не съедает хвост");
            if (str_contains($this->formatter->buildStartMessage(['name' => 'x', 'description' => ''], (array) $cfg->get($key)), 'Ранит здоровье')) {
                $this->assertStringContainsString('база', $msg, "{$key}: строка урона не срезана");
            }
        }
    }

    /** @return array<string, mixed> */
    private function config(string $key): array
    {
        $row = (new \Config\WorldEvents())->get($key);
        $this->assertIsArray($row, $key);

        return $row;
    }

    /** Длина в UTF-16 — метрика Telegram для лимита подписи. */
    private function tgLength(string $text): int
    {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }
}
