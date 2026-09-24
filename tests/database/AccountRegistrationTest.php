<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Controllers\AccountRegister;
use App\Database\Migrations\CreateAccountsTables;
use App\Database\Migrations\CreateBiomesTable;
use App\Database\Migrations\CreateCharactersTable;
use App\Database\Migrations\CreateMapTable;
use App\Database\Migrations\CreateSiteCategoriesTable;
use App\Database\Migrations\CreateTelegramUsersTable;
use App\Database\Migrations\LinkCharactersToAccounts;
use App\Services\Player\NameService;
use App\Services\Web\AccountAuthService;
use CodeIgniter\Config\Factories;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Accounts;
use Config\Database;
use Config\Email;
use Config\Filters;
use Config\Services;

/**
 * web-accounts-p0-08 (ADR-188) — регистрация по email и персонаж без Telegram за флагом
 * `web.open_registration`, правило имени из NameService, один персонаж на аккаунт, лимит на POST,
 * честный отказ почты на странице сброса, состояние входа в шапке.
 *
 * Схема — исполнением настоящих миграций. Глобальный CSRF снят на время теста (он проверен в
 * AccountAuthTest), `accountThrottle` из Routes остаётся в силе. Флаг ставится в кэш
 * GameSettingsService (mock cache) — таблица game_settings не нужна.
 *
 * @internal
 */
