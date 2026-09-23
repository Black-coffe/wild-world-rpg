<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Controllers\Telegram\Commands\Actions\SettingsAction;
use App\Controllers\Telegram\Commands\Actions\WebLinkCodeAction;
use App\Database\Migrations\CreateAccountsTables;
use App\Database\Migrations\CreateCharactersTable;
use App\Database\Migrations\CreateSiteCategoriesTable;
use App\Database\Migrations\CreateTelegramUsersTable;
use App\Database\Migrations\LinkCharactersToAccounts;
use App\Services\Telegram\BotMenuService;
use App\Services\Web\AccountService;
use App\Services\Web\LinkCodeService;
use CodeIgniter\Config\Factories;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Accounts;
use Config\CallbackRoutes;
use Config\Database;
use Config\Filters;
use Config\Services;

/**
 * web-accounts-p0-06 (ADR-188) — одноразовый код из бота: хранится только sha256, срабатывает
 * один раз (повтор, просрочка, старый код после нового — отказ с понятной причиной), атомарное
 * погашение, правило A2 (вход / слияние / отказ без траты кода), страница /account/link,
 * сообщение бота и вход с экрана настроек.
 *
 * Схема — исполнением настоящих миграций. Глобальный CSRF снят на время теста (он проверен в
 * AccountAuthTest), `accountThrottle` из Routes остаётся в силе.
 *
 * @internal
 */
