<?php

declare(strict_types=1);

namespace App\Services\Web;

/**
 * ADR-061 — Verify Telegram Login Widget callback payload.
 *
 * Telegram отдаёт нам набор полей + `hash`. Нужно:
 *   1. Собрать data_check_string = отсортированные по ключу `key=value`, объединённые «\n».
 *   2. secret_key = sha256(bot_token, raw=true).
 *   3. expected = hmac_sha256(data_check_string, secret_key).
 *   4. hash_equals(expected, payload_hash).
 *   5. auth_date не старше MAX_AGE_SECONDS.
 *
 * Pure service: GameSettings/Env reader, без БД. Тестируется юнитами.
 *
 * Refs: https://core.telegram.org/widgets/login#checking-authorization
 */
class TelegramLoginVerifier
{
    /** 24 часа — стандартный TTL подписи. */
    public const MAX_AGE_SECONDS = 86400;

    private string $botToken;
    private int    $now;

    public function __construct(?string $botToken = null, ?int $now = null)
    {
        if ($botToken !== null) {
            $this->botToken = $botToken;
        } else {
            $envToken       = env('telegram.API_KEY');
            $this->botToken = is_string($envToken) ? $envToken : '';
        }
        $this->now = $now ?? time();
    }

    /**
     * @param array<string,mixed> $payload Telegram callback params (id, first_name, ..., auth_date, hash)
     *
     * @return array{ok: bool, error: string, tg_id: int, first_name: string, last_name: string, username: string, photo_url: string}
     */
    public function verify(array $payload): array
    {
        $failBase = ['ok' => false, 'tg_id' => 0, 'first_name' => '', 'last_name' => '', 'username' => '', 'photo_url' => ''];
        if ($this->botToken === '') {
            return array_merge($failBase, ['error' => 'bot_token_missing']);
        }
        $hash = is_string($payload['hash'] ?? null) ? $payload['hash'] : '';
        if ($hash === '') {
            return array_merge($failBase, ['error' => 'hash_missing']);
        }

        // Сбор data_check_string
        $data = [];
        foreach ($payload as $key => $val) {
            if ($key === 'hash') {
                continue;
            }
            if (! is_scalar($val)) {
                continue;
            }
            $data[(string) $key] = (string) $val;
        }
        if (! isset($data['id'], $data['auth_date'])) {
            return array_merge($failBase, ['error' => 'required_fields_missing']);
        }
        if (! is_numeric($data['id']) || ! is_numeric($data['auth_date'])) {
            return array_merge($failBase, ['error' => 'non_numeric_fields']);
        }

        $authDate = (int) $data['auth_date'];
        if ($authDate <= 0 || ($this->now - $authDate) > self::MAX_AGE_SECONDS) {
            return array_merge($failBase, ['error' => 'auth_date_expired']);
        }
        if ($authDate > $this->now + 300) { // future > 5 мин — точно подделка
            return array_merge($failBase, ['error' => 'auth_date_future']);
        }

        ksort($data);
        $pairs = [];
        foreach ($data as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }
        $dataCheckString = implode("\n", $pairs);

        $secretKey    = hash('sha256', $this->botToken, true);
        $expectedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

        if (! hash_equals($expectedHash, $hash)) {
            return array_merge($failBase, ['error' => 'hash_invalid']);
        }

        return [
            'ok'         => true,
            'error'      => '',
            'tg_id'      => (int) $data['id'],
            'first_name' => $data['first_name'] ?? '',
            'last_name'  => $data['last_name']  ?? '',
            'username'   => $data['username']   ?? '',
            'photo_url'  => $data['photo_url']  ?? '',
        ];
    }
}
