<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Filters\AccountThrottleFilter;
use App\Services\Logging\ActionOrigin;
use App\Services\Logging\PlayerActionLogger;
use App\Services\Logging\TelegramDeliveryProbe;
use App\Services\Web\AccountService;
use App\Services\Web\DeliveryContext;
use App\Services\Web\VirtualIdentityService;
use App\Services\Web\WebDelivery;
use App\Services\Web\WebInboxService;
use App\Services\Web\WebScreenStore;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Security\Exceptions\SecurityException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Database;
use Config\Services;
use Config\WebPlay;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Longman\TelegramBot\Request as LongmanRequest;
use Psr\Http\Message\RequestInterface;

/**
 * web-bridge-p1-07 (ADR-189 §1, §6) — маршруты `/play`: флаг на сервере (Ask 7), входящие и
 * колокол (Ask 4), CSRF/лимит/персонаж только из сессии (Ask 6), ни одного telegram id в ответе
 * (Ask 2), первый вход поднимает экран и док (A13).
 *
 * Схема — исполнением настоящих миграций; сеть — заглушка за клиентом Probe.
 *
 * @internal
 */
final class PlayControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

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
        '2026-05-08-220000_AddDisableMediaFlag',
        '2026-05-19-100000_CreateGameSettingsTable',
        '2026-05-22-330000_TipsCAddDailyToggle',
        '2026-07-17-100000_E6AddLastSeenToTelegramUsers',
        '2026-07-19-100000_E6AddLoginStreakToCharacters',
        '2026-09-02-120000_Adr181CreateTelegramUpdatesSeen',
        '2026-09-28-100000_Adr148CreatePlayerActionLogTable',
        '2026-09-28-130000_Adr148PlayerActionLogAddTaskSource',
        '2026-11-10-100000_Adr148AddUndeliveredStatus',
        '2026-11-19-100000_Adr168PlayerActionLogAddOrigin',
        '2026-12-10-100001_CreateAccountsTables',
        '2026-12-10-100002_LinkCharactersToAccounts',
        '2026-12-10-100010_NullableTelegramKeys',
        '2026-12-11-100001_CreateWebPlayTables',
        '2026-12-11-100003_PlayerActionLogWebSource',
        '2026-12-11-100005_SignedTelegramIdColumns',
    ];

    private const TABLES = [
        'biomes', 'map', 'telegram_users', 'accounts', 'account_identities', 'account_tokens', 'account_link_codes',
        'characters', 'action_log', 'tasks', 'character_tasks', 'explored_cells', 'game_settings', 'player_action_log',
        'telegram_updates_seen', 'web_play_state', 'web_inbox', 'web_play_intents',
    ];

    private const ENV = ['telegram.API_KEY' => '123456:TEST_TOKEN', 'telegram.BOT_USERNAME' => 'wildworldtest_bot'];

    private const REAL_TG = 555000888;

    private BaseConnection $conn;

    /** @var array<string, string|false> */
    private array $envBackup = [];

    /** @var list<string> методы Bot API, дошедшие до сети */
    private array $network = [];

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
        } catch (\Throwable $e) {
            $this->dropTables();

            throw $e;
        }
        foreach (self::ENV as $k => $v) {
            $this->envBackup[$k] = getenv($k);
            putenv("{$k}={$v}");
        }
        $this->mockSession();
        $this->mockCache();
        Services::resetSingle('throttler');
        PlayerActionLogger::reset();
        ActionOrigin::reset();
        DeliveryContext::reset();
        WebDelivery::reset();
        $this->installProbeStub();
        $this->setFlag(true);
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $k => $v) {
            putenv($v === false ? $k : "{$k}={$v}");
        }
        PlayerActionLogger::reset();
        ActionOrigin::reset();
        DeliveryContext::reset();
        WebDelivery::reset();
        TelegramDeliveryProbe::reset();
        service('superglobals')->unsetCookie('csrf_cookie_name');
        Services::resetSingle('cache');
        Services::resetSingle('security');
        Services::resetSingle('throttler');
        $this->dropTables();
        parent::tearDown();
    }

    // ── Ask 7: флаг ─────────────────────────────────────────────────────

    public function testLoggedOutGoesToLogin(): void
    {
        $this->get('play')->assertRedirectTo('/account/login');
    }

    public function testFlagOffEveryRouteIsStubOr403AndDispatchesNothing(): void
    {
        [$session] = $this->virtualCharacter();
        $this->setFlag(false);

        $page = $this->withSession($session)->get('play');
        $page->assertStatus(200);
        $this->assertStringContainsString('Игра на сайте скоро', $this->body($page));
        $this->assertStringNotContainsString('id="play-root"', $this->body($page));

        $this->assertSame(403, $this->postWithCsrf($session, 'play/act', ['intent_id' => 'f1', 'kind' => 'command', 'data' => '/guide'])->response()->getStatusCode());
        $this->assertSame(403, $this->postWithCsrf($session, 'play/act', ['intent_id' => 'f2', 'kind' => 'command', 'data' => '/guide'], true)->response()->getStatusCode());
        $this->assertSame(403, $this->withSession($session)->get('play/inbox')->response()->getStatusCode());
        $this->assertSame(403, $this->postWithCsrf($session, 'play/inbox/read', [], true)->response()->getStatusCode());

        $this->assertSame(0, $this->conn->table('web_play_intents')->countAllResults());
        $this->assertSame(0, $this->conn->table('player_action_log')->countAllResults());
        $this->assertSame(0, $this->conn->table('web_play_state')->countAllResults());
    }

    public function testNoCharacterShowsStub(): void
    {
        $accountId = (new AccountService($this->conn))->createAccount('web');
        $page      = $this->withSession(['account_id' => $accountId])->get('play');

        $page->assertStatus(200);
        $this->assertStringContainsString('Нет персонажа', $this->body($page));
        $this->assertSame(0, $this->conn->table('web_play_intents')->countAllResults());
    }

    // ── Первый вход + Ask 2 на HTML ─────────────────────────────────────

    public function testFirstVisitBootstrapsOnceAndRendersScreenAndDockWithoutTelegramIds(): void
    {
        foreach ([$this->virtualCharacter(), $this->linkedCharacter()] as [$session, $charId, $tgId]) {
            $first = $this->withSession($session)->get('play');
            $first->assertStatus(200);
            $html = $this->body($first);
            $this->assertStringContainsString('id="play-root"', $html, 'флаг включён — site/play');

            $state = (new WebScreenStore($this->conn))->state($charId);
            $this->assertNotSame([], $state['screen'], 'bootstrap дал экран');
            $this->assertNotSame([], $state['dock'], 'bootstrap дал док');
            $this->assertStringContainsString('class="play-msg-text"', $html);
            $this->assertStringContainsString('play-dock-btn', $html);
            $this->assertStringNotContainsString((string) $tgId, $html, 'в HTML нет telegram id');

            $this->withSession($session)->get('play')->assertStatus(200);
            $this->assertSame(1, $this->conn->table('player_action_log')->where('character_id', $charId)->where('action_name', 'start')->countAllResults(), 'bootstrap один раз');
        }
        $this->assertSame([], $this->network, 'в сеть ничего не ушло');
    }

    // ── Ask 4 + Ask 2 на JSON ───────────────────────────────────────────

    public function testJsonActReturnsHtmlUnreadCsrfAndNoTelegramId(): void
    {
        foreach ([$this->virtualCharacter(), $this->linkedCharacter()] as [$session, $charId, $tgId]) {
            (new WebInboxService($this->conn))->append($charId, $this->msg(5, 'Крафт готов'), 'virtual');

            $res = $this->postWithCsrf($session, 'play/act', ['intent_id' => "j-{$charId}", 'kind' => 'command', 'data' => '/guide'], true);
            $res->assertStatus(200);
            $raw  = $this->body($res);
            $json = json_decode($raw, true);
            $this->assertIsArray($json);
            $this->assertSame(1, $json['unread']);
            $this->assertIsString($json['html']);
            $this->assertStringContainsString('play-screen', $json['html']);
            $this->assertIsString($json['csrf']);
            $this->assertArrayHasKey('alert', $json);
            $this->assertStringNotContainsString((string) $tgId, $raw, 'в JSON нет telegram id');
        }
    }

    public function testActWithoutJsonRedirectsToPlay(): void
    {
        [$session] = $this->virtualCharacter();
        $res       = $this->postWithCsrf($session, 'play/act', ['intent_id' => 'prg', 'kind' => 'command', 'data' => '/guide']);

        $this->assertSame(303, $res->response()->getStatusCode());
        $this->assertStringEndsWith('/play', $res->response()->getHeaderLine('Location'));
    }

    public function testInboxReturnsUnreadAndFragmentAndReadZeroesIt(): void
    {
        [$session, $charId, $tgId] = $this->virtualCharacter();
        $inbox                     = new WebInboxService($this->conn);
        $inbox->append($charId, $this->msg(7, 'На базу напали'), 'virtual');
        $inbox->append($charId, $this->msg(8, 'Крафт готов'), 'virtual');

        $res = $this->withSession($session)->get('play/inbox');
        $res->assertStatus(200);
        $json = json_decode($this->body($res), true);
        $this->assertIsArray($json);
        $this->assertSame(2, $json['unread']);
        $this->assertIsString($json['html']);
        $this->assertStringContainsString('На базу напали', $json['html']);
        $this->assertStringNotContainsString((string) $tgId, $this->body($res));

        $read = $this->postWithCsrf($session, 'play/inbox/read', [], true);
        $read->assertStatus(200);
        $readJson = json_decode($this->body($read), true);
        $this->assertIsArray($readJson);
        $this->assertSame(0, $readJson['unread']);
        $this->assertSame(0, $inbox->unreadCount($charId));
        $this->assertSame(0, $this->conn->table('player_action_log')->countAllResults(), 'входящие ничего не диспетчат');
    }

    // ── Ask 6 ───────────────────────────────────────────────────────────

    public function testCallbackNotOnScreenIs4xxWithoutDispatch(): void
    {
        [$session] = $this->virtualCharacter();
        $res       = $this->postWithCsrf($session, 'play/act', ['intent_id' => 'bad', 'kind' => 'callback', 'data' => 'admin_give_gold', 'message_id' => '1000000000'], true);

        $this->assertSame(400, $res->response()->getStatusCode());
        $this->assertSame(0, $this->conn->table('player_action_log')->countAllResults());
        $this->assertSame(0, $this->conn->table('web_play_intents')->countAllResults());
    }

    public function testRequestBodyCannotSelectAnotherCharacter(): void
    {
        [$sessionA, $charA]    = $this->virtualCharacter();
        [, $charB, $tgB]       = $this->linkedCharacter();
        $accountB              = (int) $this->row('characters', ['id' => $charB])['account_id'];

        $this->postWithCsrf($sessionA, 'play/act', [
            'intent_id' => 'x1', 'kind' => 'command', 'data' => '/guide',
            'character_id' => (string) $charB, 'account_id' => (string) $accountB,
            'telegram_id' => (string) $tgB, 'chat_id' => (string) $tgB,
        ], true)->assertStatus(200);

        $this->assertSame(1, $this->conn->table('player_action_log')->where('character_id', $charA)->countAllResults());
        $this->assertSame(0, $this->conn->table('player_action_log')->where('character_id', $charB)->countAllResults());
        $this->assertSame([], (new WebScreenStore($this->conn))->state($charB)['screen']);
    }

    public function testPostWithoutCsrfIsRejectedByTheGlobalFilter(): void
    {
        [$session] = $this->virtualCharacter();
        foreach (['play/act', 'play/inbox/read'] as $path) {
            try {
                $res = $this->withSession($session)->post($path, ['intent_id' => 'nocsrf', 'kind' => 'command', 'data' => '/guide']);
                $this->assertContains($res->response()->getStatusCode(), [403, 419], "{$path} принял POST без CSRF");
            } catch (SecurityException $e) {
                $this->assertStringContainsString('not allowed', $e->getMessage());
            }
        }
        $this->assertSame(0, $this->conn->table('web_play_intents')->countAllResults());
    }

    public function testThrottleBucketsArePerAccount(): void
    {
        [$sessionA] = $this->virtualCharacter();
        [$sessionB] = $this->virtualCharacter();
        $config     = new WebPlay();
        $filter     = new AccountThrottleFilter();

        foreach (['play' => $config->actsPerMinute, 'inbox' => $config->inboxReadsPerMinute] as $bucket => $limit) {
            $this->login($sessionA);
            for ($i = 0; $i < $limit; $i++) {
                $this->assertNull($filter->before($this->request('POST'), [$bucket]), "{$bucket} #{$i}");
            }
            $over = $filter->before($this->request('GET'), [$bucket]);
            $this->assertInstanceOf(ResponseInterface::class, $over);
            $this->assertSame(429, $over->getStatusCode(), "{$bucket}: сверх лимита");

            $this->login($sessionB);
            $this->assertNull($filter->before($this->request('POST'), [$bucket]), "{$bucket}: чужое ведро не тронуто");
        }
    }

    public function testRoutesCarryTheThrottleArguments(): void
    {
        $routes  = service('routes');
        $filters = static fn (string $verb, string $path): array => $routes->getFiltersForRoute($path, $verb);
        $this->assertSame(['accountThrottle:play'], $filters('POST', 'play/act'));
        $this->assertSame(['accountThrottle:play'], $filters('POST', 'play/inbox/read'));
        $this->assertSame(['accountThrottle:inbox'], $filters('GET', 'play/inbox'));
    }

    // ── Фикстура ─────────────────────────────────────────────────────────

    /** @param array<string, int> $session */
    private function login(array $session): void
    {
        $s = Services::session();
        foreach ($session as $k => $v) {
            $s->set($k, $v);
        }
    }

    private function request(string $method): IncomingRequest
    {
        $request = Services::incomingrequest(null, false);
        $request->setMethod($method);

        return $request;
    }

    /**
     * @param array<string, int>    $session
     * @param array<string, string> $data
     */
    private function postWithCsrf(array $session, string $path, array $data, bool $json = false): TestResponse
    {
        $hash = bin2hex(random_bytes(16));
        service('superglobals')->setCookie('csrf_cookie_name', $hash);
        Services::resetSingle('security');
        $self = $this->withSession($session);
        if ($json) {
            $self = $self->withHeaders(['Accept' => 'application/json']);
        }
        $res = $self->post($path, $data + ['csrf_test_name' => $hash]);
        $this->withHeaders([]);

        return $res;
    }

    private function body(TestResponse $res): string
    {
        return (string) $res->response()->getBody();
    }

    /** @return array{message_id:int, text:?string, caption:?string, parse_mode:?string, photo_url:?string, inline_keyboard:list<list<array{text:string, callback_data?:string, url?:string}>>} */
    private function msg(int $id, string $text): array
    {
        return ['message_id' => $id, 'text' => $text, 'caption' => null, 'parse_mode' => null, 'photo_url' => null, 'inline_keyboard' => []];
    }

    private function setFlag(bool $on): void
    {
        service('cache')->save('game_settings_web_play_enabled', ['v' => $on, 't' => 'bool'], 60);
    }

    private function installProbeStub(): void
    {
        TelegramDeliveryProbe::reset();
        TelegramDeliveryProbe::install();
        $this->network = [];
        $stub          = new Client(['handler' => function (RequestInterface $request): PromiseInterface {
            $this->network[] = basename($request->getUri()->getPath());

            return Create::promiseFor(new Response(200, [], '{"ok":true,"result":true}'));
        }]);
        (new \ReflectionProperty(TelegramDeliveryProbe::class, 'client'))->setValue(null, $stub);
        LongmanRequest::setClient($stub);
    }

    /** @return array{0: array<string,int>, 1:int, 2:int} session, character, telegram_id */
    private function virtualCharacter(): array
    {
        $accountId = (new AccountService($this->conn))->createAccount('web');
        $tgUser    = (new VirtualIdentityService($this->conn))->ensureForAccount($accountId, 'Странник');
        $charId    = $this->makeCharacter($tgUser, $accountId);
        $identity  = (new VirtualIdentityService($this->conn))->identityForCharacter($charId);
        $this->assertNotNull($identity);

        return [['account_id' => $accountId, 'character_id' => $charId], $charId, $identity['telegram_id']];
    }

    /** @return array{0: array<string,int>, 1:int, 2:int} */
    private function linkedCharacter(): array
    {
        $accountId = (new AccountService($this->conn))->createAccount('web');
        $now       = date('Y-m-d H:i:s');
        $this->conn->table('telegram_users')->insert(['telegram_id' => self::REAL_TG, 'first_name' => 'Т', 'created_at' => $now, 'updated_at' => $now]);
        $charId = $this->makeCharacter((int) $this->conn->insertID(), $accountId);

        return [['account_id' => $accountId, 'character_id' => $charId], $charId, self::REAL_TG];
    }

    private function makeCharacter(int $telegramUserId, int $accountId): int
    {
        $this->conn->table('characters')->insert([
            'telegram_user_id' => $telegramUserId, 'account_id' => $accountId, 'name' => 'Странник',
            'level' => 1, 'experience' => 0.01, 'health' => 100, 'tired' => 100,
            'strength' => 0.01, 'agility' => 0.01, 'intellect' => 0.01, 'gold' => 1000,
        ]);

        return (int) $this->conn->insertID();
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
