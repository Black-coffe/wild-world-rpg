<?php

namespace Tests\Unit\Controllers\Telegram;

use App\Controllers\Telegram\BotController;
use App\Database\Migrations\Adr181CreateTelegramUpdatesSeen;
use App\Services\Logging\PlayerActionLogger;
use CodeIgniter\Database\Forge;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * Тест-двойник контроллера — тот же seam-паттерн, что и `SpyBotController` в
 * `BotControllerChatTypeGateTest`: `dispatchToTelegram()` переопределён спаем, чтобы
 * не зависеть от валидного `telegram.API_KEY` в окружении теста. Собственное имя класса
 * (не `SpyBotController`) — избегает коллизии, файл грузится в том же namespace, когда
 * verification-команда запускает всю директорию `tests/unit/Controllers/Telegram/`.
 */
final class DedupSpyBotController extends BotController
{
    public int $dispatchCalls = 0;

    protected function dispatchToTelegram(): void
    {
        $this->dispatchCalls++;
    }
}

/**
 * exploit-fix-04 (ADR-181) — дедуп повторной доставки webhook'а по `update_id`.
 *
 * Таблица `telegram_updates_seen` создаётся/удаляется прогоном реальной миграции на
 * группу `tests` (паттерн `CommunityCleanupTest`), не ручной изолированной схемой —
 * иначе тест зелёный на схеме, которая расходится с продовой (feedback
 * `test_schema_must_come_from_migration`). Таблица никем, кроме этой story, не
 * используется — создание/удаление per-test безопасно и не задевает общие таблицы.
 *
 * @internal
 */
final class BotControllerUpdateDedupTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    protected function setUp(): void
    {
        parent::setUp();
        PlayerActionLogger::reset();
    }

    protected function tearDown(): void
    {
        PlayerActionLogger::reset();
        $this->dropTableIfPresent();
        parent::tearDown();
    }

    private function requireMigrationClass(): void
    {
        if (! class_exists(Adr181CreateTelegramUpdatesSeen::class, false)) {
            require_once APPPATH . 'Database/Migrations/2026-09-02-120000_Adr181CreateTelegramUpdatesSeen.php';
        }
    }

    /** Таблица дедупа реально существует в БД — для тестов «первый апдейт»/«дубль». */
    private function createTable(): void
    {
        $db = Database::connect('tests');
        if ($db->tableExists('telegram_updates_seen')) {
            return;
        }

        $this->requireMigrationClass();
        $forge = Database::forge('tests');
        (new Adr181CreateTelegramUpdatesSeen($forge instanceof Forge ? $forge : null))->up();
    }

    private function dropTableIfPresent(): void
    {
        $db = Database::connect('tests');
        if (! $db->tableExists('telegram_updates_seen')) {
            return;
        }

        $this->requireMigrationClass();
        $forge = Database::forge('tests');
        (new Adr181CreateTelegramUpdatesSeen($forge instanceof Forge ? $forge : null))->down();
    }

    private function controllerFor(array $update): DedupSpyBotController
    {
        $request = $this->createMock(IncomingRequest::class);
        $request->method('getBody')->willReturn(json_encode($update));

        $controller = new DedupSpyBotController();
        $controller->initController($request, service('response', null, false), service('logger'));

        return $controller;
    }

    /** @return array<string, mixed> */
    private function privateUpdate(int $updateId): array
    {
        return [
            'update_id' => $updateId,
            'message'   => [
                'message_id' => 10,
                'from'       => ['id' => 777, 'is_bot' => false],
                'chat'       => ['id' => 777, 'type' => 'private'],
                'text'       => '🏠 База',
            ],
        ];
    }

    public function testFirstDeliveryOfUpdateReachesDispatch(): void
    {
        $this->createTable();

        $controller = $this->controllerFor($this->privateUpdate(910001));
        $controller->webhook();

        $this->assertSame(1, $controller->dispatchCalls, 'первая доставка апдейта обязана дойти до dispatchToTelegram()');
    }

    public function testRepeatedDeliveryOfSameUpdateIsDroppedBeforeDispatch(): void
    {
        $this->createTable();

        $first = $this->controllerFor($this->privateUpdate(910002));
        $first->webhook();
        $this->assertSame(1, $first->dispatchCalls, 'фикстура: первая доставка обязана пройти, иначе тест ничего не доказывает');

        $second = $this->controllerFor($this->privateUpdate(910002));
        $response = $second->webhook();

        $this->assertSame(0, $second->dispatchCalls, 'повторная доставка того же update_id не должна доходить до диспетча');
        $this->assertSame(200, $response->getStatusCode(), 'дубль отвечает тихим 200 OK — иначе Telegram ретраит и плодит третью доставку');
        $this->assertSame('', (string) $response->getBody());
    }

    /**
     * Таблица дедупа намеренно НЕ создаётся — воспроизводит «хранилище недоступно»
     * (реальная ошибка MySQL 1146 «no such table»), не искусственный мок.
     */
    public function testUnavailableStorageFailsOpenAndLogsErrorMarker(): void
    {
        $controller = $this->controllerFor($this->privateUpdate(910003));

        $controller->webhook();

        $this->assertSame(1, $controller->dispatchCalls, 'при недоступном хранилище дедупа обработка обязана продолжаться (fail-open, ADR-181 §2)');
        $this->assertLogContains(
            'error',
            '[Bot.webhook] dedup:',
            'отказ хранилища обязан писать error-маркер — иначе fail-open неотличим от работающего дедупа'
        );
    }
}
