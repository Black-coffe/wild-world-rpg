<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Telegram;

use App\Services\Telegram\TelegramBridge;
use App\TaskHandlers\BaseTaskHandler;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * cron-delivery-integrity-01 — подъём моста не зависит от локального `.env`: тест сам
 * выставляет ключ (или его отсутствие) через putenv и возвращает окружение как было.
 * Путь «нет ключа» идёт через настоящий конструктор `Longman\TelegramBot\Telegram`,
 * без транспортного двойника.
 *
 * @internal
 */
final class TelegramBridgeTest extends CIUnitTestCase
{
    private string|false $savedKey;
    private string|false $savedUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedKey  = getenv('telegram.API_KEY');
        $this->savedUser = getenv('telegram.BOT_USERNAME');
        TelegramBridge::reset();
    }

    protected function tearDown(): void
    {
        putenv($this->savedKey === false ? 'telegram.API_KEY' : 'telegram.API_KEY=' . $this->savedKey);
        putenv($this->savedUser === false ? 'telegram.BOT_USERNAME' : 'telegram.BOT_USERNAME=' . $this->savedUser);
        TelegramBridge::reset();
        parent::tearDown();
    }

    public function testEmptyKeyReturnsFalseAndLogsError(): void
    {
        putenv('telegram.API_KEY');

        $this->assertFalse(TelegramBridge::ensure());
        $this->assertNull(TelegramBridge::instance());
        $this->assertLogged('error', '[TelegramBridge] Telegram init failed: API KEY not defined!');
    }

    public function testMalformedKeyReturnsFalseAndLogsError(): void
    {
        putenv('telegram.API_KEY=invalid');

        $this->assertFalse(TelegramBridge::ensure());
        $this->assertLogged('error', '[TelegramBridge] Telegram init failed: Invalid API KEY defined!');
    }

    public function testValidKeyIsIdempotent(): void
    {
        // Валиден ПО ФОРМАТУ: конструктор проверяет только регэксп, сети не трогает.
        putenv('telegram.API_KEY=123456:test-stub-format-only');
        putenv('telegram.BOT_USERNAME=test_stub_bot');

        $this->assertTrue(TelegramBridge::ensure());
        $first = TelegramBridge::instance();
        $this->assertNotNull($first);

        // Даже если ключ пропал, второй вызов не пересоздаёт мост.
        putenv('telegram.API_KEY');
        $this->assertTrue(TelegramBridge::ensure());
        $this->assertSame($first, TelegramBridge::instance());
    }

    public function testBaseTaskHandlerTelegramDoesNotThrowWithoutKey(): void
    {
        putenv('telegram.API_KEY');

        $handler = new class extends BaseTaskHandler {
            public function handle(array $task = []): void
            {
            }

            public function probe(): ?\Longman\TelegramBot\Telegram
            {
                return $this->telegram();
            }
        };

        $this->assertNull($handler->probe());
    }
}
