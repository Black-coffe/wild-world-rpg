<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Database\Migrations\CreateAccountsTables;
use App\Database\Migrations\CreateCharactersTable;
use App\Database\Migrations\CreateGameSettingsTable;
use App\Database\Migrations\CreateTelegramUsersTable;
use App\Database\Migrations\LinkCharactersToAccounts;
use App\Database\Migrations\WebOpenRegistrationSetting;
use App\Models\CharacterModel;
use App\Services\Web\AccountService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use RuntimeException;

/**
 * web-accounts-p0-01 (tracer, ADR-188) — аккаунт как корень игрока.
 *
 * Схема строится исполнением настоящих классов миграций (`telegram_users`, `characters`,
 * `game_settings` — только если их нет, и дропаются только созданные этим тестом) плюс три
 * новые миграции спеки. Проверяет: backfill (аккаунт + одна telegram-identity на персонажа,
 * идемпотентно), отказ миграции на дублях, `AccountService` (идемпотентный ensureForTelegram,
 * отказы addIdentity/unlinkIdentity/mergeInto), сид `web.open_registration`.
 *
 * @internal
 */
final class AccountsSchemaTest extends CIUnitTestCase
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
        $this->requireMigrationClasses();
        $forge               = Database::forge();
        $this->forgeInstance = $forge instanceof Forge ? $forge : null;

        try {
            // Хвосты прошлого упавшего прогона — снимаем тем же down().
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
            $this->created['game_settings'] = ! $this->conn->tableExists('game_settings');
            if ($this->created['game_settings']) {
                (new CreateGameSettingsTable($this->forgeInstance))->up();
            }

            (new CreateAccountsTables($this->forgeInstance))->up();
        } catch (\Throwable $e) {
            $this->cleanup();

            throw $e;
        }
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testBackfillGivesEveryTelegramCharacterOneAccountAndIsIdempotent(): void
    {
        $tgA   = $this->insertTelegramUser(900000001);
        $tgB   = $this->insertTelegramUser(900000002);
        $charA = $this->insertCharacter($tgA);
        $charB = $this->insertCharacter($tgB);
        $charW = $this->insertCharacter(null);

        $migration = $this->linkMigration();
        $migration->up();

        foreach ([[$charA, '900000001'], [$charB, '900000002']] as [$charId, $subject]) {
            $accountId = $this->accountOf($charId);
            $this->assertNotNull($accountId, "character {$charId} has no account after backfill");
            $ids = $this->conn->table('account_identities')->where('account_id', $accountId)->get()->getResultArray();
            $this->assertCount(1, $ids);
            $this->assertSame('telegram', $ids[0]['provider']);
            $this->assertSame($subject, $ids[0]['subject']);
        }
        $this->assertNull($this->accountOf($charW), 'character without Telegram must stay without account');
        $this->assertNotSame($this->accountOf($charA), $this->accountOf($charB));

        $accountsBefore   = $this->conn->table('accounts')->countAllResults();
        $identitiesBefore = $this->conn->table('account_identities')->countAllResults();
        $migration->backfill();
        $this->assertSame($accountsBefore, $this->conn->table('accounts')->countAllResults());
        $this->assertSame($identitiesBefore, $this->conn->table('account_identities')->countAllResults());
        $this->assertSame(2, $accountsBefore);
    }

    public function testMigrationRefusesDuplicateTelegramIdsWithOffendingIds(): void
    {
        $first  = $this->insertTelegramUser(900000010);
        $second = $this->insertTelegramUser(900000010);

        try {
            $this->linkMigration()->up();
            $this->fail('migration must throw on duplicate telegram_id');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("{$first},{$second}", $e->getMessage());
        }
        $this->conn->resetDataCache();
        $this->assertFalse($this->conn->fieldExists('account_id', 'characters'), 'nothing may change on abort');
    }

    public function testEnsureForTelegramIsIdempotentAndAttachesCharacter(): void
    {
        $this->linkMigration()->up();
        $tg   = $this->insertTelegramUser(900000020);
        $char = $this->insertCharacter($tg);
        $this->assertNull($this->accountOf($char));

        $svc    = new AccountService($this->conn);
        $first  = $svc->ensureForTelegram($tg);
        $second = $svc->ensureForTelegram($tg);

        $this->assertSame($first, $second);
        $this->assertSame($first, $this->accountOf($char));
        $this->assertSame($first, $svc->findByIdentity('telegram', '900000020'));
        $this->assertCount(1, $svc->identities($first));
        $this->assertSame($first, $svc->ensureForCharacter($char));
        $this->assertSame($char, (int) ($svc->characterForAccount($first)['id'] ?? 0));
    }

    public function testIdentityRulesRefuseTakenLastAndCharacterOwningMerge(): void
    {
        $this->linkMigration()->up();
        $tg   = $this->insertTelegramUser(900000030);
        $char = $this->insertCharacter($tg);
        $svc  = new AccountService($this->conn);

        $withChar = $svc->ensureForTelegram($tg);
        $webOnly  = $svc->createAccount('web');
        $this->assertTrue($svc->addIdentity($webOnly, 'email', 'a@example.test', password_hash('x', PASSWORD_DEFAULT), 'a@example.test'));

        // Taken (provider, subject) — refused, for another account and for the same one.
        $this->assertFalse($svc->addIdentity($withChar, 'email', 'a@example.test'));
        $this->assertFalse($svc->addIdentity($webOnly, 'telegram', '900000030'));

        // Last identity — refused.
        $emailId = (int) $svc->identities($webOnly)[0]['id'];
        $this->assertFalse($svc->unlinkIdentity($webOnly, $emailId));
        $this->assertCount(1, $svc->identities($webOnly));

        // `from` owns a character — refused.
        $this->assertFalse($svc->mergeInto($withChar, $webOnly));

        // Account without character merges into the character's account.
        $this->assertTrue($svc->mergeInto($webOnly, $withChar));
        $this->assertCount(2, $svc->identities($withChar));
        $this->assertSame(0, $this->conn->table('accounts')->where('id', $webOnly)->countAllResults());

        // Now two identities — unlinking one is allowed, the last one again is not.
        $this->assertTrue($svc->unlinkIdentity($withChar, $emailId));
        $tgIdentityId = (int) $svc->identities($withChar)[0]['id'];
        $this->assertFalse($svc->unlinkIdentity($withChar, $tgIdentityId));
        $this->assertSame($withChar, $this->accountOf($char));
    }

    public function testCharacterModelAllowsAccountId(): void
    {
        $this->linkMigration()->up();
        $char    = $this->insertCharacter(null);
        $account = (new AccountService($this->conn))->createAccount('web');

        (new CharacterModel())->update($char, ['account_id' => $account]);

        $this->assertSame($account, $this->accountOf($char));
    }

    public function testOpenRegistrationSeedIsIdempotent(): void
    {
        $seed = new WebOpenRegistrationSetting($this->forgeInstance);
        $seed->up();
        $seed->up();

        $rows = $this->conn->table('game_settings')->where('setting_key', 'web.open_registration')->get()->getResultArray();
        $this->assertCount(1, $rows);
        $this->assertSame('bool', $rows[0]['value_type']);
        $this->assertSame(0, (int) $rows[0]['value_bool']);
        foreach (['rationale_text', 'effect_text', 'above_effect_text', 'below_effect_text'] as $col) {
            $this->assertNotEmpty($rows[0][$col], "{$col} is empty");
        }

        $seed->down();
        $this->assertSame(0, $this->conn->table('game_settings')->where('setting_key', 'web.open_registration')->countAllResults());
    }

    private function linkMigration(): LinkCharactersToAccounts
    {
        return new LinkCharactersToAccounts($this->forgeInstance);
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
            'name'             => 'acc_test_' . bin2hex(random_bytes(3)),
            'created_at'       => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->conn->insertID();
    }

    private function accountOf(int $characterId): ?int
    {
        $row = $this->conn->table('characters')->select('account_id')->where('id', $characterId)->get()->getRowArray();
        $v   = $row['account_id'] ?? null;

        return is_numeric($v) ? (int) $v : null;
    }

    /** Снимает три новые миграции (guarded down) — работает и на частично поднятой схеме. */
    private function downAccounts(): void
    {
        $this->conn->resetDataCache();
        if ($this->conn->tableExists('game_settings')) {
            (new WebOpenRegistrationSetting($this->forgeInstance))->down();
        }
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

        foreach (['game_settings', 'characters', 'telegram_users'] as $table) {
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
            $this->conn->table('characters')->like('name', 'acc_test_', 'after')->delete();
        }
        if ($this->conn->tableExists('telegram_users')) {
            $this->conn->table('telegram_users')->where('telegram_id >=', 900000000)->where('telegram_id <', 900001000)->delete();
        }
    }

    private function requireMigrationClasses(): void
    {
        $classes = [
            CreateTelegramUsersTable::class   => '2024-03-20-153728_CreateTelegramUsersTable.php',
            CreateCharactersTable::class      => '2024-03-20-154155_CreateCharactersTable.php',
            CreateGameSettingsTable::class    => '2026-05-19-100000_CreateGameSettingsTable.php',
            CreateAccountsTables::class       => '2026-12-10-100001_CreateAccountsTables.php',
            LinkCharactersToAccounts::class   => '2026-12-10-100002_LinkCharactersToAccounts.php',
            WebOpenRegistrationSetting::class => '2026-12-10-100003_WebOpenRegistrationSetting.php',
        ];

        foreach ($classes as $class => $file) {
            if (! class_exists($class, false)) {
                require_once APPPATH . 'Database/Migrations/' . $file;
            }
        }
    }
}
