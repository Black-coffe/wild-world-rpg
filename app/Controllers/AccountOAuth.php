<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\GameSettings\GameSettingsReaderTrait;
use App\Services\Web\AccountService;
use App\Services\Web\AccountSession;
use App\Services\Web\OAuthProviderFactory;
use CodeIgniter\Config\Factories;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use Config\Services;
use League\OAuth2\Client\Token\AccessToken;
use Throwable;

/**
 * web-accounts-p0-07 (ADR-188) — вход и привязка через Google / Яндекс.
 *
 * start: кладёт в сессию `oauth_state`, `oauth_intent` ('login'|'link') и для Яндекса
 * `oauth_pkce` (code_verifier, S256), затем уводит на страницу провайдера.
 * callback: снимает все три ключа ДО проверки (одноразовые), сверяет state, меняет code на
 * токен (Яндекс — с code_verifier), берёт subject и:
 *   - вошедший игрок → identity привязывается к его аккаунту; чужая identity — отказ (план A2);
 *   - известная `(provider, subject)` → вход в её аккаунт;
 *   - неизвестная → при `web.open_registration` новый аккаунт + identity и /account/character,
 *     иначе честное «аккаунта нет» и путь через код из бота.
 */
class AccountOAuth extends BaseController
{
    use GameSettingsReaderTrait;

    public const KEY_STATE  = 'oauth_state';
    public const KEY_PKCE   = 'oauth_pkce';
    public const KEY_INTENT = 'oauth_intent';

    /** Сообщения страницы входа (посетитель без аккаунта в сессии). */
    public const MSG_STATE       = 'Вход не подтверждён: ссылка устарела или открыта не из этого браузера. Попробуй ещё раз.';
    public const MSG_FAILED      = 'Не удалось получить ответ от провайдера. Попробуй ещё раз или войди почтой с паролем.';
    public const MSG_DENIED      = 'Вход отменён.';
    public const MSG_UNAVAILABLE = 'Этот способ входа сейчас недоступен. Войди почтой с паролем или через Telegram.';

    public function start(string $provider): ResponseInterface|string
    {
        $factory  = $this->factory();
        $loggedIn = (new AccountSession())->current() !== null;

        if (! $factory->isConfigured($provider)) {
            return $loggedIn
                ? redirect()->to('/account?auth=oauth_unavailable')->withCookies()
                : $this->renderLogin(['error' => self::MSG_UNAVAILABLE], 404);
        }

        $client = $factory->make($provider);
        $url    = $client->getAuthorizationUrl();

        $session = Services::session();
        $session->set(self::KEY_STATE, $client->getState());
        $session->set(self::KEY_INTENT, $loggedIn ? 'link' : 'login');
        $pkce = $client->getPkceCode();
        if (is_string($pkce) && $pkce !== '') {
            $session->set(self::KEY_PKCE, $pkce);
        } else {
            $session->remove(self::KEY_PKCE);
        }

        return redirect()->to($url)->withCookies();
    }

