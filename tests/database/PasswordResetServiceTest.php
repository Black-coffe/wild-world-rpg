<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Database\Migrations\CreateAccountsTables;
use App\Database\Migrations\CreateCharactersTable;
use App\Database\Migrations\CreateTelegramUsersTable;
use App\Database\Migrations\LinkCharactersToAccounts;
use App\Services\Web\AccountAuthService;
use App\Services\Web\PasswordResetService;
use Closure;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Accounts;
use Config\Database;
use RuntimeException;

/**
 * web-accounts-p0-08 (ADR-188) — сброс пароля: токен одноразовый и истекает, неизвестная почта
 * получает тот же `sent`, отказ транспорта (мейлер-двойник) даёт `mail_failed` без живого токена,
 * в БД только sha256(validator), новый пароль — `password_hash()`.
 *
 * @internal
 */
final class PasswordResetServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private BaseConnection $conn;

    private ?Forge $forgeInstance = null;

    /** @var array<string, bool> */
    private array $created = [];

    /** @var list<array{to:string, subject:string, body:string}> */
    private array $outbox = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = Database::connect();
        $files = [
            CreateTelegramUsersTable::class => '2024-03-20-153728_CreateTelegramUsersTable.php',
            CreateCharactersTable::class    => '2024-03-20-154155_CreateCharactersTable.php',
            CreateAccountsTables::class     => '2026-12-10-100001_CreateAccountsTables.php',
            LinkCharactersToAccounts::class => '2026-12-10-100002_LinkCharactersToAccounts.php',
        ];
        foreach ($files as $class => $file) {
            if (! class_exists($class, false)) {
                require_once APPPATH . 'Database/Migrations/' . $file;
            }
        }
        $forge               = Database::forge();
        $this->forgeInstance = $forge instanceof Forge ? $forge : null;

        $this->conn->resetDataCache();
        $this->dropAccounts();
        // account_link_codes ссылается на characters — базовые таблицы нужны, свои потом сносим.
        foreach (['telegram_users' => CreateTelegramUsersTable::class, 'characters' => CreateCharactersTable::class] as $table => $class) {
            $this->created[$table] = ! $this->conn->tableExists($table);
            if ($this->created[$table]) {
                (new $class($this->forgeInstance))->up();
            }
        }
        (new CreateAccountsTables($this->forgeInstance))->up();
        $this->outbox = [];
    }

    protected function tearDown(): void
    {
        $this->dropAccounts();
        foreach (['characters', 'telegram_users'] as $table) {
            if (($this->created[$table] ?? false) === true) {
                $this->forgeInstance?->dropTable($table, true);
            }
        }
        $this->created = [];
        $this->conn->resetDataCache();
        parent::tearDown();
    }

    public function testLinkResetsPasswordOnceAndStoresOnlyHashes(): void
    {
        $accountId = $this->register('Reset.Me@example.com', 'old password');
        $this->conn->table('account_tokens')->insert([
            'account_id' => $accountId, 'purpose' => 'remember', 'selector' => str_repeat('a', 24),
            'validator_hash' => str_repeat('b', 64), 'expires_at' => date('Y-m-d H:i:s', time() + 3600),
        ]);

        $this->assertSame(PasswordResetService::SENT, $this->service($this->okMailer())->request(' reset.me@EXAMPLE.com '));
        $this->assertCount(1, $this->outbox);
        $this->assertSame('Reset.Me@example.com', $this->outbox[0]['to']);
        [$selector, $validator] = $this->tokenFromMail();

        $row = $this->conn->table('account_tokens')->where('purpose', 'password_reset')->get()->getRowArray();
        $this->assertIsArray($row);
        $this->assertSame($selector, $row['selector']);
        $this->assertSame(hash('sha256', $validator), $row['validator_hash'], 'only sha256(validator) is stored');
        $ttl = (new Accounts())->passwordResetTtlSeconds;
        $this->assertEqualsWithDelta(time() + $ttl, strtotime((string) $row['expires_at']), 5);

        $svc = $this->service($this->okMailer());
        $this->assertFalse($svc->complete($selector, $validator, 'short'), 'short password refused');
        $this->assertSame(1, $this->conn->table('account_tokens')->where('purpose', 'password_reset')->countAllResults(), 'refused password does not spend the token');

        $this->assertTrue($svc->complete($selector, $validator, 'new password'));
        $auth = new AccountAuthService(null, $this->conn);
        $this->assertSame($accountId, $auth->verifyPassword('reset.me@example.com', 'new password'));
        $this->assertNull($auth->verifyPassword('reset.me@example.com', 'old password'));
        $hash = (string) $this->conn->table('account_identities')->where('account_id', $accountId)->get()->getRow('secret_hash');
        $this->assertSame(PASSWORD_DEFAULT, password_get_info($hash)['algo']);
        $this->assertSame(0, $this->conn->table('account_tokens')->where('purpose', 'remember')->countAllResults(), 'remembered devices are logged out');

        $this->assertFalse($svc->complete($selector, $validator, 'another password'), 'single use');
        $this->assertSame($accountId, $auth->verifyPassword('reset.me@example.com', 'new password'));
    }

    public function testExpiredOrWrongTokenIsRefused(): void
    {
        $accountId = $this->register('expire@example.com', 'old password');
        $svc       = $this->service($this->okMailer());

        $svc->request('expire@example.com');
        [$selector, $validator] = $this->tokenFromMail();
        $this->assertFalse($svc->complete($selector, str_repeat('0', 64), 'new password'), 'wrong validator');
        $this->assertFalse($svc->complete($selector, $validator, 'new password'), 'a wrong guess burns the token');

        $svc->request('expire@example.com');
        [$selector, $validator] = $this->tokenFromMail();
        $this->conn->table('account_tokens')->where('selector', $selector)->update(['expires_at' => date('Y-m-d H:i:s', time() - 1)]);
        $this->assertFalse($svc->complete($selector, $validator, 'new password'), 'expired');

        $this->assertSame($accountId, (new AccountAuthService(null, $this->conn))->verifyPassword('expire@example.com', 'old password'));
    }

    public function testNewRequestInvalidatesEarlierLink(): void
    {
        $this->register('twice@example.com', 'old password');
        $svc = $this->service($this->okMailer());
        $svc->request('twice@example.com');
        [$firstSelector, $firstValidator] = $this->tokenFromMail();
        $svc->request('twice@example.com');

        $this->assertFalse($svc->complete($firstSelector, $firstValidator, 'new password'));
        $this->assertSame(1, $this->conn->table('account_tokens')->where('purpose', 'password_reset')->countAllResults());
    }

    public function testUnknownEmailGetsSameSentAnswerWithoutMail(): void
    {
        $this->register('known@example.com', 'old password');

        $this->assertSame(PasswordResetService::SENT, $this->service($this->okMailer())->request('unknown@example.com'));
        $this->assertSame([], $this->outbox);
        $this->assertSame(0, $this->conn->table('account_tokens')->countAllResults());
    }

    public function testTransportFailureReportsMailFailedAndLeavesNoToken(): void
    {
        $this->register('fail@example.com', 'old password');

        $failing = static fn (string $to, string $subject, string $body): bool => false;
        $this->assertSame(PasswordResetService::MAIL_FAILED, $this->service($failing)->request('fail@example.com'));
        $this->assertSame(0, $this->conn->table('account_tokens')->countAllResults());

        $throwing = static function (string $to, string $subject, string $body): bool {
            throw new RuntimeException('SMTP connect failed');
        };
        $this->assertSame(PasswordResetService::MAIL_FAILED, $this->service($throwing)->request('fail@example.com'));
        $this->assertSame(0, $this->conn->table('account_tokens')->countAllResults());
    }

    /**
     * @param Closure(string, string, string): bool $mailer
     */
    private function service(Closure $mailer): PasswordResetService
    {
        return new PasswordResetService($this->conn, null, $mailer);
    }

    /**
     * @return Closure(string, string, string): bool
     */
    private function okMailer(): Closure
    {
        return function (string $to, string $subject, string $body): bool {
            $this->outbox[] = ['to' => $to, 'subject' => $subject, 'body' => $body];

            return true;
        };
    }

    /**
     * @return array{0:string, 1:string}
     */
    private function tokenFromMail(): array
    {
        $last = end($this->outbox);
        $this->assertIsArray($last);
        $this->assertSame(1, preg_match('~/account/reset/([a-f0-9]{24})-([a-f0-9]{64})~', $last['body'], $m), 'mail carries the reset link');

        return [$m[1], $m[2]];
    }

    private function register(string $email, string $password): int
    {
        $id = (new AccountAuthService(null, $this->conn))->registerWithEmail($email, $password);
        $this->assertIsInt($id);

        return $id;
    }

    private function dropAccounts(): void
    {
        $this->conn->resetDataCache();
        if ($this->conn->tableExists('characters') && $this->conn->tableExists('telegram_users')) {
            // Оставленный другим тестом FK characters.account_id → accounts иначе не даст снести accounts.
            (new LinkCharactersToAccounts($this->forgeInstance))->down();
        }
        (new CreateAccountsTables($this->forgeInstance))->down();
        $this->conn->resetDataCache();
    }
}
