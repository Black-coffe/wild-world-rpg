<?php

declare(strict_types=1);

use App\Services\Web\OAuthProviderFactory;
use App\Services\Web\YandexOAuthProvider;
use CodeIgniter\Test\CIUnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessToken;
use Psr\Http\Message\RequestInterface;

/**
 * web-accounts-p0-07 (ADR-188) — собственный провайдер Яндекс ID: PKCE S256 в authorize URL,
 * code_verifier в запросе токена, `Authorization: OAuth <token>` на userinfo, subject = `id`.
 * HTTP подменён Guzzle MockHandler — в сеть тест не ходит.
 *
 * @internal
 */
final class YandexOAuthProviderTest extends CIUnitTestCase
{
    /** @var list<array{request: RequestInterface}> */
    private array $history = [];

    public function testAuthorizeUrlCarriesS256ChallengeOfTheVerifier(): void
    {
        $provider = $this->provider([]);
        $url      = $provider->getAuthorizationUrl();

        $this->assertStringStartsWith(YandexOAuthProvider::AUTHORIZE_URL . '?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        $verifier = $provider->getPkceCode();
        $this->assertIsString($verifier);
        $this->assertGreaterThanOrEqual(43, strlen($verifier));
        $expectedChallenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $this->assertSame('code', $q['response_type'] ?? null);
        $this->assertSame('yandex-client', $q['client_id'] ?? null);
        $this->assertSame('https://example.test/account/oauth/yandex/callback', $q['redirect_uri'] ?? null);
        $this->assertSame($provider->getState(), $q['state'] ?? null);
        $this->assertSame('S256', $q['code_challenge_method'] ?? null);
        $this->assertSame($expectedChallenge, $q['code_challenge'] ?? null);
        $this->assertArrayNotHasKey('approval_prompt', $q);
        $this->assertArrayNotHasKey('scope', $q, 'empty scope is dropped: the app registration defines the rights');
    }

    public function testTokenRequestSendsVerifierAndUserinfoUsesOAuthScheme(): void
    {
        $provider = $this->provider([
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['access_token' => 'y0_token', 'token_type' => 'bearer', 'expires_in' => 3600])),
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['id' => '1234567890', 'login' => 'wanderer', 'default_email' => 'wanderer@yandex.ru'])),
        ]);
        $provider->setPkceCode('verifier-from-session-0123456789-0123456789-abc');

        $token = $provider->getAccessToken('authorization_code', ['code' => 'the-code']);
        $this->assertInstanceOf(AccessToken::class, $token);
        $this->assertSame('y0_token', $token->getToken());

        $owner = $provider->getResourceOwner($token);
        $this->assertSame('1234567890', $owner->getId());
        $this->assertSame('wanderer@yandex.ru', $owner->toArray()['default_email']);

        $this->assertCount(2, $this->history);
        $tokenRequest = $this->history[0]['request'];
        $this->assertSame('POST', $tokenRequest->getMethod());
        $this->assertSame(YandexOAuthProvider::TOKEN_URL, (string) $tokenRequest->getUri());
        parse_str((string) $tokenRequest->getBody(), $form);
        $this->assertSame('authorization_code', $form['grant_type'] ?? null);
        $this->assertSame('the-code', $form['code'] ?? null);
        $this->assertSame('verifier-from-session-0123456789-0123456789-abc', $form['code_verifier'] ?? null);
        $this->assertSame('yandex-client', $form['client_id'] ?? null);

        $infoRequest = $this->history[1]['request'];
        $this->assertSame('GET', $infoRequest->getMethod());
        $this->assertSame(YandexOAuthProvider::USERINFO_URL, (string) $infoRequest->getUri());
        $this->assertSame('OAuth y0_token', $infoRequest->getHeaderLine('Authorization'));
    }

    public function testErrorResponseThrows(): void
    {
        $provider = $this->provider([
            new Response(400, ['Content-Type' => 'application/json'], (string) json_encode(['error' => 'bad_verification_code', 'error_description' => 'Invalid code'])),
        ]);

        $this->expectException(IdentityProviderException::class);
        $this->expectExceptionMessage('Invalid code');
        $provider->getAccessToken('authorization_code', ['code' => 'stale']);
    }

    public function testFactoryReportsUnavailableWithoutEnvAndBuildsWithIt(): void
    {
        $keys   = ['YANDEX_OAUTH_CLIENT_ID', 'YANDEX_OAUTH_CLIENT_SECRET', 'GOOGLE_OAUTH_CLIENT_ID', 'GOOGLE_OAUTH_CLIENT_SECRET'];
        $backup = [];
        foreach ($keys as $key) {
            $backup[$key] = $_SERVER[$key] ?? null;
            $_SERVER[$key] = $_ENV[$key] = '';
        }

        try {
            $factory = new OAuthProviderFactory();
            foreach (OAuthProviderFactory::PROVIDERS as $p) {
                $this->assertFalse($factory->isConfigured($p));
                $this->assertStringContainsString('не подключён', $factory->unavailableReason($p));
            }

            $_SERVER['YANDEX_OAUTH_CLIENT_ID'] = $_ENV['YANDEX_OAUTH_CLIENT_ID'] = 'id';
            $this->assertFalse($factory->isConfigured('yandex'), 'id without secret is not enough');
            $_SERVER['YANDEX_OAUTH_CLIENT_SECRET'] = $_ENV['YANDEX_OAUTH_CLIENT_SECRET'] = 'secret';
            $this->assertTrue($factory->isConfigured('yandex'));
            $this->assertSame('', $factory->unavailableReason('yandex'));
            $this->assertInstanceOf(YandexOAuthProvider::class, $factory->make('yandex'));
        } finally {
            foreach ($backup as $key => $value) {
                if ($value === null) {
                    unset($_SERVER[$key], $_ENV[$key]);
                } else {
                    $_SERVER[$key] = $_ENV[$key] = $value;
                }
            }
        }
    }

    /**
     * @param list<Response> $responses
     */
    private function provider(array $responses): YandexOAuthProvider
    {
        $this->history = [];
        $stack         = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new YandexOAuthProvider([
            'clientId'     => 'yandex-client',
            'clientSecret' => 'yandex-secret',
            'redirectUri'  => 'https://example.test/account/oauth/yandex/callback',
        ], ['httpClient' => new Client(['handler' => $stack])]);
    }
}
