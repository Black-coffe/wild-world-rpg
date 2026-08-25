<?php

namespace Tests\Unit\Controllers\Telegram;

use App\Controllers\Telegram\BotController;
use App\Services\Logging\PlayerActionLogger;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Тест-двойник контроллера: `dispatchToTelegram()` — единственная точка, где
 * webhook() трогает живой Longman-клиент. Тест переопределяет её спаем и никогда
 * не вызывает `$this->telegram->handle()` напрямую (см. комментарий у seam'а в
 * BotController — паттерн [[feedback_taskhandler_telegram_init_in_tests]]). Так тест не
 * зависит от того, задан ли валидный `telegram.API_KEY` в окружении, где он бежит.
 */
final class SpyBotController extends BotController
{
    public int $dispatchCalls = 0;

    /** @var list<array<array-key, mixed>> */
    public array $communityUpdates = [];

    protected function dispatchToTelegram(): void
    {
        $this->dispatchCalls++;
    }

    protected function handleCommunityUpdate(array $update): void
    {
        $this->communityUpdates[] = $update;
        parent::handleCommunityUpdate($update);
    }
}

/**
 * community-chat-bot-01 — гейт по типу чата в `BotController::webhook()`.
 *
 * Групповой/супергрупповой/канальный апдейт обязан быть отсечён ДО
 * `$this->telegram->handle()` и ДО side-эффектов E6/E8/firehose; приватный путь и
 * апдейты без `chat` вообще (`inline_query`) должны продолжать идти прежним путём —
 * без этого регрессия на 99% трафика игры была бы незаметна одним PHPUnit-прогоном.
 *
 * @internal
 */
final class BotControllerChatTypeGateTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        PlayerActionLogger::reset();
    }

    protected function tearDown(): void
    {
        PlayerActionLogger::reset();
        parent::tearDown();
    }

    private function controllerFor(array $update): SpyBotController
    {
        $request = $this->createMock(IncomingRequest::class);
        $request->method('getBody')->willReturn(json_encode($update));

        $controller = new SpyBotController();
        $controller->initController($request, service('response', null, false), service('logger'));

        return $controller;
    }

    /** @return array<string, mixed> */
    private function groupUpdate(string $chatType = 'supergroup'): array
    {
        return [
            'update_id' => 1,
            'message'   => [
                'message_id' => 10,
                'from'       => ['id' => 777, 'is_bot' => false],
                'chat'       => ['id' => -100777, 'type' => $chatType],
                'text'       => 'привет чат',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function groupCallbackUpdate(): array
    {
        return [
            'update_id'      => 2,
            'callback_query' => [
                'id'      => 'cb-1',
                'from'    => ['id' => 777, 'is_bot' => false],
                'data'    => 'inventory',
                'message' => [
                    'message_id' => 11,
                    'chat'       => ['id' => -100777, 'type' => 'group'],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function privateUpdate(): array
    {
        return [
            'update_id' => 3,
            'message'   => [
                'message_id' => 12,
                'from'       => ['id' => 777, 'is_bot' => false],
                'chat'       => ['id' => 777, 'type' => 'private'],
                'text'       => '🏠 База',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function inlineQueryUpdate(): array
    {
        return [
            'update_id'    => 4,
            'inline_query' => [
                'id'    => 'iq-1',
                'from'  => ['id' => 777, 'is_bot' => false],
                'query' => 'foo',
            ],
        ];
    }

    public function testSupergroupMessageNeverReachesTelegramHandle(): void
    {
        $controller = $this->controllerFor($this->groupUpdate('supergroup'));

        $controller->webhook();

        $this->assertSame(0, $controller->dispatchCalls, 'Групповой апдейт не должен доходить до $telegram->handle()');
    }

    public function testGroupCallbackNeverReachesTelegramHandle(): void
    {
        $controller = $this->controllerFor($this->groupCallbackUpdate());

        $controller->webhook();

        $this->assertSame(0, $controller->dispatchCalls, 'chat.type из callback_query.message тоже гейтится');
    }

    public function testChannelPostChatTypeIsTreatedAsCommunity(): void
    {
        $controller = $this->controllerFor($this->groupUpdate('channel'));

        $controller->webhook();

        $this->assertSame(0, $controller->dispatchCalls, 'channel трактуется как групповой путь по контракту story');
    }

    public function testCommunityUpdateReachesExtensionPointExactlyOnce(): void
    {
        $update     = $this->groupUpdate('supergroup');
        $controller = $this->controllerFor($update);

        $controller->webhook();

        $this->assertCount(1, $controller->communityUpdates, 'handleCommunityUpdate() — точка расширения story 04/05');
        $this->assertSame($update, $controller->communityUpdates[0]);
    }

    public function testGroupUpdateDoesNotOpenPlayerActionLogger(): void
    {
        $controller = $this->controllerFor($this->groupUpdate('supergroup'));

        $controller->webhook();

        $this->assertFalse(
            PlayerActionLogger::current()->isActive(),
            'Групповой апдейт не должен открывать firehose-захват (ADR-148)'
        );
    }

    public function testGroupUpdateRespondsWithEmpty200(): void
    {
        $controller = $this->controllerFor($this->groupUpdate('supergroup'));

        $response = $controller->webhook();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', (string) $response->getBody());
    }

    /**
     * 🔴 Регрессия: приватный путь обязан остаться прежним — включая то, что
     * `begin()` открывает firehose-захват (side-эффект, который контракт story
     * прямо запрещает трогать).
     */
    public function testPrivateMessageStillReachesTelegramHandle(): void
    {
        $controller = $this->controllerFor($this->privateUpdate());

        $controller->webhook();

        $this->assertSame(1, $controller->dispatchCalls, 'Приватный путь обязан дойти до $telegram->handle()');
        $this->assertTrue(
            PlayerActionLogger::current()->isActive(),
            'Приватный путь по-прежнему открывает firehose-захват — так было и до story'
        );
    }

    public function testUpdateWithoutChatGoesThePrivateRoute(): void
    {
        $controller = $this->controllerFor($this->inlineQueryUpdate());

        $controller->webhook();

        $this->assertSame(1, $controller->dispatchCalls, 'inline_query без chat идёт прежним, приватным путём');
        $this->assertSame([], $controller->communityUpdates);
    }
}
