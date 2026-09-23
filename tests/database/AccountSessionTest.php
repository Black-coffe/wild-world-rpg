<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Database\Migrations\CreateAccountsTables;
use App\Database\Migrations\CreateCharactersTable;
use App\Database\Migrations\CreateTelegramUsersTable;
use App\Database\Migrations\LinkCharactersToAccounts;
use App\Services\Web\AccountService;
use App\Services\Web\AccountSession;
use CodeIgniter\Cookie\Cookie;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Config\Services;

/**
 * web-accounts-p0-05 (ADR-188) — AccountSession: ключи сессии, remember-me (ротация, отказ
 * украденному/старому validator'у), logout, апгрейд legacy `tg_user_id`-сессии, web-only персонаж.
 *
 * Схема — настоящие миграции (`telegram_users`, `characters` — только если их нет) + миграции
 * аккаунтов story 01. Сессия — MockSession; «истечение сессии» = новая пустая MockSession
 * при той же remember-cookie в запросе.
 *
 * @internal
 */
final class AccountSessionTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private BaseConnection $conn;

    private ?Forge $forgeInstance = null;

    /** @var array<string,bool> */
    private array $created = [];

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
            $this->created['telegram_users'] = ! $this->conn->tableExists('telegram_users');
            if ($this->created['telegram_users']) {
                (new CreateTelegramUsersTable($this->forgeInstance))->up();
            }
            $this->created['characters'] = ! $this->conn->tableExists('characters');
            if ($this->created['characters']) {
                (new CreateCharactersTable($this->forgeInstance))->up();
            }
            (new CreateAccountsTables($this->forgeInstance))->up();
            (new LinkCharactersToAccounts($this->forgeInstance))->up();
        } catch (\Throwable $e) {
            $this->cleanup();

            throw $e;
        }

        $this->mockSession();
        $this->useCookie(null);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        Services::resetSingle('request');
        Services::resetSingle('response');
        parent::tearDown();
    }

    public function testLoginWritesAccountCharacterAndLegacyTelegramKeys(): void
    {
        $tgUser    = $this->insertTelegramUser(900002001);
        $charId    = $this->insertCharacter($tgUser);
        $accountId = (new AccountService($this->conn))->ensureForTelegram($tgUser);

        $this->sessionService()->login($accountId);

        $session = Services::session();
        $this->assertSame($accountId, $session->get('account_id'));
        $this->assertSame($charId, $session->get('character_id'));
        $this->assertSame($tgUser, $session->get('tg_user_id'));
        $this->assertSame(0, $this->conn->table('account_tokens')->countAllResults(), 'no remember token without remember');
        $this->assertNotNull($this->accountField($accountId, 'last_login_at'));
        $this->assertSame(
            ['account_id' => $accountId, 'character_id' => $charId, 'telegram_user_id' => $tgUser],
            $this->sessionService()->current()
        );
    }

    public function testWebOnlyCharacterHasCharacterIdAndNoTelegramKey(): void
    {
        $accounts  = new AccountService($this->conn);
        $accountId = $accounts->createAccount('web');
        $charId    = $this->insertCharacter(null);
        $accounts->attachCharacter($accountId, $charId);

        $this->sessionService()->login($accountId);

        $this->assertNull(Services::session()->get('tg_user_id'));
        $this->assertSame($charId, $this->sessionService()->characterId());
        $this->assertSame($accountId, $this->sessionService()->accountId());
    }

    public function testLegacyTelegramOnlySessionIsUpgradedToAccount(): void
    {
        $tgUser = $this->insertTelegramUser(900002002);
        $charId = $this->insertCharacter($tgUser);
        Services::session()->set('tg_user_id', $tgUser);

        $current = $this->sessionService()->current();

        $this->assertNotNull($current);
        $this->assertSame($charId, $current['character_id']);
        $this->assertSame($tgUser, $current['telegram_user_id']);
        $this->assertSame($current['account_id'], Services::session()->get('account_id'));
        $this->assertSame($current['account_id'], $this->accountOfCharacter($charId));
    }

    public function testRememberedLoginSurvivesSessionExpiryAndRotatesToken(): void
    {
        $tgUser    = $this->insertTelegramUser(900002003);
        $charId    = $this->insertCharacter($tgUser);
        $accountId = (new AccountService($this->conn))->ensureForTelegram($tgUser);

        $this->sessionService()->login($accountId, true);
        $first = $this->issuedCookieValue();
        $this->assertSame(1, $this->conn->table('account_tokens')->where('purpose', 'remember')->countAllResults());
        [$selector, $validator] = explode(':', $first, 2);
        $row = $this->conn->table('account_tokens')->where('selector', $selector)->get()->getRowArray();
        $this->assertIsArray($row);
        $this->assertSame(hash('sha256', $validator), $row['validator_hash'], 'only the hash is stored');

        // Сессия истекла: пустая сессия, в запросе — только cookie.
        $this->mockSession();
        $this->useCookie($first);
        $current = $this->sessionService()->current();

        $this->assertNotNull($current);
        $this->assertSame($accountId, $current['account_id']);
        $this->assertSame($charId, $current['character_id']);
        $second = $this->issuedCookieValue();
        $this->assertNotSame($first, $second, 'token must rotate on use');
        $this->assertSame(0, $this->conn->table('account_tokens')->where('selector', $selector)->countAllResults());
        $this->assertSame(1, $this->conn->table('account_tokens')->where('purpose', 'remember')->countAllResults());

        // Старый (уже использованный) validator больше не пускает.
        $this->mockSession();
        $this->useCookie($first);
        $this->assertNull($this->sessionService()->current());
        $this->assertTrue($this->responseCookie()?->isExpired() ?? false, 'cookie of a rejected token is expired');
    }

    public function testStolenValidatorIsRejectedAndTokenDeleted(): void
    {
        $accountId = (new AccountService($this->conn))->createAccount('web');
        $this->sessionService()->login($accountId, true);
        [$selector] = explode(':', $this->issuedCookieValue(), 2);

        $this->mockSession();
        $this->useCookie($selector . ':' . str_repeat('a', 64));

        $this->assertNull($this->sessionService()->current());
        $this->assertSame(0, $this->conn->table('account_tokens')->where('selector', $selector)->countAllResults());
        $this->assertTrue($this->responseCookie()?->isExpired() ?? false);
    }

    public function testExpiredTokenIsRejected(): void
    {
        $accountId = (new AccountService($this->conn))->createAccount('web');
        $this->sessionService()->login($accountId, true);
        $value = $this->issuedCookieValue();
        $this->conn->table('account_tokens')->update(['expires_at' => date('Y-m-d H:i:s', time() - 60)]);

        $this->mockSession();
        $this->useCookie($value);

        $this->assertNull($this->sessionService()->current());
        $this->assertSame(0, $this->conn->table('account_tokens')->countAllResults());
    }

    public function testLogoutClearsSessionDeletesTokenAndExpiresCookie(): void
    {
        $tgUser    = $this->insertTelegramUser(900002004);
        $this->insertCharacter($tgUser);
        $accountId = (new AccountService($this->conn))->ensureForTelegram($tgUser);
        $this->sessionService()->login($accountId, true);
        $this->useCookie($this->issuedCookieValue());

        $this->sessionService()->logout();

        $session = Services::session();
        foreach (['account_id', 'character_id', 'tg_user_id'] as $key) {
            $this->assertNull($session->get($key), "{$key} survived logout");
        }
        $this->assertSame(0, $this->conn->table('account_tokens')->countAllResults());
        $this->assertTrue($this->responseCookie()?->isExpired() ?? false);

        $this->mockSession();
        $this->useCookie(null);
        $this->assertNull($this->sessionService()->current());
    }

    private function sessionService(): AccountSession
    {
        return new AccountSession(new AccountService($this->conn), $this->conn);
    }

    /** Новый запрос с данной remember-cookie (или без неё) и чистый ответ. */
    private function useCookie(?string $value): void
    {
        $request = Services::incomingrequest(null, false);
        $request->setGlobal('cookie', $value === null ? [] : ['ww_remember' => $value]);
        Services::injectMock('request', $request);
        Services::resetSingle('response');
    }

    private function responseCookie(): ?Cookie
    {
        $response = Services::response();
        if (! $response->hasCookie('ww_remember')) {
            return null;
        }
        $cookie = $response->getCookie('ww_remember');

        return $cookie instanceof Cookie ? $cookie : null;
    }

    private function issuedCookieValue(): string
    {
        $cookie = $this->responseCookie();
        $this->assertNotNull($cookie, 'remember cookie was not issued');
        $this->assertFalse($cookie->isExpired());
        $this->assertTrue($cookie->isHTTPOnly());
        $this->assertSame('Lax', $cookie->getSameSite());

        return $cookie->getValue();
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
            'name'             => 'accsess_test_' . bin2hex(random_bytes(3)),
            'created_at'       => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->conn->insertID();
    }

    private function accountOfCharacter(int $characterId): ?int
    {
        $row = $this->conn->table('characters')->select('account_id')->where('id', $characterId)->get()->getRowArray();
        $v   = $row['account_id'] ?? null;

        return is_numeric($v) ? (int) $v : null;
    }

    private function accountField(int $accountId, string $field): mixed
    {
        $row = $this->conn->table('accounts')->where('id', $accountId)->get()->getRowArray();

        return is_array($row) ? ($row[$field] ?? null) : null;
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
        foreach (['characters', 'telegram_users'] as $table) {
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
            $this->conn->table('characters')->like('name', 'accsess_test_', 'after')->delete();
        }
        if ($this->conn->tableExists('telegram_users')) {
            $this->conn->table('telegram_users')->where('telegram_id >=', 900002000)->where('telegram_id <', 900003000)->delete();
        }
    }

    private static function requireMigrationClasses(): void
    {
        $classes = [
            CreateTelegramUsersTable::class => '2024-03-20-153728_CreateTelegramUsersTable.php',
            CreateCharactersTable::class    => '2024-03-20-154155_CreateCharactersTable.php',
            CreateAccountsTables::class     => '2026-12-10-100001_CreateAccountsTables.php',
            LinkCharactersToAccounts::class => '2026-12-10-100002_LinkCharactersToAccounts.php',
        ];
        foreach ($classes as $class => $file) {
            if (! class_exists($class, false)) {
                require_once APPPATH . 'Database/Migrations/' . $file;
            }
        }
    }
}