final class AccountRegistrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = false;

    private const FLAG_CACHE_KEY = 'game_settings_web_open_registration';

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
            $this->deleteTestRows();
            $bases = [
                'telegram_users'  => CreateTelegramUsersTable::class,
                'characters'      => CreateCharactersTable::class,
                'site_categories' => CreateSiteCategoriesTable::class,
                'biomes'          => CreateBiomesTable::class,
                'map'             => CreateMapTable::class,
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

        $this->mockSession();
        $this->mockCache();
        Services::resetSingle('throttler');
        service('superglobals')->unsetCookie('ci_session');
    }

    protected function tearDown(): void
    {
        config(Filters::class)->globals = $this->filtersBackup;
        service('superglobals')->unsetCookie('ci_session');
        $this->cleanup();
        Factories::reset('config');
        Services::resetSingle('throttler');
        Services::resetSingle('cache');
        parent::tearDown();
    }

    public function testFlagOffPagesExplainClosedBetaAndCreateNothing(): void
    {
        foreach (['account/register', 'account/character'] as $path) {
            $body = $this->bodyOf($this->get($path));
            $this->assertStringContainsString('Закрытая бета', $body, $path);
            $this->assertStringContainsString('/web', $body, $path);
            $this->assertStringNotContainsString('name="password"', $body, $path);
            $this->assertStringNotContainsString('name="name"', $body, $path);
        }

        $body = $this->bodyOf($this->post('account/register', ['email' => 'closed@example.com', 'password' => 'longenough']));
        $this->assertStringContainsString('Закрытая бета', $body);
        $this->assertSame(0, $this->conn->table('accounts')->countAllResults());

        $accountId = $this->registered('closed2@example.com');
        $body      = $this->bodyOf($this->withSession(['account_id' => $accountId])->post('account/character', ['name' => 'Closed_Hero']));
        $this->assertStringContainsString('Закрытая бета', $body);
        $this->assertSame(0, $this->conn->table('characters')->where('account_id', $accountId)->countAllResults());
    }

    public function testFlagOnRegistersEmailAccountThenCreatesWebCharacter(): void
    {
        $this->openRegistration();

        $body = $this->bodyOf($this->get('account/register'));
        $this->assertStringContainsString('name="password"', $body);

        $result = $this->post('account/register', ['email' => ' Web.Player@Example.com', 'password' => 'correct horse']);
        $result->assertRedirectTo('/account/character');

        $account = $this->conn->table('accounts')->get()->getRowArray();
        $this->assertIsArray($account);
        $accountId = (int) $account['id'];
        $this->assertSame('web', $account['acquisition_source']);
        $this->assertSame($accountId, Services::session()->get('account_id'));

        $identity = $this->conn->table('account_identities')->where('account_id', $accountId)->get()->getRowArray();
        $this->assertIsArray($identity);
        $this->assertSame('email', $identity['provider']);
        $this->assertSame('web.player@example.com', $identity['subject']);
        $this->assertNotSame('correct horse', $identity['secret_hash']);
        $this->assertSame(PASSWORD_DEFAULT, password_get_info((string) $identity['secret_hash'])['algo'], 'stored as password_hash() output');
        $this->assertTrue(password_verify('correct horse', (string) $identity['secret_hash']));

        $body = $this->bodyOf($this->withSession(['account_id' => $accountId])->get('account/character'));
        $this->assertStringContainsString('name="name"', $body);

        $result = $this->withSession(['account_id' => $accountId])->post('account/character', ['name' => 'Web_Hero']);
        $result->assertRedirectTo('/account');

        $chars = $this->conn->table('characters')->where('account_id', $accountId)->get()->getResultArray();
        $this->assertCount(1, $chars);
        $this->assertSame('Web_Hero', $chars[0]['name']);
        $this->assertNull($chars[0]['telegram_user_id']);
        $this->assertSame((int) $chars[0]['id'], Services::session()->get('character_id'));
    }

    public function testSecondCharacterIsRefusedAndBadNameGetsBotRuleMessage(): void
    {
        $this->openRegistration();
        $accountId = $this->registered('one@example.com');

        $body = $this->bodyOf($this->withSession(['account_id' => $accountId])->post('account/character', ['name' => 'bad name!']));
        $this->assertStringContainsString(esc(NameService::ruleMessagePlain()), $body);
        $this->assertSame(0, $this->conn->table('characters')->where('account_id', $accountId)->countAllResults());

        // Та же правда, что у /name в боте: сообщение бота — RULE_MESSAGE, сайт — его текст без Markdown.
        $bot = (new NameService())->applyName(['id' => 0], 'bad name!');
        $this->assertFalse($bot['ok']);
        $this->assertSame(NameService::RULE_MESSAGE, $bot['text']);
        $this->assertSame('Имя не соответствует правилам: 3–20 символов, буквы любого языка, цифры и «_». Без пробелов, эмодзи и спецсимволов.', NameService::ruleMessagePlain());
        $this->assertFalse(NameService::isValidName('ab'));
        $this->assertTrue(NameService::isValidName('Путник_7'));

        $this->withSession(['account_id' => $accountId])->post('account/character', ['name' => 'First_One'])->assertRedirectTo('/account');
        $this->withSession(['account_id' => $accountId])->post('account/character', ['name' => 'Second_One'])->assertRedirectTo('/account');
        $this->withSession(['account_id' => $accountId])->get('account/character')->assertRedirectTo('/account');

        $names = array_column($this->conn->table('characters')->where('account_id', $accountId)->get()->getResultArray(), 'name');
        $this->assertSame(['First_One'], $names);
    }

    public function testShortPasswordIsRefused(): void
    {
        $this->openRegistration();
        $min  = (new Accounts())->passwordMinLength;
        $body = $this->bodyOf($this->post('account/register', ['email' => 'short@example.com', 'password' => str_repeat('x', $min - 1)]));

        $this->assertStringContainsString("не меньше {$min} символов", $body);
        $this->assertSame(0, $this->conn->table('accounts')->countAllResults());
    }

    public function testRegisterAndResetPostsGoThroughAccountThrottle(): void
    {
        $limit = (new Accounts())->throttleIpPerMinute;
        foreach (['account/register', 'account/reset'] as $path) {
            Services::resetSingle('throttler');
            $this->mockCache();
            for ($i = 0; $i < $limit; $i++) {
                $status = $this->post($path, ['email' => "t{$i}@example.com"])->response()->getStatusCode();
                $this->assertNotSame(429, $status, "{$path} attempt {$i} blocked too early");
            }
            $this->assertSame(429, $this->post($path, ['email' => 'late@example.com'])->response()->getStatusCode(), $path);
        }
    }

    public function testResetPageShowsHonestMailFailureWithBotCodeAlternative(): void
    {
        // Story 10: отказ почты не отличается от «неизвестной почты» — страница одна, и в ней
        // всегда есть честная оговорка и вход кодом из бота.
        $this->registered('reset.fail@example.com');
        $email            = config(Email::class);
        $email->fromEmail = ''; // транспорт не настроен → штатный мейлер отвечает «не отправлено»

        $failed = $this->bodyOf($this->post('account/reset', ['email' => 'reset.fail@example.com']));
        $this->assertStringContainsString('Наша почта иногда не доходит', $failed);
        $this->assertStringContainsString(esc(base_url('account/link'), 'attr'), $failed);
        $this->assertStringContainsString('/web', $failed);
        $this->assertStringNotContainsString('reset.fail@example.com', $failed);
        $this->assertSame(0, $this->conn->table('account_tokens')->where('purpose', 'password_reset')->countAllResults());

        $unknown = $this->bodyOf($this->post('account/reset', ['email' => 'nobody@example.com']));
        // Без CSRF-токена и счётчиков DEBUG-VIEW (они есть только вне прода).
        $same = static fn (string $b): string => (string) preg_replace(
            ['~<input[^>]*csrf[^>]*>~i', '~<!-- DEBUG-VIEW (START|ENDED) \d+ ~'],
            ['', '<!-- DEBUG-VIEW $1 '],
            $b
        );
        $this->assertSame(
            $same($failed),
            $same($unknown),
            'unknown email gets the very same page as a known one whose mail failed'
        );
    }

    public function testHeaderShowsLoginOrCharacterName(): void
    {
        $body = $this->bodyOf($this->get('account/reset'));
        $this->assertStringContainsString('href="' . esc(base_url('account/login'), 'attr') . '" class="is-active" data-auth-state="out">Войти</a>', $body);

        $this->openRegistration();
        $accountId = $this->registered('header@example.com');
        $this->withSession(['account_id' => $accountId])->post('account/character', ['name' => 'Header_Hero']);

        service('superglobals')->setCookie('ci_session', 'test-session'); // браузер с cookie сессии
        $body = $this->bodyOf($this->withSession(['account_id' => $accountId])->get('account/reset'));
        $this->assertStringContainsString('href="' . esc(base_url('account'), 'attr') . '" class="is-active" data-auth-state="in">Header_Hero</a>', $body);
    }

    private function openRegistration(): void
    {
        $cache = service('cache');
        $this->assertIsObject($cache);
        $cache->save(self::FLAG_CACHE_KEY, ['v' => true, 't' => 'bool'], 60);
        $this->assertTrue(AccountRegister::registrationOpen());
    }

    private function registered(string $email): int
    {
        $id = (new AccountAuthService(null, $this->conn))->registerWithEmail($email, 'longenough');
        $this->assertIsInt($id);

        return $id;
    }

    private function bodyOf(\CodeIgniter\Test\TestResponse $result): string
    {
        return (string) $result->response()->getBody();
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
        foreach (['map', 'biomes', 'site_categories', 'characters', 'telegram_users'] as $table) {
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
            $this->conn->table('characters')
                ->whereIn('name', ['Web_Hero', 'First_One', 'Second_One', 'Header_Hero', 'Closed_Hero'])
                ->delete();
        }
    }

    private static function requireMigrationClasses(): void
    {
        $classes = [
            CreateTelegramUsersTable::class  => '2024-03-20-153728_CreateTelegramUsersTable.php',
            CreateCharactersTable::class     => '2024-03-20-154155_CreateCharactersTable.php',
            CreateSiteCategoriesTable::class => '2026-05-25-180000_CreateSiteCategoriesTable.php',
            CreateBiomesTable::class         => '2024-03-17-222643_CreateBiomesTable.php',
            CreateMapTable::class            => '2024-03-18-105708_CreateMapTable.php',
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
