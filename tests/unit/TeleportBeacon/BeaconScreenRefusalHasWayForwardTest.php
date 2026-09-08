<?php

declare(strict_types=1);

namespace Tests\Unit\TeleportBeacon;

use App\Controllers\Telegram\Commands\Actions\Camp\Buildings\TeleportBeacon;
use CodeIgniter\Test\CIUnitTestCase;
use Config\CallbackRoutes;
use ReflectionClass;

/**
 * Экран «📡 Маяки» без постройки «Центр телепортации» отвечал голым текстом без единой
 * кнопки — ни «🏗 Строить», ни возврата на базу. Тупик: за месяц в него попали двое из
 * девяти открывавших экран. Тест держит клавиатуру отказа на месте.
 *
 * `TeleportBeacon::handle()` требует живого `CallbackQuery` и DB-моделей (характер, база),
 * поэтому клавиатура этой ветки вынесена в отдельный приватный метод `noTeleportCenterKeyboard()`
 * — тест зовёт его напрямую через рефлексию, не через полный HTTP/Telegram-стек.
 */
final class BeaconScreenRefusalHasWayForwardTest extends CIUnitTestCase
{
    /** @return array{inline_keyboard: array<int, array<int, array{text: string, callback_data: string}>>} */
    private function keyboard(): array
    {
        $reflection = new ReflectionClass(TeleportBeacon::class);
        $method     = $reflection->getMethod('noTeleportCenterKeyboard');
        $method->setAccessible(true);

        // Метод не трогает свойства объекта — можно создать инстанс через newInstanceWithoutConstructor.
        $instance = $reflection->newInstanceWithoutConstructor();

        return $method->invoke($instance);
    }

    public function testKeyboardIsPresent(): void
    {
        $keyboard = $this->keyboard();

        $this->assertArrayHasKey('inline_keyboard', $keyboard, 'Отказ обязан нести клавиатуру, а не только текст.');
        $this->assertNotEmpty($keyboard['inline_keyboard'], 'Клавиатура не может быть пустой.');
    }

    public function testHasPathToBuildAndBack(): void
    {
        $keyboard  = $this->keyboard();
        $callbacks = [];
        foreach ($keyboard['inline_keyboard'] as $row) {
            foreach ($row as $button) {
                $callbacks[] = $button['callback_data'] ?? null;
            }
        }

        $this->assertContains('Build', $callbacks, 'Должен быть путь на стройку.');
        $this->assertContains('Base', $callbacks, 'Должен быть путь назад на экран базы.');

        // Callback_data обязаны реально резолвиться, а не быть строкой-заглушкой.
        $routes = new CallbackRoutes();
        foreach (['Build', 'Base'] as $callbackData) {
            $this->assertNotNull(
                $routes->resolve($callbackData),
                "callback_data '{$callbackData}' не резолвится ни в один обработчик."
            );
        }
    }

    public function testNoRowIsASingleButton(): void
    {
        $keyboard = $this->keyboard();

        foreach ($keyboard['inline_keyboard'] as $row) {
            $this->assertGreaterThan(1, count($row), 'Правило «ноль одиночек в ряду»: ни один ряд не может состоять из одной кнопки.');
        }
    }

    /**
     * Регресс-гвард: если клавиатуру уберут из ветки отказа (перестанут звать
     * `noTeleportCenterKeyboard()` и передавать её в `sendError()`), сообщение снова
     * станет тупиком без единой кнопки.
     */
    public function testRefusalBranchStillPassesKeyboardToSendError(): void
    {
        $screen = (string) file_get_contents(
            APPPATH . 'Controllers/Telegram/Commands/Actions/Camp/Buildings/TeleportBeacon.php'
        );

        $this->assertStringContainsString(
            'noTeleportCenterKeyboard()',
            $screen,
            'Ветка «нет Центра телепортации» обязана строить клавиатуру.'
        );

        $this->assertMatchesRegularExpression(
            '/hasTeleportCenter\).*?\$keyboardJson = json_encode\(\$this->noTeleportCenterKeyboard\(\)\);.*?sendError\(\$chatId, \$errorText, \$keyboardJson !== false \? \$keyboardJson : null\);/s',
            $screen,
            'Клавиатура обязана передаваться именно в sendError() ветки «нет Центра телепортации».'
        );
    }

    public function testTextStillNamesTheReason(): void
    {
        $screen = (string) file_get_contents(
            APPPATH . 'Controllers/Telegram/Commands/Actions/Camp/Buildings/TeleportBeacon.php'
        );

        $this->assertStringContainsString(
            'Центр телепортации',
            $screen,
            'Текст обязан по-прежнему называть причину отказа и постройку.'
        );
    }
}
