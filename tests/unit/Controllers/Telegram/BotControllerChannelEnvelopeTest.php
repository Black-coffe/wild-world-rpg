<?php

namespace Tests\Unit\Controllers\Telegram;

use App\Controllers\Telegram\BotController;
use App\Services\Logging\PlayerActionLogger;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Тест-двойник контроллера — тот же паттерн, что в {@see \Tests\Unit\Controllers\Telegram\BotControllerChatTypeGateTest}
 * (`SpyBotController` там же не переиспользуем: класс объявлен без namespace в глобальном
 * пространстве и коллизия имён под PHPUnit исключена собственным именем здесь).
 */
final class ChannelEnvelopeSpyBotController extends BotController
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
 * story community-chat-bot-25, дефект 1 «Конверты» — `channel_post` и
 * `edited_channel_post` не имеют ключа `message`, поэтому до этой story
 * `extractChatType()` их не распознавал, срабатывал fail-safe «считаем приватным»,
 * и апдейт уходил в игровой диспетчер вопреки контракту story-01 («`channel` —
 * групповой путь»).
 *
 * @internal
 */
final class BotControllerChannelEnvelopeTest extends CIUnitTestCase
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

    private function controllerFor(array $update): ChannelEnvelopeSpyBotController
    {
        $request = $this->createMock(IncomingRequest::class);
        $request->method('getBody')->willReturn(json_encode($update));

        $controller = new ChannelEnvelopeSpyBotController();
        $controller->initController($request, service('response', null, false), service('logger'));

        return $controller;
    }

    /** @return array<string, mixed> */
    private function channelPostUpdate(): array
    {
        return [
            'update_id'    => 101,
            'channel_post' => [
                'message_id' => 55,
                'chat'       => ['id' => -100999, 'type' => 'channel'],
                'text'       => 'анонс в канале',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function editedChannelPostUpdate(): array
    {
        return [
            'update_id'           => 102,
            'edited_channel_post' => [
                'message_id' => 55,
                'chat'       => ['id' => -100999, 'type' => 'channel'],
                'text'       => 'анонс в канале (правка)',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function unknownEnvelopeUpdate(): array
    {
        // Форма, которую бот не разбирает вовсе — fail-safe обязан остаться:
        // без chat где-либо апдейт идёт приватным путём.
        return [
            'update_id'      => 103,
            'poll_answer'    => [
                'poll_id' => 'poll-1',
                'user'    => ['id' => 777, 'is_bot' => false],
            ],
        ];
    }

    public function testChannelPostNeverReachesTelegramDispatcher(): void
    {
        $controller = $this->controllerFor($this->channelPostUpdate());

        $controller->webhook();

        $this->assertSame(0, $controller->dispatchCalls, 'channel_post обязан гейтиться до диспетчера');
    }

    public function testChannelPostReachesCommunityExtensionPoint(): void
    {
        $update     = $this->channelPostUpdate();
        $controller = $this->controllerFor($update);

        $controller->webhook();

        $this->assertCount(1, $controller->communityUpdates);
        $this->assertSame($update, $controller->communityUpdates[0]);
    }

    public function testEditedChannelPostNeverReachesTelegramDispatcher(): void
    {
        $controller = $this->controllerFor($this->editedChannelPostUpdate());

        $controller->webhook();

        $this->assertSame(0, $controller->dispatchCalls, 'edited_channel_post обязан гейтиться до диспетчера');
    }

    public function testChannelPostDoesNotOpenPlayerActionLogger(): void
    {
        $controller = $this->controllerFor($this->channelPostUpdate());

        $controller->webhook();

        $this->assertFalse(
            PlayerActionLogger::current()->isActive(),
            'channel_post не должен открывать firehose-захват (ADR-148)'
        );
    }

    /**
     * 🔴 Fail-safe story-01 остаётся: конверт, который бот не разбирает НИ в одном
     * из известных путей, по-прежнему трактуется как приватный.
     */
    public function testUnknownEnvelopeStillGoesThePrivateRoute(): void
    {
        $controller = $this->controllerFor($this->unknownEnvelopeUpdate());

        $controller->webhook();

        $this->assertSame(1, $controller->dispatchCalls, 'Неизвестный конверт — приватный путь, fail-safe цел');
        $this->assertSame([], $controller->communityUpdates);
    }
}