final class LinkCodeServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = false;

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

    // --- Ask 3 / Ask 15: хранение и одноразовость ----------------------------------------------

    public function testIssueStoresOnlySha256AndUsesSafeAlphabet(): void
    {
        $charId = $this->insertCharacter($this->insertTelegramUser(900005001));
        $issued = $this->service()->issue($charId);
        $config = new Accounts();

        $this->assertSame($config->linkCodeLength, strlen($issued['code']));
        $this->assertMatchesRegularExpression('/^[ABCDEFGHJKMNPQRSTUVWXYZ2-9]+$/', $issued['code']);
        $this->assertSame(intdiv($config->linkCodeTtlSeconds + 59, 60), $issued['ttl_minutes']);

        $rows = $this->conn->table('account_link_codes')->where('character_id', $charId)->get()->getResultArray();
        $this->assertCount(1, $rows);
        $this->assertSame(hash('sha256', $issued['code']), $rows[0]['code_hash']);
        foreach ($rows[0] as $value) {
            $this->assertStringNotContainsString($issued['code'], (string) $value, 'plaintext code is never stored');
        }
    }

    public function testCodeWorksOnceAndSecondRedeemFailsWithReason(): void
    {
        $tgUser = $this->insertTelegramUser(900005002);
        $charId = $this->insertCharacter($tgUser);
        $code   = $this->service()->issue($charId)['code'];

        $claimed = $this->service()->redeem(strtolower(substr($code, 0, 4) . '-' . substr($code, 4)));
        $this->assertNotNull($claimed, 'normalized code (lowercase, dash) is accepted');
        $this->assertSame($charId, $claimed['character_id']);
        $this->assertSame((new AccountService($this->conn))->ensureForCharacter($charId), $claimed['account_id']);

        $this->assertNull($this->service()->redeem($code), 'second redeem fails (affected_rows = 0)');
        $again = $this->service()->link($code, null);
        $this->assertSame(LinkCodeService::STATUS_INVALID, $again['status']);
        $this->assertSame(LinkCodeService::MSG_USED, $again['message']);
    }

    public function testExpiredCodeFailsWithReason(): void
    {
        $charId = $this->insertCharacter($this->insertTelegramUser(900005003));
        $code   = $this->service()->issue($charId)['code'];
        $this->conn->query(
            'UPDATE account_link_codes SET expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE code_hash = ?',
            [hash('sha256', $code)]
        );

        $this->assertNull($this->service()->redeem($code));
        $result = $this->service()->link($code, null);
        $this->assertSame(LinkCodeService::STATUS_INVALID, $result['status']);
        $this->assertSame(LinkCodeService::MSG_EXPIRED, $result['message']);
    }

    public function testOlderCodeFailsAfterNewerIsIssued(): void
    {
        $charId = $this->insertCharacter($this->insertTelegramUser(900005004));
        $older  = $this->service()->issue($charId)['code'];
        $newer  = $this->service()->issue($charId)['code'];

        $result = $this->service()->link($older, null);
        $this->assertSame(LinkCodeService::STATUS_INVALID, $result['status']);
        $this->assertSame(LinkCodeService::MSG_NOT_FOUND, $result['message']);
        $this->assertNotNull($this->service()->redeem($newer), 'the newest code still works');
    }

    public function testUnknownCodeFailsWithReason(): void
    {
        $result = $this->service()->link('ZZZZ2222', null);

        $this->assertSame(LinkCodeService::STATUS_INVALID, $result['status']);
        $this->assertSame(LinkCodeService::MSG_NOT_FOUND, $result['message']);
    }

    // --- Ask 3: правило A2 ----------------------------------------------------------------------

    public function testLoggedOutRedeemLogsIntoCharacterAccountAndLandsOnAccount(): void
    {
        $tgUser = $this->insertTelegramUser(900005005);
        $charId = $this->insertCharacter($tgUser);
        $code   = $this->service()->issue($charId)['code'];

        $result = $this->post('account/link', ['code' => $code]);

        $result->assertRedirectTo('/account');
        $accountId = (new AccountService($this->conn))->ensureForCharacter($charId);
        $this->assertSame($accountId, Services::session()->get('account_id'));
        $this->assertSame($charId, Services::session()->get('character_id'));
        $this->assertNull($this->service()->redeem($code), 'the code is spent');
    }

    public function testLoggedInAccountWithoutCharacterIsMergedIntoCharacterAccount(): void
    {
        $accounts = new AccountService($this->conn);
        $charId   = $this->insertCharacter($this->insertTelegramUser(900005006));
        $target   = $accounts->ensureForCharacter($charId);
        $webOnly  = $accounts->createAccount('web');
        $this->assertTrue($accounts->addIdentity($webOnly, 'email', 'lnkcode-merge@example.com', password_hash('x', PASSWORD_DEFAULT)));
        $code = $this->service()->issue($charId)['code'];

        $result = $this->service()->link($code, $webOnly);

        $this->assertSame(LinkCodeService::STATUS_MERGED, $result['status']);
        $this->assertSame($target, $result['account_id']);
        $this->assertSame($target, $accounts->findByIdentity('email', 'lnkcode-merge@example.com'));
        $this->assertSame(0, $this->conn->table('accounts')->where('id', $webOnly)->countAllResults(), 'merged account is gone');
    }

    public function testLoggedInAccountWithOtherCharacterIsRefusedAndCodeIsNotSpent(): void
    {
        $accounts = new AccountService($this->conn);
        $charId   = $this->insertCharacter($this->insertTelegramUser(900005007));
        $otherId  = $this->insertCharacter($this->insertTelegramUser(900005008));
        $other    = $accounts->ensureForCharacter($otherId);
        $this->assertNotNull($other);
        $code = $this->service()->issue($charId)['code'];

        $result = $this->withSession(['account_id' => $other])->post('account/link', ['code' => $code]);

        $this->assertSame(200, $result->response()->getStatusCode());
        $this->assertStringContainsString(esc(LinkCodeService::MSG_OTHER), (string) $result->response()->getBody());
        $this->assertSame($other, Services::session()->get('account_id'), 'session is untouched');
        $row = $this->conn->table('account_link_codes')->where('code_hash', hash('sha256', $code))->get()->getRowArray();
        $this->assertIsArray($row);
        $this->assertNull($row['used_at'], 'refusal does not spend the code');
    }

    // --- Ask 9 / Ask 11: страница, сообщение бота, вход с настроек ------------------------------

    public function testLinkPageRendersFormWithWhereToGetTheCode(): void
    {
        $body = (string) $this->get('account/link')->response()->getBody();

        $this->assertStringContainsString('name="code"', $body);
        $this->assertStringContainsString('/web', $body);
        $this->assertStringContainsString('wildworld-ui.css', $body);
        $this->assertDoesNotMatchRegularExpression('/\sstyle="/', $this->mainOf($body), 'no inline styles, only kit tokens');
    }

    public function testBotMessageNamesCodeSitePathAndLifetimeAndIsMarkdownSafe(): void
    {
        $ttl  = intdiv((new Accounts())->linkCodeTtlSeconds + 59, 60);
        $text = WebLinkCodeAction::codeMessage('ABCD2345', $ttl);

        $this->assertStringContainsString('ABCD2345', $text);
        $this->assertStringContainsString('wildworld.fun/account/link', $text);
        $this->assertStringContainsString("{$ttl} мин.", $text);
        $this->assertStringContainsString('один раз', $text);
        foreach (['*', '_', '`'] as $entity) {
            $this->assertSame(0, substr_count($text, $entity) % 2, "unbalanced «{$entity}»");
        }
    }

    public function testWebIsInCommandMenuAndSettingsScreenHasTheButton(): void
    {
        $this->assertContains('web', array_column(BotMenuService::commandList(), 'command'));
        $this->assertSame(WebLinkCodeAction::class, (new CallbackRoutes())->resolve(WebLinkCodeAction::CALLBACK));

        $screen = SettingsAction::buildScreen(['disable_media' => 0, 'daily_tips_enabled' => 1, 'health_warnings_enabled' => 1]);
        $markup = json_decode((string) $screen['reply_markup'], true);
        $this->assertIsArray($markup);
        $found = null;
        foreach ($markup['inline_keyboard'] as $row) {
            foreach ($row as $button) {
                if (($button['callback_data'] ?? null) === WebLinkCodeAction::CALLBACK) {
                    $found = $row;
                }
            }
        }
        $this->assertNotNull($found, 'settings screen has the web-code button');
        $this->assertGreaterThanOrEqual(2, count($found), 'the button is not alone in its row');
        $this->assertStringContainsString('/web', (string) $screen['text']);
    }

    private function service(): LinkCodeService
    {
        return new LinkCodeService($this->conn, new AccountService($this->conn));
    }

    private function mainOf(string $html): string
    {
        $start = strpos($html, '<section class="block">');
        $end   = $start === false ? false : strpos($html, '</section>', $start);

        return $start === false || $end === false ? $html : substr($html, $start, $end - $start);
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
            'name'             => 'lnkcode_test_' . bin2hex(random_bytes(3)),
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
            $this->conn->table('characters')->like('name', 'lnkcode_test_', 'after')->delete();
        }
        if ($this->conn->tableExists('telegram_users')) {
            $this->conn->table('telegram_users')->where('telegram_id >=', 900005000)->where('telegram_id <', 900006000)->delete();
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
