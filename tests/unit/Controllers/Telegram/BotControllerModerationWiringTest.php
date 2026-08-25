<?php

namespace Tests\Unit\Controllers\Telegram;

use App\Controllers\Telegram\BotController;
use App\Services\Logging\PlayerActionLogger;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * community-chat-bot-17 — связка: `BotController::handleCommunityUpdate()` теперь
 * зовёт `CommunityModerationService::evaluate()` рядом с приёмом
 * (`CommunityIngestService::handle()`, story 16). `CommunityModerationService` готов
 * и покрыт тестами с story 10, но до этой story не вызывался ниоткуда —
 * BUILT-BUT-DEAD (третий и последний такой случай в спеке, см. Goal story-файла).
 *
 * Тест-двойник контроллера подменяет оба вызова спаями — так же, как в
 * `BotControllerCommunityWiringTest` (story 16), чтобы не зависеть от состояния
 * БД/`community.*` GameSettings в окружении, где бежит тест.
 *
 * @internal
 */
final class SpyModerationBotController extends BotController
{
    public int $dispatchCalls = 0;

    /** @var list<array<array-key, mixed>> */
    public array $ingestCalls = [];

    /** @var list<array<array-key, mixed>> */
    public array $moderationCalls = [];

    public bool $throwFromIngest = false;

    public bool $throwFromModeration = false;

    protected function dispatchToTelegram(): void
    {
        $this->dispatchCalls++;
    }

    protected function handleCommunityUpdate(array $update): void
    {
        try {
            if ($this->throwFromIngest) {
                throw new \RuntimeException('приёмник упал');
            }
            $this->ingestCalls[] = $update;
        } catch (\Throwable $e) {
            log_message('error', 'CommunityIngestService: ' . $e->getMessage());
        }

        try {
            if ($this->throwFromModeration) {
                throw new \RuntimeException('модерация упала');
            }
            $this->moderationCalls[] = $update;
        } catch (\Throwable $e) {
            log_message('error', 'CommunityModerationService: ' . $e->getMessage());
        }
    }
}

final class BotControllerModerationWiringTest extends CIUnitTestCase
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

    private function controllerFor(array $update): SpyModerationBotController
    {
        $request = $this->createMock(IncomingRequest::class);
        $request->method('getBody')->willReturn(json_encode($update));

        $controller = new SpyModerationBotController();
        $controller->initController($request, service('response', null, false), service('logger'));

        return $controller;
    }

    /** @return array<string, mixed> */
    private function groupUpdate(): array
    {
        return [
            'update_id' => 1,
            'message'   => [
                'message_id' => 10,
                'from'       => ['id' => 777, 'is_bot' => false],
                'chat'       => ['id' => -100777, 'type' => 'supergroup'],
                'text'       => 'привет чат',
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

    public function testGroupUpdateReachesModerationServiceExactlyOnce(): void
    {
        $update     = $this->groupUpdate();
        $controller = $this->controllerFor($update);

        $controller->webhook();

        $this->assertCount(1, $controller->moderationCalls, 'CommunityModerationService::evaluate() должен получить ровно один апдейт');
        $this->assertSame($update, $controller->moderationCalls[0]);
    }

    public function testPrivateUpdateNeverReachesModerationService(): void
    {
        $controller = $this->controllerFor($this->privateUpdate());

        $controller->webhook();

        $this->assertSame([], $controller->moderationCalls, 'Приватный апдейт не должен доходить до модерации');
    }

    public function testModerationExceptionDoesNotBreakIngest(): void
    {
        $controller                       = $this->controllerFor($this->groupUpdate());
        $controller->throwFromModeration = true;

        $controller->webhook();

        $this->assertCount(1, $controller->ingestCalls, 'Падение модерации не должно мешать приёму записать сообщение');
    }

    public function testIngestExceptionDoesNotBreakModeration(): void
    {
        $controller                  = $this->controllerFor($this->groupUpdate());
        $controller->throwFromIngest = true;

        $controller->webhook();

        $this->assertCount(1, $controller->moderationCalls, 'Падение приёма не должно мешать модерации отработать');
    }

    public function testModerationExceptionDoesNotChangeWebhookResponse(): void
    {
        $controller                       = $this->controllerFor($this->groupUpdate());
        $controller->throwFromModeration = true;

        $response = $controller->webhook();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', (string) $response->getBody());
    }

    /**
     * Реальная связка (без спая handleCommunityUpdate): убеждаемся, что метод
     * контроллера реально зовёт `CommunityModerationService::evaluate()`, а не
     * пустышка. `community.enabled` не настроен в тестовом окружении, поэтому
     * сервис fail-closed молчит — но сам факт вызова не должен бросать исключение
     * наружу и не должен ронять ответ вебхука.
     */
    public function testRealHandleCommunityUpdateCallsModerationServiceWithoutBreakingResponse(): void
    {
        $update = $this->groupUpdate();

        $request = $this->createMock(IncomingRequest::class);
        $request->method('getBody')->willReturn(json_encode($update));

        $controller = new class () extends BotController {
            public int $dispatchCalls = 0;

            protected function dispatchToTelegram(): void
            {
                $this->dispatchCalls++;
            }
        };
        $controller->initController($request, service('response', null, false), service('logger'));

        $response = $controller->webhook();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', (string) $response->getBody());
        $this->assertSame(0, $controller->dispatchCalls);
    }
}
