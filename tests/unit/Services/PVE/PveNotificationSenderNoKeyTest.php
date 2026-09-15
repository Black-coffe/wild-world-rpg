<?php

declare(strict_types=1);

namespace Tests\Unit\Services\PVE;

use App\Models\TelegramUserModel;
use App\Services\PVE\PveNotificationSender;
use App\Services\Telegram\TelegramBridge;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\TestLogger;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Longman\TelegramBot\Request as LongmanRequest;
use ReflectionProperty;

/**
 * cron-delivery-integrity-04 — поведенческая половина гейта: реальный
 * `PveNotificationSender::send()` в процессе крона без ключа.
 *
 * Транспорт НЕ подменён двойником: работает настоящий `Request` (Longman + наша
 * надстройка). Тест только воспроизводит состояние свежего крон-процесса — статический
 * `Longman\TelegramBot\Request::$telegram` = null (прошлые тесты могли его поднять) — и
 * ставит Guzzle-клиент с MockHandler как сетевой уровень: он записывает каждый HTTP-вызов
 * и не пускает запрос в настоящий api.telegram.org. Убери гейт `TelegramBridge::ensure()`
 * из send() — и `Request::send()` упадёт на null-мосте (исключение наружу), а с поднятым
 * кем-то мостом — уйдёт в сеть и отметит «успех» (clearBlocked + info); оба случая красные.
 *
 * Модель пользователя — подкласс без БД (строка получателя), чтобы тест шёл на пустой базе.
 *
 * @internal
 */
final class PveNotificationSenderNoKeyTest extends CIUnitTestCase
{
    private string|false $savedKey;
    private string|false $savedUser;
    private mixed $savedRequestTelegram;
    private mixed $savedRequestClient;

    /** @var list<array<string, mixed>> */
    private array $httpHistory = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->savedKey  = getenv('telegram.API_KEY');
        $this->savedUser = getenv('telegram.BOT_USERNAME');

        $this->savedRequestTelegram = $this->requestStatic('telegram')->getValue();
        $this->savedRequestClient   = $this->requestStatic('client')->getValue();

        TelegramBridge::reset();
        $this->requestStatic('telegram')->setValue(null, null);

        $this->httpHistory = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], (string) json_encode([
                'ok'     => true,
                'result' => ['message_id' => 1, 'date' => 0, 'chat' => ['id' => 777, 'type' => 'private']],
            ])),
        ]));
        $stack->push(Middleware::history($this->httpHistory));
        LongmanRequest::setClient(new Client(['handler' => $stack, 'base_uri' => 'https://api.telegram.org']));
    }

    protected function tearDown(): void
    {
        putenv($this->savedKey === false ? 'telegram.API_KEY' : 'telegram.API_KEY=' . $this->savedKey);
        putenv($this->savedUser === false ? 'telegram.BOT_USERNAME' : 'telegram.BOT_USERNAME=' . $this->savedUser);

        TelegramBridge::reset();
        $this->requestStatic('telegram')->setValue(null, $this->savedRequestTelegram);
        $this->requestStatic('client')->setValue(null, $this->savedRequestClient);

        parent::tearDown();
    }

    public function testNoKeyDoesNotThrowDoesNotSendAndLogsError(): void
    {
        putenv('telegram.API_KEY');
        putenv('telegram.BOT_USERNAME');

        $model  = new NoKeyRecordingTelegramUserModel();
        $sender = new PveNotificationSender($model);

        // Исключение наружу провалит тест само по себе.
        $sender->send(['name' => 'Тестер', 'telegram_user_id' => 25], 'Бой окончен');

        $this->assertSame([], $this->httpHistory, 'sendMessage ушёл в сеть без поднятого моста');
        $this->assertFalse($model->cleared, 'ложный успех: clearBlocked() вызван, хотя ничего не отправлено');
        $this->assertFalse($model->marked, 'markBlocked() без отправки');
        $this->assertLogged('error', 'PvE notify: мост Telegram не поднят, сообщение chat_id=777 не отправлено');
        $this->assertFalse(
            TestLogger::didLog('info', 'Сообщение успешно отправлено в Telegram пользователю ID=777'),
            'ложный успех: в логе «успешно отправлено»'
        );
    }

    /**
     * Контрольная: с ключом (валидным по формату) тот же путь реально доходит до отправки,
     * и харнес это видит — значит «ноль отправок» в тесте выше не пустое утверждение.
     */
    public function testWithKeyTheSamePathReachesSend(): void
    {
        putenv('telegram.API_KEY=123456:test-stub-format-only');
        putenv('telegram.BOT_USERNAME=test_stub_bot');

        $model = new NoKeyRecordingTelegramUserModel();
        (new PveNotificationSender($model))->send(['name' => 'Тестер', 'telegram_user_id' => 25], 'Бой окончен');

        // Под PHPUNIT_TESTSUITE (его определяют некоторые тесты набора) Longman отдаёт фейковый
        // ok без HTTP; иначе запрос ловит MockHandler. В обоих случаях путь дошёл до успеха.
        $this->assertTrue($model->cleared, 'с ключом отправка не дошла до успеха — харнес слеп');
        if (! defined('PHPUNIT_TESTSUITE')) {
            $this->assertCount(1, $this->httpHistory);
        }
    }

    private function requestStatic(string $name): ReflectionProperty
    {
        $prop = new ReflectionProperty(LongmanRequest::class, $name);
        $prop->setAccessible(true);

        return $prop;
    }
}

/**
 * Получатель без БД: строка telegram_users + запись о markBlocked/clearBlocked.
 *
 * @internal
 */
final class NoKeyRecordingTelegramUserModel extends TelegramUserModel
{
    public bool $cleared = false;
    public bool $marked  = false;

    public function find($id = null)
    {
        return ['id' => 25, 'telegram_id' => 777, 'username' => 'tester', 'blocked_at' => null];
    }

    public function markBlocked(string $telegramId): void
    {
        $this->marked = true;
    }

    public function clearBlocked(string $telegramId): void
    {
        $this->cleared = true;
    }
}
