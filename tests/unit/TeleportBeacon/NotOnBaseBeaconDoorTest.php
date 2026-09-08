<?php

declare(strict_types=1);

namespace Tests\Unit\TeleportBeacon;

use App\Services\Bases\BaseServiceMessageFormatter;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Экран «ты не на базе» (`notOnBasePhysically()`) обещал путь «🏠 База → 📡 Маяки», но кнопки
 * в клавиатуре не было с 09.07.2026 — единственный вход стоял через физическое возвращение
 * на клетку базы. Тест держит вход открытым.
 */
final class NotOnBaseBeaconDoorTest extends CIUnitTestCase
{
    public function testKeyboardHasBeaconButton(): void
    {
        $result = (new BaseServiceMessageFormatter())->notOnBasePhysically(12, 44, 'Лес');

        $keyboard = json_decode((string) $result['reply_markup'], true);
        $this->assertIsArray($keyboard, 'reply_markup обязан быть валидным JSON.');

        $found = false;
        foreach ($keyboard['inline_keyboard'] as $row) {
            foreach ($row as $button) {
                if (($button['callback_data'] ?? null) === 'teleportBeacon') {
                    $found = true;
                    $this->assertSame(
                        '📡 Маяки',
                        $button['text'],
                        'Подпись должна совпадать с happy-path кнопкой из baseBuildings().'
                    );
                }
            }
        }

        $this->assertTrue($found, 'Кнопка «📡 Маяки» (callback_data=teleportBeacon) обязана быть на экране «ты не на базе».');
    }

    public function testPreviousButtonsStillPresent(): void
    {
        $result   = (new BaseServiceMessageFormatter())->notOnBasePhysically(12, 44, 'Лес');
        $keyboard = json_decode((string) $result['reply_markup'], true);

        $callbacks = [];
        foreach ($keyboard['inline_keyboard'] as $row) {
            foreach ($row as $button) {
                $callbacks[] = $button['callback_data'] ?? null;
            }
        }

        $this->assertContains('TeleportToCamp', $callbacks, 'Кнопка «📡 Телепорт» не должна пропасть.');
        $this->assertContains('move', $callbacks, 'Кнопка «🧭 Двигаться» не должна пропасть.');
    }

    public function testNoRowIsASingleButton(): void
    {
        $result   = (new BaseServiceMessageFormatter())->notOnBasePhysically(12, 44, 'Лес');
        $keyboard = json_decode((string) $result['reply_markup'], true);

        foreach ($keyboard['inline_keyboard'] as $row) {
            $this->assertGreaterThan(1, count($row), 'Правило «ноль одиночек в ряду»: ни один ряд не может состоять из одной кнопки.');
        }
    }

    public function testShapeUnchanged(): void
    {
        $result = (new BaseServiceMessageFormatter())->notOnBasePhysically(12, 44, 'Лес');

        $this->assertArrayHasKey('caption', $result);
        $this->assertArrayHasKey('parse_mode', $result);
        $this->assertArrayHasKey('reply_markup', $result);
        $this->assertSame('Markdown', $result['parse_mode']);
        $this->assertNotNull(json_decode((string) $result['reply_markup'], true), 'reply_markup обязан быть валидным JSON.');
    }
}
