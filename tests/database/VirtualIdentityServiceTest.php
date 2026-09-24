<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Models\ExploredCellsModel;
use App\Services\Logging\PlayerActionLogger;
use App\Services\Web\AccountService;
use App\Services\Web\AccountSession;
use App\Services\Web\VirtualChat;
use App\Services\Web\VirtualIdentityService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Config\Services;

/**
 * web-bridge-p1-01 (ADR-189 §3) — виртуальная строка `telegram_users` web-only персонажа.
 *
 * Tracer: бэкфилл создаёт виртуальную строку → {@see VirtualIdentityService::identityForCharacter()}
 * отдаёт её с `virtual=true` → строка firehose с каналом `web` и виртуальным `from.id` пишется
 * под STRICT. Плюс: идемпотентность и откат бэкфилла, заполнение NULL-ключей задач и тумана,
 * `ExploredCellsModel::revealAround()` без FK-ошибки, и то, что виртуальная строка не
 * становится Telegram-входом ни в `AccountService`, ни в `AccountSession`.
 *
 * Схема — исполнением настоящих миграций.
 *
 * @internal
 */
final class VirtualIdentityServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const MIGRATIONS = [
        '2024-03-17-222643_CreateBiomesTable',
        '2024-03-18-105708_CreateMapTable',
        '2024-03-20-153728_CreateTelegramUsersTable',
        '2024-03-20-154155_CreateCharactersTable',
        '2024-03-18-134951_CreateActionLogTable',
        '2024-03-22-111828_CreateTasksTable',
        '2024-03-22-132411_CreateCharacterTasksTable',
        '2024-03-24-212921_CreateExploredCellsTable',
        '2026-05-19-100000_CreateGameSettingsTable',
        '2026-09-28-100000_Adr148CreatePlayerActionLogTable',
        '2026-09-28-130000_Adr148PlayerActionLogAddTaskSource',
        '2026-11-10-100000_Adr148AddUndeliveredStatus',
        '2026-11-19-100000_Adr168PlayerActionLogAddOrigin',
        '2026-12-10-100001_CreateAccountsTables',
        '2026-12-10-100002_LinkCharactersToAccounts',
        '2026-12-10-100010_NullableTelegramKeys',
        '2026-12-11-100003_PlayerActionLogWebSource',
        '2026-12-11-100005_SignedTelegramIdColumns',
    ];

    private const BACKFILL = '2026-12-11-100004_BackfillVirtualTelegramUsers';

    private const TABLES = [
        'biomes', 'map', 'telegram_users', 'accounts', 'account_identities', 'account_tokens', 'account_link_codes',
        'characters', 'action_log', 'tasks', 'character_tasks', 'explored_cells', 'game_settings', 'player_action_log',
    ];

    private BaseConnection $conn;

    private ?string $sqlMode = null;

    private int $taskId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = Database::connect();
        $this->dropTables();
        try {
            $forge = Database::forge();
            foreach (self::MIGRATIONS as $file) {
                $this->migration($file, $forge instanceof Forge ? $forge : null)->up();
            }
            $this->seed();
        } catch (\Throwable $e) {
            $this->dropTables();

            throw $e;
        }
        // STRICT на время теста (прод идёт под STRICT_TRANS_TABLES); соединение общее — вернуть в tearDown.
        $mode           = $this->conn->query('SELECT @@SESSION.sql_mode AS m')->getRowArray();
        $this->sqlMode  = is_array($mode) ? (string) $mode['m'] : '';
        $this->conn->query("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
        PlayerActionLogger::reset();
    }

    protected function tearDown(): void
    {
        if ($this->sqlMode !== null) {
            $this->conn->query('SET SESSION sql_mode = ?', [$this->sqlMode]);
        }
        PlayerActionLogger::reset();
        $this->dropTables();
        parent::tearDown();
    }

    public function testTracerBackfillIdentityAndWebFirehoseRow(): void
    {
        $accountId = (new AccountService($this->conn))->createAccount('web');
        $charId    = $this->makeCharacter('Странник', null, $accountId);

        $this->backfill()->up();

        $virtualId = VirtualChat::idForAccount($accountId);
        $identity  = (new VirtualIdentityService($this->conn))->identityForCharacter($charId);
        $this->assertNotNull($identity);
        $this->assertTrue($identity['virtual']);
        $this->assertSame($virtualId, $identity['telegram_id']);
        $this->assertSame('Странник', $identity['first_name']);
        $this->assertSame($identity['telegram_user_id'], (int) $this->row('characters', ['id' => $charId])['telegram_user_id']);

        $logger = PlayerActionLogger::current();
        $logger->begin(['callback_query' => [
            'data'    => 'map_open',
            'from'    => ['id' => $virtualId],
            'message' => ['chat' => ['id' => $virtualId, 'type' => 'private']],
        ]], 'web');
        $logger->commit();

        $log = $this->row('player_action_log', ['source' => 'web']);
        $this->assertSame((string) $virtualId, (string) $log['telegram_user_id']);
        $this->assertSame((string) $virtualId, (string) $log['chat_id']);
        $this->assertSame($charId, (int) $log['character_id']);
    }

    public function testActionLogAcceptsVirtualChatIdUnderStrict(): void
    {
        $accountId = (new AccountService($this->conn))->createAccount('web');
        $charId    = $this->makeCharacter('Странник', null, $accountId);

        $this->conn->table('action_log')->insert([
            'character_id' => $charId, 'chat_id' => VirtualChat::idForAccount($accountId),
            'action_name' => 'probe', 'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertSame((string) VirtualChat::idForAccount($accountId), (string) $this->row('action_log', ['character_id' => $charId])['chat_id']);
    }

    public function testBackfillIsIdempotentAndFillsOnlyNullKeysOfVirtualCharacters(): void
    {
        $accounts = new AccountService($this->conn);
        $webA     = $this->makeCharacter('A', null, $accounts->createAccount('web'));
        $webB     = $this->makeCharacter('B', null, $accounts->createAccount('web'));
        $orphan   = $this->makeCharacter('Сирота', null, null);
        $realTg   = $this->makeTelegramUser(555000222);
        $bot      = $this->makeCharacter('Бот', $realTg, $accounts->createAccount('telegram'));

        $this->makeTask($webA, null);
        $this->makeExplored($webA, null);
        $this->makeTask($bot, $realTg);

        $this->backfill()->up();
        $this->backfill()->up();

        $this->assertSame(2, $this->virtualRowCount());
        $this->assertSame(3, $this->rowCount('telegram_users', []));
        $tgA = (int) $this->row('characters', ['id' => $webA])['telegram_user_id'];
        $tgB = (int) $this->row('characters', ['id' => $webB])['telegram_user_id'];
        $this->assertNotSame($tgA, $tgB);
        $this->assertNull($this->row('characters', ['id' => $orphan])['telegram_user_id']);
        $this->assertSame($realTg, (int) $this->row('characters', ['id' => $bot])['telegram_user_id']);

        $this->assertSame($tgA, (int) $this->row('character_tasks', ['character_id' => $webA])['telegram_user_id']);
        $this->assertSame($tgA, (int) $this->row('explored_cells', ['character_id' => $webA])['telegram_user_id']);
        $this->assertSame($realTg, (int) $this->row('character_tasks', ['character_id' => $bot])['telegram_user_id']);
        $this->assertSame(0, $this->rowCount('account_identities', []));
    }

    public function testBackfillDownRemovesOnlyVirtualRowsAndKeepsProgress(): void
    {
        $accounts = new AccountService($this->conn);
        $web      = $this->makeCharacter('A', null, $accounts->createAccount('web'));
        $realTg   = $this->makeTelegramUser(555000222);
        $bot      = $this->makeCharacter('Бот', $realTg, $accounts->createAccount('telegram'));
        $this->makeTask($web, null);
        $this->makeExplored($web, null);

        $this->backfill()->up();
        $this->backfill()->down();

        $this->assertSame(0, $this->virtualRowCount());
        $this->assertSame(1, $this->rowCount('telegram_users', []));
        $this->assertNull($this->row('characters', ['id' => $web])['telegram_user_id']);
        $this->assertSame($realTg, (int) $this->row('characters', ['id' => $bot])['telegram_user_id']);
        // FK задач/тумана — CASCADE: прогресс не стёрт, ключ снова NULL.
        $this->assertNull($this->row('character_tasks', ['character_id' => $web])['telegram_user_id']);
        $this->assertNull($this->row('explored_cells', ['character_id' => $web])['telegram_user_id']);
    }

    public function testRevealAroundForBackfilledCharacterHasNoFkError(): void
    {
        $charId = $this->makeCharacter('A', null, (new AccountService($this->conn))->createAccount('web'));
        $this->backfill()->up();
        $identity = (new VirtualIdentityService($this->conn))->identityForCharacter($charId);
        $this->assertNotNull($identity);

        $revealed = (new ExploredCellsModel())->revealAround($charId, $identity['telegram_user_id'], 10, 10);

        $this->assertSame(1, $revealed);
        $this->assertSame($identity['telegram_user_id'], (int) $this->row('explored_cells', ['character_id' => $charId])['telegram_user_id']);
    }

    public function testEnsureForAccountIsIdempotentAndCreatesNoIdentity(): void
    {
        $accountId = (new AccountService($this->conn))->createAccount('web');
        $svc       = new VirtualIdentityService($this->conn);

        $first  = $svc->ensureForAccount($accountId, 'Имя');
        $second = $svc->ensureForAccount($accountId, 'Другое');

        $this->assertSame($first, $second);
        $this->assertSame(1, $this->rowCount('telegram_users', []));
        $this->assertSame((string) VirtualChat::idForAccount($accountId), (string) $this->row('telegram_users', ['id' => $first])['telegram_id']);
        $this->assertSame(0, $this->rowCount('account_identities', []));
    }

    public function testIdentityForRealTelegramCharacterIsNotVirtual(): void
    {
        $tg     = $this->makeTelegramUser(555000222);
        $charId = $this->makeCharacter('Бот', $tg, null);

        $identity = (new VirtualIdentityService($this->conn))->identityForCharacter($charId);

        $this->assertNotNull($identity);
        $this->assertFalse($identity['virtual']);
        $this->assertSame(555000222, $identity['telegram_id']);
        $this->assertNull((new VirtualIdentityService($this->conn))->identityForCharacter($charId + 999));
    }

    public function testAccountServiceTreatsVirtualRowAsNoTelegram(): void
    {
        $accounts = new AccountService($this->conn);
        $virtual  = (new VirtualIdentityService($this->conn))->ensureForAccount($accounts->createAccount('web'), 'A');
        $charId   = $this->makeCharacter('A', $virtual, null);
        $real     = $this->makeTelegramUser(555000222);

        $this->assertTrue($accounts->isVirtualTelegramUser($virtual));
        $this->assertFalse($accounts->isVirtualTelegramUser($real));
        $this->assertNull($accounts->ensureForCharacter($charId));
        $this->assertSame(1, $this->rowCount('accounts', []));
        $this->assertSame(0, $this->rowCount('account_identities', []));
    }

    public function testAccountSessionNeverWritesOrUpgradesVirtualTelegramKey(): void
    {
        $accounts  = new AccountService($this->conn);
        $accountId = $accounts->createAccount('web');
        $charId    = $this->makeCharacter('A', null, $accountId);
        $this->backfill()->up();
        $virtual = (int) $this->row('characters', ['id' => $charId])['telegram_user_id'];

        $this->mockSession();
        $session = new AccountSession($accounts, $this->conn);
        $session->login($accountId);
        $this->assertNull(Services::session()->get('tg_user_id'));
        $this->assertSame($charId, $session->characterId());

        // Legacy-сессия только с tg_user_id виртуальной строки — не вход и не новый аккаунт.
        $this->mockSession();
        Services::session()->set('tg_user_id', $virtual);
        $this->assertNull((new AccountSession($accounts, $this->conn))->current());
        $this->assertNull(Services::session()->get('tg_user_id'));
        $this->assertSame(1, $this->rowCount('accounts', []));
        $this->assertSame(0, $this->rowCount('account_identities', []));
    }

    // ── Фикстура ─────────────────────────────────────────────────────────────

    private function seed(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->conn->table('biomes')->insert([
            'id' => 1, 'name' => 'Равнина', 'description' => '', 'occurrence_rate' => 1, 'danger_level' => 1, 'survival_difficulty' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $n = 10 * 1000 + 10 + 1;
        $this->conn->table('map')->insert([
            'id' => $n, 'cell_number' => $n, 'coordinate_x' => 10, 'coordinate_y' => 10, 'biome_id' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->conn->table('tasks')->insert([
            'name' => 'gather', 'description' => '', 'type' => 'gather', 'difficulty_level' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->taskId = (int) $this->conn->insertID();
    }

    private function makeCharacter(string $name, ?int $telegramUserId, ?int $accountId): int
    {
        $this->conn->table('characters')->insert([
            'telegram_user_id' => $telegramUserId, 'account_id' => $accountId, 'name' => $name,
            'level' => 1, 'experience' => 0.01, 'health' => 100, 'tired' => 100,
            'strength' => 0.01, 'agility' => 0.01, 'intellect' => 0.01, 'gold' => 1000,
        ]);

        return (int) $this->conn->insertID();
    }

    private function makeTelegramUser(int $telegramId): int
    {
        $now = date('Y-m-d H:i:s');
        $this->conn->table('telegram_users')->insert(['telegram_id' => $telegramId, 'created_at' => $now, 'updated_at' => $now]);

        return (int) $this->conn->insertID();
    }

    private function makeTask(int $charId, ?int $telegramUserId): void
    {
        $now = date('Y-m-d H:i:s');
        $this->conn->table('character_tasks')->insert([
            'character_id' => $charId, 'telegram_user_id' => $telegramUserId, 'task_id' => $this->taskId,
            'start_time' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function makeExplored(int $charId, ?int $telegramUserId): void
    {
        $now = date('Y-m-d H:i:s');
        $this->conn->table('explored_cells')->insert([
            'character_id' => $charId, 'telegram_user_id' => $telegramUserId, 'map_cell_id' => 10011, 'biome_id' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function virtualRowCount(): int
    {
        return $this->conn->table('telegram_users')
            ->where('telegram_id <=', -VirtualChat::VIRTUAL_BASE)
            ->countAllResults();
    }

    private function backfill(): Migration
    {
        $forge = Database::forge();

        return $this->migration(self::BACKFILL, $forge instanceof Forge ? $forge : null);
    }

    /**
     * @param array<string, int|string> $where
     *
     * @return array<string, mixed>
     */
    private function row(string $table, array $where): array
    {
        $row = $this->conn->table($table)->where($where)->get()->getRowArray();
        $this->assertIsArray($row, "{$table}: строка не найдена");

        return $row;
    }

    /** @param array<string, int|string> $where */
    private function rowCount(string $table, array $where): int
    {
        return $this->conn->table($table)->where($where)->countAllResults();
    }

    private function migration(string $file, ?Forge $forge): Migration
    {
        require_once APPPATH . 'Database/Migrations/' . $file . '.php';
        $class = 'App\\Database\\Migrations\\' . substr($file, 18);
        $m     = new $class($forge);
        $this->assertInstanceOf(Migration::class, $m);

        return $m;
    }

    private function dropTables(): void
    {
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach (array_reverse(self::TABLES) as $t) {
            $this->conn->query("DROP TABLE IF EXISTS `{$t}`");
        }
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
        $this->conn->resetDataCache();
    }
}
