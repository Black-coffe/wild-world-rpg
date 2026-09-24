<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Database\Migrations\CreateAccountsTables;
use App\Database\Migrations\CreateCharactersTable;
use App\Database\Migrations\CreateSiteCategoriesTable;
use App\Database\Migrations\CreateTelegramUsersTable;
use App\Database\Migrations\LinkCharactersToAccounts;
use App\Services\Web\AccountAuthService;
use CodeIgniter\Config\Factories;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\Mock\MockEmail;
use CodeIgniter\Test\TestLogger;
use Config\Database;
use Config\Email;
use Config\Filters;
use Config\Services;
use ReflectionProperty;
use RuntimeException;

/**
 * web-accounts-p0-10 (council round 1, #3) — `POST /account/reset` не оракул: неизвестная почта,
 * известная с ушедшим письмом и известная с отказом транспорта (false или исключение) получают
 * один и тот же ответ (статус и тело без CSRF-токена), и в нём всегда вход кодом из бота. Отказ
 * почты — одна error-строка лога без адреса.
 *
 * Мейлер — штатный `defaultMailer()` сервиса поверх общего `Services::email`, подменённого
 * `injectMock`. Глобальный CSRF снят на время теста (проверен в AccountAuthTest).
 *
 * @internal
 */
final class AccountPasswordResetPageTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = false;

    private const KNOWN = 'Known.Player@example.com';

    private BaseConnection $conn;

    private ?Forge $forgeInstance = null;

    /** @var array<string,bool> */
    private array $created = [];

    /** @var array<string, mixed> */
    private array $filtersBackup = [];

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

        $filters             = config(Filters::class);
        $this->filtersBackup = $filters->globals;
        unset($filters->globals['before']['csrf']);

        config(Email::class)->fromEmail = 'noreply@example.com';

        $this->mockSession();
        $this->mockCache();
        Services::resetSingle('throttler');
        service('superglobals')->unsetCookie('ci_session');

        $id = (new AccountAuthService(null, $this->conn))->registerWithEmail(self::KNOWN, 'longenough');
        $this->assertIsInt($id);
    }

    protected function tearDown(): void
    {
        config(Filters::class)->globals = $this->filtersBackup;
        service('superglobals')->unsetCookie('ci_session');
        $this->cleanup();
        Factories::reset('config');
        Services::resetSingle('throttler');
        Services::resetSingle('cache');
        Services::resetSingle('email');
        parent::tearDown();
    }

    public function testEveryOutcomeGetsTheSamePageWithBotCodeAlternative(): void
    {
        $sentMail = $this->mailer(true);
        $cases    = [
            'unknown email'            => ['nobody@example.com', $sentMail],
            'known email, mail sent'   => [self::KNOWN, $sentMail],
            'known email, mail false'  => [self::KNOWN, $this->mailer(false)],
            'known email, mail throws' => [self::KNOWN, $this->throwingMailer()],
        ];

        $pages = [];
        foreach ($cases as $label => [$address, $mailer]) {
            Services::injectMock('email', $mailer);
            Services::resetSingle('throttler');
            $response = $this->post('account/reset', ['email' => $address])->response();
            $body     = (string) $response->getBody();

            $this->assertStringContainsString('/web', $body, $label);
            $this->assertStringContainsString(esc(base_url('account/link'), 'attr'), $body, $label);
            $this->assertStringNotContainsString('known.player@example.com', strtolower($body), $label);

            $pages[$label] = $response->getStatusCode() . "\n" . self::comparable($body);
        }

        $this->assertSame(1, $sentMail->sends, 'the success double really carried the known-email letter');
        $reference = $pages['unknown email'];
        foreach ($pages as $label => $page) {
            $this->assertSame($reference, $page, "{$label} must be indistinguishable from an unknown email");
        }
    }

    public function testMailFailureWritesOneErrorLineWithoutTheEmail(): void
    {
        foreach (['false' => $this->mailer(false), 'throws' => $this->throwingMailer()] as $label => $mailer) {
            Services::injectMock('email', $mailer);
            Services::resetSingle('throttler');
            $before = count($this->resetErrorLines());

            $this->post('account/reset', ['email' => self::KNOWN]);

            $new = array_slice($this->resetErrorLines(), $before);
            $this->assertCount(1, $new, $label);
            $this->assertStringNotContainsString('known.player@example.com', strtolower($new[0]), $label);
            $this->assertSame(0, $this->conn->table('account_tokens')->where('purpose', 'password_reset')->countAllResults(), $label);
        }

        Services::injectMock('email', $this->mailer(true));
        Services::resetSingle('throttler');
        $before = count($this->resetErrorLines());
        $this->post('account/reset', ['email' => self::KNOWN]);
        $this->assertCount($before, $this->resetErrorLines(), 'a sent letter logs no error');
    }

    /**
     * Тело без того, что меняется от запроса к запросу само по себе: CSRF-токен и счётчики
     * `<!-- DEBUG-VIEW … N … -->` (есть только в testing/development, не на проде).
     */
    private static function comparable(string $body): string
    {
        $body = (string) preg_replace('~<input[^>]*csrf[^>]*>~i', '', $body);

        return (string) preg_replace('~<!-- DEBUG-VIEW (START|ENDED) \d+ ~', '<!-- DEBUG-VIEW $1 ', $body);
    }

    /**
     * @return list<string> error-строки лога сброса пароля, записанные за процесс тестов
     */
    private function resetErrorLines(): array
    {
        $logs = (new ReflectionProperty(TestLogger::class, 'op_logs'))->getValue();
        $out  = [];
        foreach (is_array($logs) ? $logs : [] as $log) {
            if (is_array($log) && ($log['level'] ?? null) === 'error' && is_string($log['message'] ?? null)
                && str_contains($log['message'], '[PasswordReset]')) {
                $out[] = $log['message'];
            }
        }

        return $out;
    }

    private function mailer(bool $result): MockEmail
    {
        return new class (config(Email::class), $result) extends MockEmail {
            public int $sends = 0;

            public function __construct(Email $config, bool $result)
            {
                parent::__construct($config);
                $this->returnValue = $result;
            }

            public function send($autoClear = true)
            {
                $this->sends++;

                return parent::send($autoClear);
            }
        };
    }

    private function throwingMailer(): MockEmail
    {
        return new class (config(Email::class)) extends MockEmail {
            public function send($autoClear = true)
            {
                throw new RuntimeException('SMTP RCPT TO:<known.player@example.com> refused');
            }
        };
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
        foreach (['site_categories', 'characters', 'telegram_users'] as $table) {
            if (($this->created[$table] ?? false) === true) {
                $this->forgeInstance?->dropTable($table, true);
            }
        }
        $this->created = [];
        $this->conn->resetDataCache();
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
