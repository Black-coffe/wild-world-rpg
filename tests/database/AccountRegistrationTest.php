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
use App\Services\Web\AccountService;
use App\Services\Web\VirtualChat;
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

        $this->assertSame(0, $this->conn->table('accounts')->countAllResults());

        $accountId = $this->registered('closed2@example.com');
        $body      = $this->bodyOf($this->withSession(['account_id' => $accountId])->post('account/character', ['name' => 'Closed_Hero']));
        $this->assertStringContainsString('Закрытая бета', $body);
        $this->assertSame(0, $this->conn->table('characters')->where('account_id', $accountId)->countAllResults());
    }

    public function testFlagOnRegisterPageOffersOAuthAndAccountCreatesWebCharacter(): void
    {
        $this->openRegistration();

        // web-accounts-oauth-only: регистрация — первый вход через Google/Яндекс; формы почты нет.
        $body = $this->bodyOf($this->get('account/register'));
        $this->assertStringNotContainsString('name="password"', $body);
        $this->assertStringContainsString('Google', $body);
        $this->assertStringContainsString('Яндекс', $body);

        $accountId = $this->registered('web.player@example.com');

        $body = $this->bodyOf($this->withSession(['account_id' => $accountId])->get('account/character'));
        $this->assertStringContainsString('name="name"', $body);

        $result = $this->withSession(['account_id' => $accountId])->post('account/character', ['name' => 'Web_Hero']);
        $result->assertRedirectTo('/account');

        $chars = $this->conn->table('characters')->where('account_id', $accountId)->get()->getResultArray();
        $this->assertCount(1, $chars);
        $this->assertSame('Web_Hero', $chars[0]['name']);
        // web-bridge-p1-01 (ADR-189 §3): виртуальная строка telegram_users, но не Telegram-вход.
        $this->assertNotNull($chars[0]['telegram_user_id']);
        $tgRow = $this->conn->table('telegram_users')->where('id', (int) $chars[0]['telegram_user_id'])->get()->getRowArray();
        $this->assertIsArray($tgRow);
        $this->assertSame(VirtualChat::idForAccount($accountId), (int) $tgRow['telegram_id']);
        $this->assertNull(Services::session()->get('tg_user_id'));
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

    public function testCodeLoginPostGoesThroughAccountThrottle(): void
    {
        $limit = (new Accounts())->throttleIpPerMinute;
        foreach (['account/link'] as $path) {
            Services::resetSingle('throttler');
            $this->mockCache();
            for ($i = 0; $i < $limit; $i++) {
                $status = $this->post($path, ['code' => "T{$i}"])->response()->getStatusCode();
                $this->assertNotSame(429, $status, "{$path} attempt {$i} blocked too early");
            }
            $this->assertSame(429, $this->post($path, ['code' => 'LATE'])->response()->getStatusCode(), $path);
        }
    }

    public function testHeaderShowsLoginOrCharacterName(): void
    {
        $body = $this->bodyOf($this->get('account/link'));
        $this->assertStringContainsString('href="' . esc(base_url('account/login'), 'attr') . '" class="is-active" data-auth-state="out">Войти</a>', $body);

        $this->openRegistration();
        $accountId = $this->registered('header@example.com');
        $this->withSession(['account_id' => $accountId])->post('account/character', ['name' => 'Header_Hero']);

        service('superglobals')->setCookie('ci_session', 'test-session'); // браузер с cookie сессии
        $body = $this->bodyOf($this->withSession(['account_id' => $accountId])->get('account/link'));
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
        $accounts = new AccountService($this->conn);
        $id       = $accounts->createAccount('web');
        // web-accounts-oauth-only: фикстура — аккаунт с входом через Яндекс (почты с паролем больше нет).
        $this->assertTrue($accounts->addIdentity($id, 'yandex', 'y-' . md5($email), null, $email));

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
        if ($this->conn->tableExists('telegram_users')) {
            // Виртуальные строки web-персонажей (ADR-189) — только диапазон VirtualChat.
            $this->conn->table('telegram_users')->where('telegram_id <=', -VirtualChat::VIRTUAL_BASE)->delete();
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
