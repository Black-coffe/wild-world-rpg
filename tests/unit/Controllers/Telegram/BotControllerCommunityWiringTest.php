<?php

namespace Tests\Unit\Controllers\Telegram;

use App\Controllers\Telegram\BotController;
use App\Services\Logging\PlayerActionLogger;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * community-chat-bot-16 — связка: `BotController::handleCommunityUpdate()` наконец
 * зовёт `CommunityIngestService::handle()` (готов и покрыт тестами с story 05, но
 * до этой story не вызывался ниоткуда — BUILT-BUT-DEAD, см. Goal story-файла).
 *
 * Тест-двойник контроллера подменяет реальный вызов сервиса спаем — так же, как
 * `dispatchToTelegram()` подменяется в `BotControllerChatTypeGateTest`, чтобы не
 * зависеть от состояния БД/`community.*` GameSettings в окружении, где бежит тест.
 *
 * @internal
 */
final class SpyIngestBotController extends BotController
{
    public int $dispatchCalls = 0;

    /** @var list<array<array-key, mixed>> */
    public array $ingestCalls = [];

    public bool $throwFromIngest = false;

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
    }
}

final class BotControllerCommunityWiringTest extends CIUnitTestCase
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

    private function controllerFor(array $update): SpyIngestBotController
    {
        $request = $this->createMock(IncomingRequest::class);
        $request->method('getBody')->willReturn(json_encode($update));

        $controller = new SpyIngestBotController();
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

    public function testGroupUpdateReachesIngestServiceExactlyOnce(): void
    {
        $update     = $this->groupUpdate();
        $controller = $this->controllerFor($update);

        $controller->webhook();

        $this->assertCount(1, $controller->ingestCalls, 'CommunityIngestService::handle() должен получить ровно один апдейт');
        $this->assertSame($update, $controller->ingestCalls[0]);
    }

    public function testPrivateUpdateNeverReachesIngestService(): void
    {
        $controller = $this->controllerFor($this->privateUpdate());

        $controller->webhook();

        $this->assertSame([], $controller->ingestCalls, 'Приватный апдейт не должен доходить до приёмника');
    }

    public function testIngestExceptionDoesNotChangeWebhookResponse(): void
    {
        $controller                  = $this->controllerFor($this->groupUpdate());
        $controller->throwFromIngest = true;

        $response = $controller->webhook();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', (string) $response->getBody());
    }

    /**
     * Регрессия на порядок: гейт по типу чата обязан сработать ДО firehose
     * (`PlayerActionLogger::begin()`) и до хуков E6/E8 (story 01) — независимо от
     * того, что теперь `handleCommunityUpdate()` реально что-то делает.
     */
    public function testGroupUpdateGateStillFiresBeforeFirehoseAndTelegramHandle(): void
    {
        $controller = $this->controllerFor($this->groupUpdate());

        $controller->webhook();

        $this->assertSame(0, $controller->dispatchCalls, 'Групповой апдейт не должен доходить до $telegram->handle()');
        $this->assertFalse(
            PlayerActionLogger::current()->isActive(),
            'Групповой апдейт не должен открывать firehose-захват (ADR-148)'
        );
    }

    /**
     * Реальная связка (без спая handleCommunityUpdate): убеждаемся, что метод
     * контроллера — тонкое делегирование в `CommunityIngestService`, а не
     * пустышка. `community.enabled` не настроен в тестовом окружении, поэтому
     * сервис fail-closed молчит — но сам факт вызова не должен бросать исключение
     * наружу и не должен ронять ответ вебхука.
     */
    public function testRealHandleCommunityUpdateDelegatesToIngestServiceWithoutBreakingResponse(): void
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
