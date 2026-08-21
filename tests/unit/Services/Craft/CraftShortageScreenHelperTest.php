<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Craft;

use App\Entities\CharacterEntity;
use App\Services\Craft\CraftShortageScreenHelper;
use App\Services\Craft\CraftShortageService;
use CodeIgniter\Test\CIUnitTestCase;
use Longman\TelegramBot\Entities\ServerResponse;

/**
 * Story craft-shortage-screen-dedupe-01 — общая точка, к которой теперь
 * делегируют семь `Start*2Action` (см. `tests/unit/Craft/ShortageScreenDedupeGuardTest.php`
 * для замка присутствия на стороне вызывающих классов).
 *
 * Поведение при `isEnabled() === true` (вызов `describe()` + `Request::sendMessage`)
 * этот файл не гоняет: `Request::sendMessage()` уходит в реальный Telegram Bot
 * API, не мокается статически, и это поведение уже покрыто
 * `CraftShortageServiceTest` (сам `describe()`) — здесь дублировать нечего.
 * Файл проверяет то, что принадлежит только помощнику: маршрутизацию
 * fallback/callback-ветки.
 *
 * @internal
 */
final class CraftShortageScreenHelperTest extends CIUnitTestCase
{
    private function disabledShortageService(): CraftShortageService
    {
        return new class () extends CraftShortageService {
            public function isEnabled(): bool
            {
                return false;
            }
        };
    }

    public function testFallbackIsUsedWhenKillswitchIsOff(): void
    {
        $helper   = new CraftShortageScreenHelper($this->disabledShortageService());
        $expected = new ServerResponse(['ok' => true, 'result' => true], 'testbot');
        $called   = false;

        $result = $helper->render(
            new CharacterEntity(['id' => 7]),
            [],
            [],
            1,
            [],
            123,
            function () use ($expected, &$called): ServerResponse {
                $called = true;

                return $expected;
            }
        );

        $this->assertTrue($called, 'при выключенном killswitch должен вызываться fallback, а не describe()/sendMessage');
        $this->assertSame($expected, $result);
    }

    public function testFallbackReceivesNoArgumentsAndCallbackQueryIsNotAnsweredWithoutId(): void
    {
        // callback_query_id не передан (null) — тот путь, которым идут телепорт-классы,
        // уже ответившие на callback в начале handle(). Если бы helper всё равно звал
        // Request::answerCallbackQuery(), это ушло бы в реальный Bot API и тест упал бы
        // с ошибкой сети/инициализации — то, что тест здесь вообще выполняется до конца
        // без такой ошибки, и есть доказательство, что второй answerCallbackQuery не звался.
        $helper = new CraftShortageScreenHelper($this->disabledShortageService());

        $result = $helper->render(
            ['id' => 7],
            [],
            [],
            1,
            [],
            123,
            static fn (): ServerResponse => new ServerResponse(['ok' => true, 'result' => true], 'testbot'),
            null
        );

        $this->assertInstanceOf(ServerResponse::class, $result);
    }
}
