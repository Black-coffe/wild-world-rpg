<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Controllers\AccountCabinet;
use App\Controllers\AccountOAuth;
use App\Database\Migrations\CreateAccountsTables;
use App\Database\Migrations\CreateCharactersTable;
use App\Database\Migrations\CreateSiteCategoriesTable;
use App\Database\Migrations\CreateTelegramUsersTable;
use App\Database\Migrations\LinkCharactersToAccounts;
use App\Services\Web\AccountAuthService;
use App\Services\Web\AccountService;
use App\Services\Web\OAuthProviderFactory;
use CodeIgniter\Config\Factories;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Database;
use Config\Services;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * web-accounts-p0-07 (ADR-188) — вход/привязка через Google и Яндекс (state, PKCE, одноразовость,
 * флаг открытой регистрации, отказ чужой identity) и кабинет (почта+пароль, отвязка, но не
 * последнего способа; кнопки провайдеров «недоступно» без env).
 *
 * HTTP к провайдерам подменён: фабрика инжектится через Factories с Guzzle MockHandler.
 *
 * @internal
 */
final class AccountCabinetTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = false;

    private const ENV_KEYS = ['GOOGLE_OAUTH_CLIENT_ID', 'GOOGLE_OAUTH_CLIENT_SECRET', 'YANDEX_OAUTH_CLIENT_ID', 'YANDEX_OAUTH_CLIENT_SECRET', 'telegram.BOT_USERNAME'];

    private BaseConnection $conn;

    private ?Forge $forgeInstance = null;

    /** @var array<string,bool> */
    private array $created = [];

    /** @var array<string, mixed> */
    private array $envBackup = [];

    /** @var list<array{request: RequestInterface}> */
    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->conn = Database::connect();
        $this->conn->resetDataCache();
        self::requireMigrationClasses();
        $forge               = Database::forge();
        $this->forgeInstance = $forge instanceof Forge ? $forge : null;

        try {
            $this->downAccounts();
            $this->deleteTestRows();
            $bases = [
                'telegram_users'  => CreateTelegramUsersTable::class,
                'characters'      => CreateCharactersTable::class,
                'site_categories' => CreateSiteCategoriesTable::class,
            ];
            foreach ($bases as $table => $class) {
                $this->created[$table] = ! $this->conn->tableExists($table);
                if ($this->created[$table]) {
                    (new $class($this->forgeInstance))->up();
                }
            }
            (new CreateAccountsTables($this->forgeInstance))->up();
            (new LinkCharactersToAccounts($this->forgeInstance))->up();
        } catch (\Throwable $e) {
            $this->cleanup();

            throw $e;
        }

        foreach (self::ENV_KEYS as $key) {
            $this->envBackup[$key] = $_SERVER[$key] ?? null;
        }
        $this->setEnv(false);
        $this->mockSession();
        $this->mockCache();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        foreach ($this->envBackup as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key], $_ENV[$key]);
            } else {
                $_SERVER[$key] = $_ENV[$key] = $value;
            }
        }
        service('superglobals')->unsetCookie('csrf_cookie_name');
        Factories::reset('libraries');
        Services::resetSingle('cache');
        Services::resetSingle('security');
        parent::tearDown();
    }

    // --- Ask 10: кнопки без env -------------------------------------------------------------

    public function testButtonsWithoutEnvAreUnavailableNotLinksAndEmailStays(): void
    {
        $body = $this->body($this->get('account/login'));

        $this->assertStringContainsString('name="email"', $body, 'email+password is always available');
        $this->assertSame(0, preg_match('#href="[^"]*account/oauth/(google|yandex)"#', $body), 'no OAuth link without env');
        $this->assertSame(2, substr_count($body, 'aria-disabled="true"><span class="provider-mark" aria-hidden="true">'
            . 'G</span>') + substr_count($body, 'aria-disabled="true"><span class="provider-mark" aria-hidden="true">Я</span>'));
        $factory = new OAuthProviderFactory();
        $this->assertStringContainsString($factory->unavailableReason('google'), $body);
        $this->assertStringContainsString($factory->unavailableReason('yandex'), $body);
    }

    public function testButtonsWithEnvAreActiveLinks(): void
    {
        $this->setEnv(true);
        $body = $this->body($this->get('account/login'));

        $this->assertMatchesRegularExpression('#<a class="provider-btn" href="[^"]*account/oauth/google">#', $body);
        $this->assertMatchesRegularExpression('#<a class="provider-btn" href="[^"]*account/oauth/yandex">#', $body);
        $this->assertStringNotContainsString('provider-btn is-unavailable" aria-disabled="true"><span class="provider-mark" aria-hidden="true">G', $body);
    }

    // --- Ask 15: state + PKCE ---------------------------------------------------------------

    public function testYandexStartStoresStateVerifierAndSendsS256Challenge(): void
    {
        $this->setEnv(true);
        $result   = $this->get('account/oauth/yandex');
        $location = $result->response()->getHeaderLine('Location');

        $this->assertStringStartsWith('https://oauth.yandex.ru/authorize?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $q);
        $session  = Services::session();
        $verifier = $session->get(AccountOAuth::KEY_PKCE);
        $this->assertIsString($verifier);
        $this->assertSame($session->get(AccountOAuth::KEY_STATE), $q['state'] ?? null);
        $this->assertSame('login', $session->get(AccountOAuth::KEY_INTENT));
        $this->assertSame('S256', $q['code_challenge_method'] ?? null);
        $this->assertSame(rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), $q['code_challenge'] ?? null);
    }

    public function testGoogleStartStoresStateWithoutVerifier(): void
    {
        $this->setEnv(true);
        $location = $this->get('account/oauth/google')->response()->getHeaderLine('Location');

        $this->assertStringStartsWith('https://accounts.google.com/', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $q);
        $this->assertSame(Services::session()->get(AccountOAuth::KEY_STATE), $q['state'] ?? null);
        $this->assertNull(Services::session()->get(AccountOAuth::KEY_PKCE));
    }

    public function testCallbackRejectsMissingStateForEachProvider(): void
    {
        foreach (OAuthProviderFactory::PROVIDERS as $provider) {
            $this->injectFactory([]);
            $result = $this->withSession(['oauth_state' => 'expected-state', 'oauth_pkce' => 'v', 'oauth_intent' => 'login'])
                ->get("account/oauth/{$provider}/callback", ['code' => 'c']);

            $result->assertStatus(400);
            $this->assertStringContainsString(AccountOAuth::MSG_STATE, $this->body($result), $provider);
            $this->assertSame([], $this->history, "{$provider}: no token exchange without state");
            $this->assertStateCleared($provider);
        }
        $this->assertSame(0, $this->conn->table('account_identities')->countAllResults());
    }

    public function testCallbackRejectsMismatchedStateForEachProvider(): void
    {
        foreach (OAuthProviderFactory::PROVIDERS as $provider) {
            $this->injectFactory([]);
            $result = $this->withSession(['oauth_state' => 'expected-state', 'oauth_intent' => 'login'])
                ->get("account/oauth/{$provider}/callback", ['code' => 'c', 'state' => 'forged-state']);

            $result->assertStatus(400);
            $this->assertStringContainsString(AccountOAuth::MSG_STATE, $this->body($result), $provider);
            $this->assertSame([], $this->history, "{$provider}: no token exchange with forged state");
            $this->assertStateCleared($provider);
        }
    }

    public function testCallbackWithoutSessionStateIsRejectedEvenIfStateParamPresent(): void
    {
        $this->injectFactory([]);
        $result = $this->get('account/oauth/yandex/callback', ['code' => 'c', 'state' => '']);

        $result->assertStatus(400);
        $this->assertSame([], $this->history);
    }

    public function testKnownYandexIdentityLogsInAndTokenRequestCarriesVerifierOnce(): void
    {
        $accounts  = new AccountService($this->conn);
        $accountId = $accounts->createAccount('web');
        $this->assertTrue($accounts->addIdentity($accountId, 'yandex', '555001'));

        $this->injectFactory([self::tokenResponse(), self::json(['id' => '555001', 'login' => 'wanderer'])]);
        $result = $this->withSession(['oauth_state' => 'st-1', 'oauth_pkce' => 'the-verifier-kept-in-session', 'oauth_intent' => 'login'])
            ->get('account/oauth/yandex/callback', ['code' => 'the-code', 'state' => 'st-1']);

        $result->assertRedirectTo('/account');
        $this->assertSame($accountId, Services::session()->get('account_id'));
        parse_str((string) $this->history[0]['request']->getBody(), $form);
        $this->assertSame('the-verifier-kept-in-session', $form['code_verifier'] ?? null);
        $this->assertSame('OAuth tok', $this->history[1]['request']->getHeaderLine('Authorization'));
        $this->assertStateCleared('yandex');

        // Повтор того же callback (реплей) — state уже снят.
        $this->injectFactory([]);
        $replay = $this->get('account/oauth/yandex/callback', ['code' => 'the-code', 'state' => 'st-1']);
        $this->assertNotSame(302, $replay->response()->getStatusCode());
        $this->assertSame([], $this->history, 'replayed state does not reach the token endpoint');
    }

    public function testKnownGoogleIdentityLogsIn(): void
    {
        $accounts  = new AccountService($this->conn);
        $accountId = $accounts->createAccount('web');
        $this->assertTrue($accounts->addIdentity($accountId, 'google', 'g-sub-1'));

        $this->injectFactory([self::tokenResponse(), self::json(['sub' => 'g-sub-1', 'email' => 'x@gmail.com'])]);
        $result = $this->withSession(['oauth_state' => 'st-g', 'oauth_intent' => 'login'])
            ->get('account/oauth/google/callback', ['code' => 'c', 'state' => 'st-g']);

        $result->assertRedirectTo('/account');
        $this->assertSame($accountId, Services::session()->get('account_id'));
    }

    // --- Ask 2 / Ask 4: неизвестная identity и флаг ---------------------------------------

    public function testUnknownIdentityWithFlagOffCreatesNothingAndPointsToBotCode(): void
    {
        $this->setOpenRegistration(false);
        $this->injectFactory([self::tokenResponse(), self::json(['id' => '777'])]);
        $result = $this->withSession(['oauth_state' => 's', 'oauth_pkce' => 'v', 'oauth_intent' => 'login'])
            ->get('account/oauth/yandex/callback', ['code' => 'c', 'state' => 's']);

        $result->assertStatus(200);
        $body = $this->body($result);
        $this->assertStringContainsString(AccountOAuth::noAccountMessage('yandex'), $body);
        $this->assertStringContainsString('/web', $body);
        $this->assertStringContainsString('/account/link', $body);
        $this->assertSame(0, $this->conn->table('accounts')->countAllResults());
        $this->assertSame(0, $this->conn->table('account_identities')->countAllResults());
        $this->assertNull(Services::session()->get('account_id'));
    }

    public function testUnknownIdentityWithFlagOnCreatesAccountAndGoesToCharacter(): void
    {
        $this->setOpenRegistration(true);
        $this->injectFactory([self::tokenResponse(), self::json(['sub' => 'g-new', 'email' => 'new@gmail.com'])]);
        $result = $this->withSession(['oauth_state' => 's', 'oauth_intent' => 'login'])
            ->get('account/oauth/google/callback', ['code' => 'c', 'state' => 's']);

        $result->assertRedirectTo('/account/character');
        $accountId = (new AccountService($this->conn))->findByIdentity('google', 'g-new');
        $this->assertIsInt($accountId);
        $this->assertSame($accountId, Services::session()->get('account_id'));
        $row = $this->conn->table('accounts')->where('id', $accountId)->get()->getRowArray();
        $this->assertSame('web', $row['acquisition_source'] ?? null);
    }

    // --- Ask 2: привязка из кабинета ------------------------------------------------------

    public function testLoggedInCallbackLinksIdentityToCurrentAccount(): void
    {
        $accountId = $this->emailAccount('linker@example.com');
        $this->injectFactory([self::tokenResponse(), self::json(['id' => '9001', 'default_email' => 'l@yandex.ru'])]);
        $result = $this->withSession(['account_id' => $accountId, 'oauth_state' => 's', 'oauth_pkce' => 'v', 'oauth_intent' => 'link'])
            ->get('account/oauth/yandex/callback', ['code' => 'c', 'state' => 's']);

        $result->assertRedirectTo('/account?auth=oauth_linked');
        $this->assertSame($accountId, (new AccountService($this->conn))->findByIdentity('yandex', '9001'));
    }

    public function testLinkingIdentityOwnedByAnotherAccountIsRefused(): void
    {
        $accounts = new AccountService($this->conn);
        $other    = $accounts->createAccount('web');
        $this->assertTrue($accounts->addIdentity($other, 'google', 'g-owned'));
        $mine = $this->emailAccount('mine@example.com');

        $this->injectFactory([self::tokenResponse(), self::json(['sub' => 'g-owned'])]);
        $result = $this->withSession(['account_id' => $mine, 'oauth_state' => 's', 'oauth_intent' => 'link'])
            ->get('account/oauth/google/callback', ['code' => 'c', 'state' => 's']);

        $result->assertRedirectTo('/account?auth=oauth_taken');
        $this->assertSame($other, $accounts->findByIdentity('google', 'g-owned'), 'not moved');
        $this->assertSame(1, $this->conn->table('accounts')->where('id', $other)->countAllResults(), 'not merged');
        $this->assertSame($mine, Services::session()->get('account_id'));
    }

    public function testCabinetAddsEmailPassword(): void
    {
        $accounts  = new AccountService($this->conn);
        $accountId = $accounts->createAccount('web');
        $this->assertTrue($accounts->addIdentity($accountId, 'google', 'g-only'));

        $result = $this->postWithCsrf(['account_id' => $accountId], 'account/identity/email', ['email' => 'Added@Example.com', 'password' => 'longenough']);

        $result->assertRedirectTo('/account?auth=email_added');
        $this->assertSame($accountId, (new AccountAuthService(null, $this->conn))->verifyPassword('added@example.com', 'longenough'));
    }

    public function testCabinetUnlinksAnyButRefusesTheLast(): void
    {
        $accounts  = new AccountService($this->conn);
        $accountId = $this->emailAccount('two@example.com');
        $this->assertTrue($accounts->addIdentity($accountId, 'yandex', 'y-two'));
        $ids = array_map(static fn (array $i): int => (int) $i['id'], $accounts->identities($accountId));
        $this->assertCount(2, $ids);

        $first = $this->postWithCsrf(['account_id' => $accountId], "account/identity/{$ids[1]}/unlink", []);
        $first->assertRedirectTo('/account?auth=unlinked');
        $this->assertCount(1, $accounts->identities($accountId));

        $last = $this->postWithCsrf(['account_id' => $accountId], "account/identity/{$ids[0]}/unlink", []);
        $last->assertRedirectTo('/account?auth=unlink_last');
        $this->assertCount(1, $accounts->identities($accountId), 'the last login method stays');

        $page = $this->withSession(['account_id' => $accountId])->get('account', ['auth' => 'unlink_last']);
        $page->assertStatus(200);
        $body = $this->body($page);
        $this->assertStringContainsString(AccountCabinet::MSG_LAST_IDENTITY, $body);
        $this->assertStringNotContainsString('/unlink"', $body, 'no unlink button on the only method');
    }

    public function testCabinetRendersIdentitiesAndAddPaths(): void
    {
        $this->setEnv(true);
        $_SERVER['telegram.BOT_USERNAME'] = $_ENV['telegram.BOT_USERNAME'] = 'wildworldrpg_bot';
        $accountId = $this->emailAccount('view@example.com');
        (new AccountService($this->conn))->addIdentity($accountId, 'google', 'g-view', null, 'view@gmail.com');

        $body = $this->body($this->withSession(['account_id' => $accountId])->get('account'));

        $this->assertStringContainsString('view@example.com', $body);
        $this->assertStringContainsString('view@gmail.com', $body);
        $this->assertSame(2, substr_count($body, '/unlink"'));
        $this->assertStringContainsString('action="' . base_url('account/identity/email') . '"', $body);
        $this->assertDoesNotMatchRegularExpression('#href="[^"]*account/oauth/google"#', $body, 'google already linked');
        $this->assertMatchesRegularExpression('#href="[^"]*account/oauth/yandex"#', $body);
        $this->assertStringContainsString('telegram-widget.js', $body);
        $this->assertStringContainsString(base_url('account/link'), $body);
        $this->assertStringContainsString(base_url('account/logout'), $body);
    }

    public function testCabinetRequiresLogin(): void
    {
        $this->get('account')->assertRedirectTo('/account/login');
    }

    // --- helpers -------------------------------------------------------------------------

    private function assertStateCleared(string $provider): void
    {
        $session = Services::session();
        foreach ([AccountOAuth::KEY_STATE, AccountOAuth::KEY_PKCE, AccountOAuth::KEY_INTENT] as $key) {
            $this->assertNull($session->get($key), "{$provider}: {$key} is single-use");
        }
    }

    /**
     * @param array<string, mixed>  $session
     * @param array<string, string> $data
     */
    private function postWithCsrf(array $session, string $path, array $data): TestResponse
    {
        $hash = bin2hex(random_bytes(16));
        service('superglobals')->setCookie('csrf_cookie_name', $hash);
        Services::resetSingle('security');

        return $this->withSession($session)->post($path, $data + ['csrf_test_name' => $hash]);
    }

    /**
     * @param list<Response> $responses
     */
    private function injectFactory(array $responses): void
    {
        $this->setEnv(true);
        $this->history = [];
        $stack         = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        Factories::injectMock('libraries', OAuthProviderFactory::class, new OAuthProviderFactory(['httpClient' => new Client(['handler' => $stack])]));
    }

    private function setEnv(bool $configured): void
    {
        foreach (array_slice(self::ENV_KEYS, 0, 4) as $key) {
            $_SERVER[$key] = $_ENV[$key] = $configured ? 'test-' . strtolower($key) : '';
        }
    }

    private function setOpenRegistration(bool $on): void
    {
        service('cache')->save('game_settings_web_open_registration', ['v' => $on, 't' => 'bool'], 60);
    }

    private function emailAccount(string $email): int
    {
        $id = (new AccountAuthService(null, $this->conn))->registerWithEmail($email, 'longenough');
        $this->assertIsInt($id);

        return $id;
    }

    private static function tokenResponse(): Response
    {
        return self::json(['access_token' => 'tok', 'token_type' => 'Bearer', 'expires_in' => 3600]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function json(array $data): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($data));
    }

    private function body(TestResponse $result): string
    {
        // esc(..., 'attr') кодирует «/» в href — сравниваем с декодированным текстом.
        return html_entity_decode((string) $result->response()->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function downAccounts(): void
    {
        $this->conn->resetDataCache();
        if ($this->conn->tableExists('characters') && $this->conn->tableExists('telegram_users')) {
            (new LinkCharactersToAccounts($this->forgeInstance))->down();
        }
        (new CreateAccountsTables($this->forgeInstance))->down();
        $this->conn->resetDataCache();
    }

    private function cleanup(): void
    {
        $this->downAccounts();
        $this->deleteTestRows();
        foreach (['site_categories', 'characters', 'telegram_users'] as $table) {
            if (($this->created[$table] ?? false) === true) {
                $this->forgeInstance?->dropTable($table, true);
            }
        }
        $this->created = [];
        $this->conn->resetDataCache();
    }

    private function deleteTestRows(): void
    {
        if ($this->conn->tableExists('characters')) {
            $this->conn->table('characters')->like('name', 'acccab_test_', 'after')->delete();
        }
    }

    private static function requireMigrationClasses(): void
    {
        $classes = [
            CreateTelegramUsersTable::class  => '2024-03-20-153728_CreateTelegramUsersTable.php',
            CreateCharactersTable::class     => '2024-03-20-154155_CreateCharactersTable.php',
            CreateSiteCategoriesTable::class => '2026-05-25-180000_CreateSiteCategoriesTable.php',
            CreateAccountsTables::class      => '2026-12-10-100001_CreateAccountsTables.php',
            LinkCharactersToAccounts::class  => '2026-12-10-100002_LinkCharactersToAccounts.php',
        ];
        foreach ($classes as $class => $file) {
            if (! class_exists($class, false)) {
                require_once APPPATH . 'Database/Migrations/' . $file;
            }
        }
    }
}
