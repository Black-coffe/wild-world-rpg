<?php

declare(strict_types=1);

namespace App\Services\Web;

use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\GenericResourceOwner;
use League\OAuth2\Client\Token\AccessToken;
use Psr\Http\Message\ResponseInterface;

/**
 * web-accounts-p0-07 (ADR-188) — вход через Яндекс ID поверх `league/oauth2-client`.
 *
 * Собственный провайдер вместо `aego/oauth2-yandex` (заброшен с 2018). Эндпоинты Яндекс ID:
 *   - authorize: https://oauth.yandex.ru/authorize (code + PKCE S256);
 *   - token:     POST https://oauth.yandex.ru/token (grant_type=authorization_code + code_verifier);
 *   - userinfo:  GET https://login.yandex.ru/info?format=json, заголовок `Authorization: OAuth <token>`.
 * Subject аккаунта = поле `id` ответа userinfo.
 *
 * Scope не передаётся: Яндекс берёт права, заданные при регистрации приложения (нужен только
 * «Доступ к логину, имени и фамилии, полу» — login:info; login:email — по желанию, для подписи).
 */
class YandexOAuthProvider extends AbstractProvider
{
    public const AUTHORIZE_URL = 'https://oauth.yandex.ru/authorize';
    public const TOKEN_URL     = 'https://oauth.yandex.ru/token';
    public const USERINFO_URL  = 'https://login.yandex.ru/info?format=json';

    public function getBaseAuthorizationUrl(): string
    {
        return self::AUTHORIZE_URL;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function getBaseAccessTokenUrl(array $params): string
    {
        return self::TOKEN_URL;
    }

    public function getResourceOwnerDetailsUrl(AccessToken $token): string
    {
        return self::USERINFO_URL;
    }

    /**
     * @return list<string>
     */
    protected function getDefaultScopes(): array
    {
        return [];
    }

    protected function getPkceMethod(): string
    {
        return self::PKCE_METHOD_S256;
    }

    /**
     * Базовый класс всегда кладёт `scope` и `approval_prompt`; Яндексу не нужно ни то, ни другое —
     * пустой scope убираем, чтобы действовали права приложения.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    protected function getAuthorizationParameters(array $options): array
    {
        $params = parent::getAuthorizationParameters($options);
        unset($params['approval_prompt']);
        if (($params['scope'] ?? '') === '') {
            unset($params['scope']);
        }

        return $params;
    }

    /**
     * Яндекс принимает токен в схеме `OAuth`, не `Bearer`.
     *
     * @param mixed $token
     *
     * @return array<string, string>
     */
    protected function getAuthorizationHeaders($token = null): array
    {
        $value = $token instanceof AccessToken ? $token->getToken() : (is_string($token) ? $token : '');

        return $value === '' ? [] : ['Authorization' => 'OAuth ' . $value];
    }

    /**
     * @param mixed $data
     *
     * @throws IdentityProviderException
     */
    protected function checkResponse(ResponseInterface $response, $data): void
    {
        $status = $response->getStatusCode();
        $error  = is_array($data) ? ($data['error_description'] ?? $data['error'] ?? null) : null;

        if ($status >= 400 || $error !== null) {
            $message = is_string($error) ? $error : $response->getReasonPhrase();

            throw new IdentityProviderException($message, $status, $data);
        }
    }

    /**
     * @param array<string, mixed> $response
     */
    protected function createResourceOwner(array $response, AccessToken $token): GenericResourceOwner
    {
        return new GenericResourceOwner($response, 'id');
    }
}
