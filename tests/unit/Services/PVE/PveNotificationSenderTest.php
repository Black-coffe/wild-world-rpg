<?php

declare(strict_types=1);

namespace Tests\Unit\Services\PVE;

use App\Models\TelegramUserModel;
use App\Services\PVE\PveNotificationSender;
use App\Services\Telegram\TelegramBridge;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * cron-delivery-integrity-02 — `PveNotificationSender::send()` поднимает мост сам.
 *
 * Реальный `TelegramBridge` (не двойник): двойник транспорта прячет именно то, что тут
 * нужно доказать — что `ensure()` реально позван ДО `Request::sendMessage`. Если бы
 * `send()` пропустил инициализацию, реальный `Longman\TelegramBot\Request` бросил бы
 * исключение «бот не инициализирован» — тест это ловит отсутствием exception.
 *
 * @internal
 */
final class PveNotificationSenderTest extends CIUnitTestCase
{
    private ?string $savedApiKey = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedApiKey = getenv('telegram.API_KEY') === false ? null : (string) getenv('telegram.API_KEY');
        putenv('telegram.API_KEY');
        TelegramBridge::reset();
    }

    protected function tearDown(): void
    {
        putenv($this->savedApiKey === null ? 'telegram.API_KEY' : 'telegram.API_KEY=' . $this->savedApiKey);
        TelegramBridge::reset();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function player(): array
    {
        return ['id' => 491, 'name' => 'Hero', 'telegram_user_id' => 25];
    }

    public function testSendWithoutBridgeInitCallsEnsureBeforeTransportAndDoesNotThrow(): void
    {
        $fakeModel = new FakePveTelegramUserModel();
        $sender    = new PveNotificationSender($fakeModel);

        // Без API_KEY реальный ensure() провалится и залогирует свою причину — этот
        // лог появляется ТОЛЬКО если send() реально позвал TelegramBridge::ensure().
        $sender->send($this->player(), 'Бой окончен.');

        $this->assertLogContains('error', '[TelegramBridge] Telegram init failed');
    }

    public function testEnsureFalseSkipsTransportWritesErrorAndDoesNotThrow(): void
    {
        $fakeModel = new FakePveTelegramUserModel();
        $sender    = new PveNotificationSender($fakeModel);

        $sender->send($this->player(), 'Бой окончен.');

        // Свой error помимо error TelegramBridge — доказывает, что send() сам знает про отказ.
        $this->assertLogContains('error', 'мост Telegram не поднят');
        // sendMessage реально не позван: транспорт дошёл бы до markBlocked/clearBlocked
        // только через isOk() ветку после реального ответа — её тут нет.
        $this->assertSame([], $fakeModel->calls);
    }

    /**
     * blocked_at-гигиена не должна была измениться этой story — проверяем чистую
     * функцию отдельно от транспорта (не завязана на TelegramBridge).
     */
    public function testBlockedRecipientGateUnchanged(): void
    {
        $this->assertFalse(PveNotificationSender::isRecipientBlocked(null));
        $this->assertFalse(PveNotificationSender::isRecipientBlocked(''));
        $this->assertTrue(PveNotificationSender::isRecipientBlocked('2026-09-15 00:00:00'));
    }
}

/**
 * Test-double только для DB-слоя (`find`/`markBlocked`/`clearBlocked`) — Telegram-транспорт
 * в этом тесте намеренно НЕ подменён (см. докблок класса теста).
 *
 * @internal
 */
final class FakePveTelegramUserModel extends TelegramUserModel
{
    /** @var list<array{0: string, 1: string}> */
    public array $calls = [];

    /**
     * @param int|list<int|string>|string|null $id
     *
     * @return array<string, mixed>|null
     */
    public function find($id = null)
    {
        return [
            'id'           => 25,
            'telegram_id'  => '6995661239',
            'username'     => 'tester',
            'blocked_at'   => null,
        ];
    }

    public function markBlocked(string $telegramId): void
    {
        $this->calls[] = ['markBlocked', $telegramId];
    }

    public function clearBlocked(string $telegramId): void
    {
        $this->calls[] = ['clearBlocked', $telegramId];
    }
}