    public function callback(string $provider): ResponseInterface|string
    {
        $session  = Services::session();
        $expected = $session->get(self::KEY_STATE);
        $verifier = $session->get(self::KEY_PKCE);
        // Одноразовость: state и verifier снимаются при ЛЮБОМ исходе, до проверки.
        $session->remove([self::KEY_STATE, self::KEY_PKCE, self::KEY_INTENT]);

        $accountSession = new AccountSession();
        $current        = $accountSession->current();
        $state          = $this->request->getGet('state');

        if (! is_string($expected) || $expected === '' || ! is_string($state) || ! hash_equals($expected, $state)) {
            return $this->fail($current !== null, 'oauth_state', self::MSG_STATE, 400);
        }
        $error = $this->request->getGet('error');
        if (is_string($error) && $error !== '') {
            return $this->fail($current !== null, 'oauth_denied', self::MSG_DENIED, 200);
        }
        $code    = $this->request->getGet('code');
        $factory = $this->factory();
        if (! is_string($code) || $code === '' || ! $factory->isConfigured($provider)) {
            return $this->fail($current !== null, 'oauth_failed', self::MSG_FAILED, 400);
        }

        try {
            $client = $factory->make($provider);
            if (is_string($verifier) && $verifier !== '') {
                $client->setPkceCode($verifier);
            }
            $token = $client->getAccessToken('authorization_code', ['code' => $code]);
            if (! $token instanceof AccessToken) {
                return $this->fail($current !== null, 'oauth_failed', self::MSG_FAILED, 502);
            }
            $resourceOwner = $client->getResourceOwner($token);
            $rawId         = $resourceOwner->getId();
            $subject       = is_scalar($rawId) ? trim((string) $rawId) : '';
            $email         = self::emailOf($resourceOwner->toArray());
        } catch (Throwable $e) {
            log_message('warning', "AccountOAuth {$provider} exchange failed: " . $e->getMessage());

            return $this->fail($current !== null, 'oauth_failed', self::MSG_FAILED, 502);
        }
        if ($subject === '') {
            return $this->fail($current !== null, 'oauth_failed', self::MSG_FAILED, 502);
        }

        $accounts = new AccountService();
        $owner    = $accounts->findByIdentity($provider, $subject);

        if ($current !== null) {
            return redirect()->to('/account?auth=' . $this->link($accounts, $current['account_id'], $owner, $provider, $subject, $email))->withCookies();
        }

        if ($owner !== null) {
            $accountSession->login($owner);

            return redirect()->to('/account')->withCookies();
        }

        if (! $this->gsBool('web.open_registration', false)) {
            return $this->renderLogin(['notice' => self::noAccountMessage($provider)], 200);
        }

        $accountId = $this->register($accounts, $provider, $subject, $email);
        if ($accountId === null) {
            return $this->renderLogin(['error' => self::MSG_FAILED], 409);
        }
        $accountSession->login($accountId);

        return redirect()->to('/account/character')->withCookies();
    }

    public static function noAccountMessage(string $provider): string
    {
        return 'Аккаунта с этим входом через ' . OAuthProviderFactory::label($provider) . ' нет, а регистрация на сайте пока закрыта. '
            . 'Играешь в Telegram-боте? Возьми там код командой /web, введи его на странице /account/link — '
            . 'и потом привяжи ' . OAuthProviderFactory::label($provider) . ' в кабинете.';
    }

    /** Привязка к вошедшему аккаунту; возвращает код сообщения кабинета. */
    private function link(AccountService $accounts, int $accountId, ?int $owner, string $provider, string $subject, ?string $email): string
    {
        if ($owner === $accountId) {
            return 'oauth_already';
        }
        if ($owner !== null) {
            return 'oauth_taken';
        }

        return $accounts->addIdentity($accountId, $provider, $subject, null, $email) ? 'oauth_linked' : 'oauth_taken';
    }

    private function register(AccountService $accounts, string $provider, string $subject, ?string $email): ?int
    {
        $db = Database::connect();
        $db->transBegin();
        $accountId = $accounts->createAccount('web');
        if (! $accounts->addIdentity($accountId, $provider, $subject, null, $email)) {
            $db->transRollback();

            return null;
        }
        $db->transCommit();

        return $accountId;
    }

    private function fail(bool $loggedIn, string $code, string $message, int $status): ResponseInterface|string
    {
        return $loggedIn
            ? redirect()->to('/account?auth=' . $code)->withCookies()
            : $this->renderLogin(['error' => $message], $status);
    }

    /**
     * @param array<string, string> $data
     */
    private function renderLogin(array $data, int $status): string
    {
        $this->response->setStatusCode($status);
        $rawBot = env('telegram.BOT_USERNAME');

        return view('site/account_login', $data + [
            'error'       => null,
            'notice'      => null,
            'email'       => '',
            'botUsername' => is_string($rawBot) ? ltrim($rawBot, '@') : '',
            'meta'        => AccountAuth::meta(),
        ]);
    }

    private function factory(): OAuthProviderFactory
    {
        // Через Factories — тест подменяет фабрику (injectMock) с поддельным HTTP-клиентом.
        $factory = Factories::get('libraries', OAuthProviderFactory::class);

        return $factory instanceof OAuthProviderFactory ? $factory : new OAuthProviderFactory();
    }

    /**
     * Подпись identity в кабинете: Google — `email`, Яндекс — `default_email`.
     *
     * @param array<array-key, mixed> $info
     */
    private static function emailOf(array $info): ?string
    {
        $email = $info['email'] ?? $info['default_email'] ?? null;

        return is_string($email) && $email !== '' ? mb_substr($email, 0, 191) : null;
    }
}
