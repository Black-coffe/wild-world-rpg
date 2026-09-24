<?php

declare(strict_types=1);

namespace App\Services\Web;

use InvalidArgumentException;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Google;

/**
 * web-accounts-p0-07 (ADR-188) — OAuth-провайдеры сайта (Google, Яндекс) из env.
 *
 * Env: `GOOGLE_OAUTH_CLIENT_ID` / `GOOGLE_OAUTH_CLIENT_SECRET`, `YANDEX_OAUTH_CLIENT_ID` /
 * `YANDEX_OAUTH_CLIENT_SECRET`. Пустой env = провайдер «недоступен»: кнопка рисуется, но не
 * ссылкой, с причиной из {@see unavailableReason()} (не прячем — игрок видит, что способ будет).
 *
 * Redirect URI = base_url('account/oauth/<provider>/callback') — его же регистрируют в консолях
 * Google Cloud и Яндекс ID.
 *
 * `$collaborators` уходят в конструктор провайдера (`httpClient` и т.п.) — для тестов.
 */
class OAuthProviderFactory
{
    public const PROVIDERS = ['google', 'yandex'];

    private const ENV = [
        'google' => ['GOOGLE_OAUTH_CLIENT_ID', 'GOOGLE_OAUTH_CLIENT_SECRET'],
        'yandex' => ['YANDEX_OAUTH_CLIENT_ID', 'YANDEX_OAUTH_CLIENT_SECRET'],
    ];

    private const LABELS = ['google' => 'Google', 'yandex' => 'Яндекс'];

    /**
     * @param array<string, mixed> $collaborators
     */
    public function __construct(private readonly array $collaborators = [])
    {
    }

    public static function label(string $provider): string
    {
        return self::LABELS[$provider] ?? $provider;
    }

    public function isConfigured(string $provider): bool
    {
        return $this->credentials($provider) !== null;
    }

    /**
     * @throws InvalidArgumentException неизвестный или не настроенный провайдер
     */
    public function make(string $provider): AbstractProvider
    {
        $credentials = $this->credentials($provider);
        if ($credentials === null) {
            throw new InvalidArgumentException("OAuthProviderFactory: provider '{$provider}' is unknown or not configured");
        }

        $options = [
            'clientId'     => $credentials[0],
            'clientSecret' => $credentials[1],
            'redirectUri'  => $this->redirectUri($provider),
        ];

        return $provider === 'google'
            ? new Google($options, $this->collaborators)
            : new YandexOAuthProvider($options, $this->collaborators);
    }

    /** Пустая строка — провайдер настроен. */
    public function unavailableReason(string $provider): string
    {
        if (! isset(self::ENV[$provider])) {
            return 'Такого способа входа нет.';
        }
        if ($this->isConfigured($provider)) {
            return '';
        }

        return 'Вход через ' . self::label($provider) . ' ещё не подключён на сервере. '
            . 'Пока входи почтой с паролем или через Telegram.';
    }

    public function redirectUri(string $provider): string
    {
        return rtrim(base_url('account/oauth/' . $provider . '/callback'), '/');
    }

    /**
     * @return array{0:string, 1:string}|null
     */
    private function credentials(string $provider): ?array
    {
        if (! isset(self::ENV[$provider])) {
            return null;
        }
        [$idKey, $secretKey] = self::ENV[$provider];
        $id     = env($idKey);
        $secret = env($secretKey);
        $id     = is_string($id) ? trim($id) : '';
        $secret = is_string($secret) ? trim($secret) : '';

        return $id !== '' && $secret !== '' ? [$id, $secret] : null;
    }
}
