<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Web;

use App\Services\Web\TelegramLoginVerifier;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-061 — verify TelegramLoginVerifier обрабатывает все ветки HMAC-check'а.
 *
 * Все хеши вычисляются от тестового токена 'TEST_BOT_TOKEN' через ту же
 * формулу: secret_key = sha256(token, raw=true), HMAC-sha256(data_check_string, secret_key).
 *
 * @internal
 */
final class TelegramLoginVerifierTest extends CIUnitTestCase
{
    private const BOT_TOKEN = 'TEST_BOT_TOKEN_v1';

    /**
     * @param array<string,int|string> $data ключи и значения payload'а без `hash`
     */
    private function makeValidHash(array $data): string
    {
        ksort($data);
        $pairs = [];
        foreach ($data as $k => $v) {
            $pairs[] = $k . '=' . (string) $v;
        }
        $dataCheckString = implode("\n", $pairs);
        $secretKey       = hash('sha256', self::BOT_TOKEN, true);
        return hash_hmac('sha256', $dataCheckString, $secretKey);
    }

    public function testValidPayloadAuthorised(): void
    {
        $now    = 1_900_000_000;
        $fields = [
            'id'         => 42,
            'first_name' => 'Andrei',
            'username'   => 'andrei_test',
            'auth_date'  => $now - 10,
        ];
        $payload         = $fields;
        $payload['hash'] = $this->makeValidHash($fields);

        $verifier = new TelegramLoginVerifier(self::BOT_TOKEN, $now);
        $result   = $verifier->verify($payload);

        $this->assertTrue($result['ok']);
        $this->assertSame(42, $result['tg_id']);
        $this->assertSame('Andrei', $result['first_name']);
        $this->assertSame('andrei_test', $result['username']);
    }

    public function testHashInvalidRejected(): void
    {
        $now    = 1_900_000_000;
        $fields = [
            'id'        => 42,
            'auth_date' => $now - 10,
        ];
        $payload         = $fields;
        $payload['hash'] = str_repeat('a', 64); // явно неверный

        $verifier = new TelegramLoginVerifier(self::BOT_TOKEN, $now);
        $result   = $verifier->verify($payload);

        $this->assertFalse($result['ok']);
        $this->assertSame('hash_invalid', $result['error']);
    }

    public function testExpiredAuthDateRejected(): void
    {
        $now    = 1_900_000_000;
        $fields = [
            'id'        => 42,
            'auth_date' => $now - 90_000, // > 24h
        ];
        $payload         = $fields;
        $payload['hash'] = $this->makeValidHash($fields);

        $verifier = new TelegramLoginVerifier(self::BOT_TOKEN, $now);
        $result   = $verifier->verify($payload);

        $this->assertFalse($result['ok']);
        $this->assertSame('auth_date_expired', $result['error']);
    }

    public function testFutureAuthDateRejected(): void
    {
        $now    = 1_900_000_000;
        $fields = [
            'id'        => 42,
            'auth_date' => $now + 1_000, // явно из будущего
        ];
        $payload         = $fields;
        $payload['hash'] = $this->makeValidHash($fields);

        $verifier = new TelegramLoginVerifier(self::BOT_TOKEN, $now);
        $result   = $verifier->verify($payload);

        $this->assertFalse($result['ok']);
        $this->assertSame('auth_date_future', $result['error']);
    }

    public function testMissingHashRejected(): void
    {
        $verifier = new TelegramLoginVerifier(self::BOT_TOKEN, 1_900_000_000);
        $result   = $verifier->verify(['id' => 42, 'auth_date' => 1_899_999_990]);
        $this->assertFalse($result['ok']);
        $this->assertSame('hash_missing', $result['error']);
    }

    public function testMissingRequiredFieldRejected(): void
    {
        $verifier = new TelegramLoginVerifier(self::BOT_TOKEN, 1_900_000_000);
        $result   = $verifier->verify(['first_name' => 'X', 'hash' => str_repeat('b', 64)]);
        $this->assertFalse($result['ok']);
        $this->assertSame('required_fields_missing', $result['error']);
    }

    public function testEmptyBotTokenRejected(): void
    {
        $verifier = new TelegramLoginVerifier('', 1_900_000_000);
        $result   = $verifier->verify(['id' => 42, 'auth_date' => 1_899_999_990, 'hash' => 'x']);
        $this->assertFalse($result['ok']);
        $this->assertSame('bot_token_missing', $result['error']);
    }

    public function testNonNumericIdRejected(): void
    {
        $verifier = new TelegramLoginVerifier(self::BOT_TOKEN, 1_900_000_000);
        $result   = $verifier->verify(['id' => 'abc', 'auth_date' => 1_899_999_990, 'hash' => str_repeat('a', 64)]);
        $this->assertFalse($result['ok']);
        $this->assertSame('non_numeric_fields', $result['error']);
    }
}
