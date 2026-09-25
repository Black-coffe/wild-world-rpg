<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Database\Migrations\CreateAccountsTables;
use App\Database\Migrations\CreateCharactersTable;
use App\Database\Migrations\CreateSiteCategoriesTable;
use App\Database\Migrations\CreateTelegramUsersTable;
use App\Database\Migrations\LinkCharactersToAccounts;
use App\Services\Web\AccountAuthService;
use App\Services\Web\AccountSession;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Database;
use Config\Services;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * web-bridge-p1-17 — `/account/link` говорит правду под F1: код у вошедшего в другой аккаунт
 * отказывает до траты (LinkCodeService::MSG_OTHER), поэтому вошедший видит «выйди из другого
 * входа и введи код» и рабочий выход, а не обещание «код привяжет вход». Гость — прежний смысл:
 * код впускает в аккаунт персонажа. Проверка по отрендеренной странице, не по исходнику вьюхи.
 *
 * @internal
 */
final class AccountLinkPageTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

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

        $this->mockSession();
        $this->mockCache();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        service('superglobals')->unsetCookie('csrf_cookie_name');
        Services::resetSingle('cache');
        Services::resetSingle('security');
        parent::tearDown();
    }

    public function testLoggedInPageSaysLogOutThenEnterCodeAndNeverPromisesLinking(): void
    {
        $accountId = $this->emailAccount('linkpage@example.com');

        $body = $this->body($this->withSession(['account_id' => $accountId])->get('account/link'));
        $text = self::text($body);

        $this->assertStringContainsString('выйди из другого входа и введи код', $text);
        $this->assertStringNotContainsString('привяж', $text, 'no text says the code links the current login');
        $this->assertStringNotContainsString('Ты вошёл на сайте в другой аккаунт', $text, 'no refusal before any code is entered');

        $logout = self::formsTo($body, base_url('account/logout'));
        $this->assertCount(1, $logout, 'one logout form');
        $this->assertNotNull($logout[0]->ownerDocument);
        $xp = new DOMXPath($logout[0]->ownerDocument);
        $this->assertSame(1.0, $xp->evaluate('count(.//input[@name="' . csrf_token() . '"])', $logout[0]), 'logout carries CSRF');
        $this->assertSame('Выйти, чтобы ввести код', trim((string) $xp->evaluate('string(.//button)', $logout[0])));
    }

    public function testLogoutFromLinkPageEndsTheSessionSoTheCodeCanBeEntered(): void
    {
        $accountId = $this->emailAccount('linkout@example.com');
        $hash      = bin2hex(random_bytes(16));
        service('superglobals')->setCookie('csrf_cookie_name', $hash);
        Services::resetSingle('security');

        $result = $this->withSession(['account_id' => $accountId])->post('account/logout', ['csrf_test_name' => $hash]);

        $result->assertRedirectTo('/account/login?auth=logged_out');
        $this->assertNull((new AccountSession())->accountId());
    }

    public function testLoggedOutPageSaysTheCodeLetsYouIntoTheCharacterAccount(): void
    {
        $body = $this->body($this->get('account/link'));
        $text = self::text($body);

        $this->assertStringContainsString('Не вошёл на сайте — код впустит тебя в аккаунт персонажа.', $text);
        $this->assertStringNotContainsString('привяж', $text);
        $this->assertStringNotContainsString('Ты уже вошёл на сайте', $text);
        $this->assertSame([], self::formsTo($body, base_url('account/logout')), 'no logout form for a guest');
        $this->assertCount(1, self::formsTo($body, base_url('account/link')), 'code form stays');
    }

    // --- helpers -------------------------------------------------------------------------

    private function emailAccount(string $email): int
    {
        $id = (new AccountAuthService(null, $this->conn))->registerWithEmail($email, 'longenough');
        $this->assertIsInt($id);

        return $id;
    }

    private function body(TestResponse $result): string
    {
        $result->assertOK();

        return (string) $result->response()->getBody();
    }

    private static function text(string $html): string
    {
        $main = self::xpath($html)->evaluate('string(//main)');

        return (string) preg_replace('/\s+/u', ' ', html_entity_decode(is_string($main) ? $main : '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private static function xpath(string $html): DOMXPath
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        return new DOMXPath($doc);
    }

    /**
     * @return list<DOMElement> формы внутри <main> с данным action
     */
    private static function formsTo(string $html, string $action): array
    {
        $out = [];
        foreach (self::xpath($html)->query('//main//form') ?: [] as $form) {
            if ($form instanceof DOMElement && $form->getAttribute('action') === $action) {
                $out[] = $form;
            }
        }

        return $out;
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
