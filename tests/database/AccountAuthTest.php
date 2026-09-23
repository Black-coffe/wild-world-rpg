<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Controllers\AccountAuth;
use App\Database\Migrations\CreateAccountsTables;
use App\Database\Migrations\CreateCharactersTable;
use App\Database\Migrations\CreateSiteCategoriesTable;
use App\Database\Migrations\CreateTelegramUsersTable;
use App\Database\Migrations\LinkCharactersToAccounts;
use App\Filters\AccountThrottleFilter;
use App\Services\Web\AccountAuthService;
use App\Services\Web\AccountService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Security\Exceptions\SecurityException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Accounts;
use Config\Database;
use Config\Services;

/**
 * web-accounts-p0-05 (ADR-188) — вход email+пароль, лимит попыток, CSRF на формах аккаунта,
 * страница входа (форма email всегда), Telegram-виджет как identity (правило A2).
 *
 * @internal
 */
final class AccountAuthTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = false;

    private const BOT_TOKEN = '123456:test-token-for-widget';

    private BaseConnection $conn;

    private ?Forge $forgeInstance = null;

    /** @var array<string,bool> */
    private array $created = [];

    /** @var array<string, mixed> */
    private array $envBackup = [];

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

        foreach (['telegram.API_KEY', 'telegram.BOT_USERNAME'] as $key) {
            $this->envBackup[$key] = $_SERVER[$key] ?? null;
        }
        $this->mockSession();
        $this->mockCache();
        Services::resetSingle('throttler');
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
        Services::resetSingle('throttler');
        Services::resetSingle('cache');
        parent::tearDown();
    }

    public function testVerifyPasswordAcceptsRightPairAndRejectsWrongEmailOrPasswordAlike(): void
    {
        $auth      = new AccountAuthService(null, $this->conn);
        $accountId = $auth->registerWithEmail('  Auth.Test@Example.com ', 'correct horse');
        $this->assertIsInt($accountId);

        $this->assertSame($accountId, $auth->verifyPassword('auth.test@example.COM', 'correct horse'));
        $this->assertNull($auth->verifyPassword('auth.test@example.com', 'wrong password'));
        $this->assertNull($auth->verifyPassword('nobody@example.com', 'correct horse'));

        $row = $this->conn->table('account_identities')->where('account_id', $accountId)->get()->getRowArray();
        $this->assertIsArray($row);
        $this->assertSame('email', $row['provider']);
        $this->assertSame('auth.test@example.com', $row['subject']);
        $this->assertTrue(password_verify('correct horse', (string) $row['secret_hash']), 'stored via password_hash');
        $this->assertNotNull($row['last_used_at']);
    }

    public function testRegisterAndSetEmailPasswordValidate(): void
    {
        $auth = new AccountAuthService(null, $this->conn);
        $this->assertSame(AccountAuthService::ERR_INVALID_EMAIL, $auth->registerWithEmail('not-an-email', 'longenough'));
        $this->assertSame(AccountAuthService::ERR_WEAK_PASSWORD, $auth->registerWithEmail('a@example.com', 'short'));
        $first = $auth->registerWithEmail('a@example.com', 'longenough');
        $this->assertIsInt($first);
        $this->assertSame(AccountAuthService::ERR_EMAIL_TAKEN, $auth->registerWithEmail('A@example.com', 'longenough'));

        $other = (new AccountService($this->conn))->createAccount('telegram');
        $this->assertSame(AccountAuthService::ERR_EMAIL_TAKEN, $auth->setEmailPassword($other, 'a@example.com', 'longenough'));
        $this->assertTrue($auth->setEmailPassword($other, 'b@example.com', 'longenough'));
        $this->assertSame(AccountAuthService::ERR_HAS_OTHER_MAIL, $auth->setEmailPassword($other, 'c@example.com', 'longenough'));
        $this->assertTrue($auth->setEmailPassword($other, 'b@example.com', 'new password'));
        $this->assertSame($other, $auth->verifyPassword('b@example.com', 'new password'));
    }

    public function testThrottleReturns429PerIpAfterLimit(): void
    {
        $limit  = (new Accounts())->throttleIpPerMinute;
        $filter = new AccountThrottleFilter();
        for ($i = 0; $i < $limit; $i++) {
            $this->assertNull($filter->before($this->postRequest('10.0.0.1', ['email' => "u{$i}@example.com"])), "attempt {$i} blocked too early");
        }

        $response = $filter->before($this->postRequest('10.0.0.1', ['email' => 'late@example.com']));
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(429, $response->getStatusCode());
        $this->assertStringContainsString('Слишком много попыток', (string) $response->getBody());
        $this->assertStringContainsString('name="email"', (string) $response->getBody(), '429 page still carries the login form');

        $this->assertNull($filter->before($this->postRequest('10.0.0.2', ['email' => 'other@example.com'])), 'other IP is not affected');
    }

    public function testThrottleReturns429PerIdentifierAcrossIps(): void
    {
        $limit  = (new Accounts())->throttleIdentifierPerHour;
        $filter = new AccountThrottleFilter();
        for ($i = 0; $i < $limit; $i++) {
            $this->assertNull($filter->before($this->postRequest("10.1.0.{$i}", ['email' => 'Target@Example.com'])));
        }

        $response = $filter->before($this->postRequest('10.1.1.1', ['email' => 'target@example.com ']));
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(429, $response->getStatusCode());
    }

    public function testAccountFormsRejectPostWithoutCsrfToken(): void
    {
        foreach (['account/login', 'account/logout'] as $path) {
            try {
                $result = $this->post($path, ['email' => 'x@example.com', 'password' => 'whatever1']);
                $this->assertContains($result->response()->getStatusCode(), [403, 419], "{$path} accepted a POST without CSRF");
            } catch (SecurityException $e) {
                $this->assertStringContainsString('not allowed', $e->getMessage());
            }
        }
        $this->assertSame(0, $this->conn->table('account_tokens')->countAllResults());
    }

    public function testLoginPageAlwaysRendersEmailForm(): void
    {
        foreach (['', 'wildworldrpg_bot'] as $bot) {
            $_SERVER['telegram.BOT_USERNAME'] = $_ENV['telegram.BOT_USERNAME'] = $bot;
            $result = $this->get('account/login');
            $result->assertStatus(200);
            $body = (string) $result->response()->getBody();
            $this->assertStringContainsString('name="email"', $body, "bot='{$bot}'");
            $this->assertStringContainsString('name="password"', $body);
            $this->assertStringContainsString('name="remember"', $body);
            $this->assertMatchesRegularExpression('/<form class="auth-form" action="[^"]*login" method="post">/', $body);
            $this->assertStringContainsString(csrf_token(), $body, 'form carries the CSRF field');
            $this->assertSame($bot !== '', str_contains($body, 'telegram-widget.js'));
        }
        $this->assertSame(AccountAuth::BAD_CREDENTIALS, 'Неверная почта или пароль.');
    }

    public function testWidgetLoginLandsOnCharacterAccount(): void
    {
        $tgUser = $this->insertTelegramUser(900003001);
        $charId = $this->insertCharacter($tgUser);

        $result = $this->get('login/telegram/callback', $this->widgetPayload(900003001) + ['next' => '/account']);

        $result->assertRedirectTo('/account?auth=ok');
        $session   = Services::session();
        $accountId = (new AccountService($this->conn))->ensureForTelegram($tgUser);
        $this->assertSame($accountId, $session->get('account_id'));
        $this->assertSame($charId, $session->get('character_id'));
        $this->assertSame($tgUser, $session->get('tg_user_id'));
    }

    public function testWidgetLinkWhileLoggedInWithoutCharacterMergesIntoCharacterAccount(): void
    {
        $auth       = new AccountAuthService(null, $this->conn);
        $webAccount = $auth->registerWithEmail('merge@example.com', 'longenough');
        $this->assertIsInt($webAccount);
        $tgUser = $this->insertTelegramUser(900003002);
        $charId = $this->insertCharacter($tgUser);
        $result = $this->withSession(['account_id' => $webAccount])->get('login/telegram/callback', $this->widgetPayload(900003002) + ['next' => '/account']);

        $result->assertRedirectTo('/account?auth=linked');
        $charAccount = (new AccountService($this->conn))->ensureForTelegram($tgUser);
        $this->assertNotSame($webAccount, $charAccount);
        $this->assertSame($charAccount, $auth->verifyPassword('merge@example.com', 'longenough'), 'email moved to character account');
        $this->assertSame(0, $this->conn->table('accounts')->where('id', $webAccount)->countAllResults());
        $this->assertSame($charId, Services::session()->get('character_id'));
    }

    public function testWidgetLinkRefusedWhenBothAccountsHaveCharacters(): void
    {
        $accounts = new AccountService($this->conn);
        $otherTg  = $this->insertTelegramUser(900003003);
        $this->insertCharacter($otherTg);
        $current = $accounts->ensureForTelegram($otherTg);
        $tgUser  = $this->insertTelegramUser(900003004);
        $this->insertCharacter($tgUser);
        $result = $this->withSession(['account_id' => $current])->get('login/telegram/callback', $this->widgetPayload(900003004));

        $result->assertRedirectTo('/account?auth=link_refused');
        $this->assertSame($current, Services::session()->get('account_id'), 'still logged into own account');
        $this->assertSame(1, $this->conn->table('account_identities')->where('account_id', $current)->countAllResults());
    }

    /**
     * @param array<string, string> $post
     */
    private function postRequest(string $ip, array $post): IncomingRequest
    {
        $request = Services::incomingrequest(null, false);
        $request->setMethod('POST');
        $request->setGlobal('post', $post);
        $request->setGlobal('server', ['REMOTE_ADDR' => $ip, 'REQUEST_METHOD' => 'POST']);

        return $request;
    }

    /**
     * @return array<string, string>
     */
    private function widgetPayload(int $telegramId): array
    {
        $_SERVER['telegram.API_KEY'] = $_ENV['telegram.API_KEY'] = self::BOT_TOKEN;
        $data = ['id' => (string) $telegramId, 'first_name' => 'Tester', 'auth_date' => (string) time()];
        ksort($data);
        $pairs = [];
        foreach ($data as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }
        $data['hash'] = hash_hmac('sha256', implode("\n", $pairs), hash('sha256', self::BOT_TOKEN, true));

        return $data;
    }

    private function insertTelegramUser(int $telegramId): int
    {
        $this->conn->table('telegram_users')->insert(['telegram_id' => $telegramId, 'created_at' => date('Y-m-d H:i:s')]);

        return (int) $this->conn->insertID();
    }

    private function insertCharacter(?int $telegramUserId): int
    {
        $this->conn->table('characters')->insert([
            'telegram_user_id' => $telegramUserId,
            'name'             => 'accauth_test_' . bin2hex(random_bytes(3)),
            'created_at'       => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->conn->insertID();
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
            $this->conn->table('characters')->like('name', 'accauth_test_', 'after')->delete();
        }
        if ($this->conn->tableExists('telegram_users')) {
            $this->conn->table('telegram_users')->where('telegram_id >=', 900003000)->where('telegram_id <', 900004000)->delete();
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
