<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Logging\TelegramDeliveryProbe;
use App\Services\Player\CharacterSheetService;
use App\Services\Telegram\BotMenuService;
use App\Services\Web\AccountService;
use App\Services\Web\DeliveryContext;
use App\Services\Web\VirtualIdentityService;
use App\Services\Web\WebActService;
use App\Services\Web\WebDelivery;
use App\Services\Web\WebNativeScreenService;
use CodeIgniter\Config\Factories;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\Security\Exceptions\SecurityException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Database;
use Config\Services;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Longman\TelegramBot\Request as LongmanRequest;
use Psr\Http\Message\RequestInterface;

/**
 * W2.N1-01 (ADR-190) — `POST /play/view` и HUD: нативный «Я» из модели персонажа, те же гейты,
 * что у `/play/act` (флаг, сессия, CSRF, лимит), персонаж только из сессии, HUD в каждом ответе,
 * кнопки без нативного экрана — через мост, док «🧑 Я» — в нативный экран.
 *
 * Полная модель персонажа тянет десятки таблиц чужих фич, поэтому `forCharacter()` подменён
 * фикстурой (имя — из БД по id сессии); HUD — настоящий, из `characters`/`map`/`character_tasks`.
 *
 * @internal
 */
final class PlayViewControllerTest extends CIUnitTestCase
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

    private const SITE_MIGRATION = '2026-05-25-180000_CreateSiteCategoriesTable';

    private BaseConnection $conn;

    private bool $createdSiteCategories = false;

    /** @var array<string, string|false> */
    private array $envBackup = [];

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
            $this->createdSiteCategories = ! $this->conn->tableExists('site_categories');
            if ($this->createdSiteCategories) {
                $this->migration(self::SITE_MIGRATION, $forge instanceof Forge ? $forge : null)->up();
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
        DeliveryContext::reset();
        WebDelivery::reset();
        $this->installProbeStub();
        service('cache')->save('game_settings_web_play_enabled', ['v' => true, 't' => 'bool'], 60);
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $k => $v) {
            putenv($v === false ? $k : "{$k}={$v}");
        }
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

    // ── Гейты: флаг, вход, CSRF, лимит ───────────────────────────────────

    public function testFlagOffViewIs403AndDispatchesNothing(): void
    {
        [$session] = $this->character('Странник');
        service('cache')->save('game_settings_web_play_enabled', ['v' => false, 't' => 'bool'], 60);

        $this->assertSame(403, $this->postWithCsrf($session, 'play/view', ['view' => 'me'], true)->response()->getStatusCode());
        $this->assertSame(403, $this->postWithCsrf($session, 'play/view', ['op' => 'bridge', 'data' => 'inventory', 'intent_id' => 'f1'], true)->response()->getStatusCode());
        $this->assertSame(0, $this->conn->table('web_play_intents')->countAllResults());
    }

    public function testGuestJsonIs401(): void
    {
        $res = $this->postWithCsrf([], 'play/view', ['view' => 'me'], true);
        $this->assertSame(401, $res->response()->getStatusCode());
    }

    public function testPostWithoutCsrfIsRejected(): void
    {
        [$session] = $this->character('Странник');
        try {
            $res = $this->withSession($session)->post('play/view', ['view' => 'me']);
            $this->assertContains($res->response()->getStatusCode(), [403, 419], 'принят POST без CSRF');
        } catch (SecurityException $e) {
            $this->assertStringContainsString('not allowed', $e->getMessage());
        }
    }

    public function testRouteCarriesThePlayThrottle(): void
    {
        $this->assertSame(['accountThrottle:play'], service('routes')->getFiltersForRoute('play/view', 'POST'));
    }

    // ── Нативный «Я» + HUD ───────────────────────────────────────────────

    public function testViewMeRendersNativeScreenOfTheSessionCharacterOnly(): void
    {
        [$session, , $tgA] = $this->character('Ворон');
        [, $charB]         = $this->character('Чужак');
        $this->stubSheets();

        $res = $this->postWithCsrf($session, 'play/view', [
            'view' => 'me', 'character_id' => (string) $charB, 'account_id' => '999',
        ], true);
        $res->assertStatus(200);
        $json = $this->json($res);

        $this->assertStringContainsString('data-native="me"', $json['html']);
        $this->assertStringContainsString('Ворон', $json['html']);
        $this->assertStringNotContainsString('Чужак', $json['html']);
        $this->assertStringContainsString('id="play-hud"', $json['hud']);
        $this->assertStringContainsString('class="play-dock"', $json['html'], 'док остаётся на нативном экране');
        $this->assertIsString($json['csrf']);
        $this->assertStringNotContainsString((string) $tgA, $this->body($res), 'ни одного telegram id');
    }

    public function testHudShowsStatsLocationAndActiveTaskTimer(): void
    {
        [$session, $charId] = $this->character('Ворон');
        $this->conn->table('biomes')->insert(['id' => 3, 'name' => 'Пустошь']);
        $this->conn->table('map')->insert(['cell_number' => 777, 'coordinate_x' => 12, 'coordinate_y' => 7, 'biome_id' => 3]);
        $this->conn->table('characters')->where('id', $charId)->update(['cell_number' => 777, 'health' => 64, 'gold' => 12480]);
        $this->conn->table('tasks')->insert(['id' => 5, 'name' => 'gather', 'name_rus' => 'Добыча металла', 'description' => '']);
        $endsAt = time() + 600;
        $this->conn->table('character_tasks')->insert([
            'character_id' => $charId, 'telegram_user_id' => 1, 'task_id' => 5,
            'start_time' => date('Y-m-d H:i:s'), 'end_time' => date('Y-m-d H:i:s', $endsAt), 'status' => 'in_work',
        ]);
        $this->stubSheets();

        $hud = $this->json($this->postWithCsrf($session, 'play/view', ['view' => 'me'], true))['hud'];

        $this->assertStringContainsString('<dd>64</dd>', $hud);
        $this->assertStringContainsString('12 480', $hud);
        $this->assertStringContainsString('X=12 Y=7', $hud);
        $this->assertStringContainsString('Пустошь', $hud);
        $this->assertStringContainsString('Добыча металла', $hud);
        $this->assertStringContainsString('data-ends-at="' . $endsAt . '"', $hud);
    }

    public function testUnknownViewOrOpIs400WithoutDispatch(): void
    {
        [$session] = $this->character('Ворон');
        foreach ([['view' => 'admin'], ['view' => 'me', 'op' => 'equip'], []] as $i => $post) {
            $res = $this->postWithCsrf($session, 'play/view', $post, true);
            $this->assertSame(400, $res->response()->getStatusCode(), "запрос #{$i}");
            $this->assertStringContainsString('id="play-hud"', $this->json($res)['hud']);
        }
        $this->assertSame(0, $this->conn->table('web_play_intents')->countAllResults());
    }

    public function testWithoutJsViewRedirectsToPlayWithViewAndPageRendersNativeScreen(): void
    {
        [$session] = $this->character('Ворон');
        $this->stubSheets();

        $res = $this->postWithCsrf($session, 'play/view', ['view' => 'me']);
        $this->assertSame(303, $res->response()->getStatusCode());
        $this->assertStringEndsWith('/play?view=me', $res->response()->getHeaderLine('Location'));

        $this->seedScreen($session['character_id']);
        $page = $this->body($this->withSession($session)->get('play?view=me'));
        $this->assertStringContainsString('data-native="me"', $page);
        $this->assertStringContainsString('id="play-hud"', $page);
    }

    // ── Док и мост ───────────────────────────────────────────────────────

    public function testDockMeButtonOpensNativeViewOthersStayOnTheBridge(): void
    {
        [$session, $charId] = $this->character('Ворон');
        $this->seedScreen($charId);

        $page = html_entity_decode($this->body($this->withSession($session)->get('play')), ENT_QUOTES | ENT_HTML5);

        $this->assertMatchesRegularExpression('~action="[^"]*/play/view" method="post">.*?name="view" value="me">.*?🧑 Я</button>~su', $page);
        $this->assertMatchesRegularExpression('~action="[^"]*/play/act" method="post">.*?name="data" value="🏠 База">~su', $page);
        $this->assertStringContainsString('id="play-hud"', $page);
    }

    public function testBridgeRejectsCallbackThatIsNotOnTheCharacterScreen(): void
    {
        [$session] = $this->character('Ворон');
        $act       = $this->fakeAct();
        $this->stubSheets($act);

        $res = $this->postWithCsrf($session, 'play/view', ['op' => 'bridge', 'data' => 'admin_give_gold', 'intent_id' => 'b1'], true);

        $this->assertSame(400, $res->response()->getStatusCode());
        $this->assertSame([], $act->calls);
    }

    public function testBridgeSummonsTheCardThenPressesItsButton(): void
    {
        [$session] = $this->character('Ворон');
        $act       = $this->fakeAct();
        $this->stubSheets($act);

        $res = $this->postWithCsrf($session, 'play/view', ['op' => 'bridge', 'data' => 'inventory', 'intent_id' => 'b2'], true);
        $res->assertStatus(200);

        $this->assertSame([
            ['intent_id' => 'b2:card', 'kind' => 'text', 'data' => BotMenuService::menuLabel('me')],
            ['intent_id' => 'b2:cb', 'kind' => 'callback', 'data' => 'inventory', 'message_id' => '41'],
        ], $act->calls);
        $this->assertStringContainsString('Инвентарь моста', $this->json($res)['html']);
        $this->assertStringContainsString('id="play-hud"', $this->json($res)['hud']);
    }

    public function testActAndInboxResponsesCarryHud(): void
    {
        [$session] = $this->character('Ворон');
        $this->stubSheets($this->fakeAct());

        $act = $this->json($this->postWithCsrf($session, 'play/act', ['intent_id' => 'a1', 'kind' => 'command', 'data' => '/guide'], true));
        $this->assertStringContainsString('id="play-hud"', $act['hud']);

        $inbox = $this->json($this->withSession($session)->get('play/inbox'));
        $this->assertStringContainsString('id="play-hud"', $inbox['hud']);
    }

    // ── Фикстура ─────────────────────────────────────────────────────────

    /** Полная модель подменена фикстурой (имя — из БД), HUD — настоящий. */
    private function stubSheets(?WebActService $act = null): void
    {
        $conn   = $this->conn;
        $sheets = new class ($conn) extends CharacterSheetService {
            /** @param BaseConnection<object, object> $conn */
            public function __construct(private BaseConnection $conn)
            {
                parent::__construct($conn);
            }

            public function forCharacter(int $characterId): ?array
            {
                $row  = $this->conn->table('characters')->where('id', $characterId)->get()->getRowArray();
                $name = is_array($row) && is_string($row['name'] ?? null) ? $row['name'] : '';
                $hud  = CharacterSheetService::buildHud(['health' => '100', 'tired' => '100', 'gold' => 0, 'level' => 1, 'experience' => '0'], null, null, null, null, []);

                return [
                    'id' => $characterId, 'name' => $name, 'faction' => null, 'cell' => null, 'biome' => '???',
                    'explored' => 0, 'resource_kinds' => 0, 'time_in_game' => '0 мес. 0 дн. 0 чс.',
                    'level' => '1', 'experience' => '0.01', 'agility' => '0.01', 'intellect' => '0.01', 'strength' => '0.01',
                    'health' => '100', 'tired' => '100', 'trading_karma' => '0', 'gold' => 0,
                    'polar_line' => null, 'ladder_line' => null, 'unlock_line' => null, 'debuffs' => [],
                    'streak_line' => null, 'milestone_line' => null, 'title' => null,
                    'armor' => null, 'weapon' => null, 'specialization' => null, 'drone' => null,
                    'personal_actions' => [['id' => 'inventory', 'label' => '🎒 Инвентарь', 'callback' => 'inventory']],
                    'tail_actions' => [['id' => 'guide', 'label' => '📖 Путь новичка', 'callback' => 'guide']],
                    'hud' => $hud,
                ];
            }
        };
        Factories::injectMock('libraries', WebNativeScreenService::class, new WebNativeScreenService($act, $sheets));
        if ($act !== null) {
            Factories::injectMock('libraries', WebActService::class, $act);
        }
    }

    /** Мост-двойник: пишет намерения, «карточка» несёт кнопку `inventory` на сообщении 41. */
    private function fakeAct(): WebActService
    {
        return new class () extends WebActService {
            /** @var list<array<string, mixed>> */
            public array $calls = [];

            public function act(int $accountId, int $characterId, array $intent): array
            {
                $this->calls[] = $intent;
                $card          = str_ends_with((string) ($intent['intent_id'] ?? ''), ':card');
                $msg           = [
                    'message_id' => $card ? 41 : 42, 'text' => $card ? 'Карточка' : 'Инвентарь моста', 'caption' => null,
                    'parse_mode' => null, 'photo_url' => null,
                    'inline_keyboard' => $card ? [[['text' => '🎒 Инвентарь', 'callback_data' => 'inventory']]] : [],
                ];

                return ['state' => ['screen' => [$msg], 'history' => [], 'dock' => [['🧑 Я']], 'input' => null], 'alert' => null, 'unread' => 0];
            }

            public function current(int $characterId): array
            {
                return ['state' => ['screen' => [], 'history' => [], 'dock' => [['🧑 Я']], 'input' => null], 'alert' => null, 'unread' => 0];
            }
        };
    }

    /** Сохранённый экран моста с доком — `/play` не делает первый вход. */
    private function seedScreen(int $characterId): void
    {
        $this->conn->table('web_play_state')->insert([
            'character_id' => $characterId,
            'screen'       => json_encode([['message_id' => 1000000000, 'text' => 'Экран', 'caption' => null, 'parse_mode' => null, 'photo_url' => null, 'inline_keyboard' => []]], JSON_UNESCAPED_UNICODE),
            'history'      => '[]',
            'dock'         => json_encode([['🧑 Я', '🏠 База']], JSON_UNESCAPED_UNICODE),
            'input'        => null,
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return array{0: array{account_id:int, character_id:int}, 1: int, 2: int} */
    private function character(string $name): array
    {
        $accountId = (new AccountService($this->conn))->createAccount('web');
        $tgUser    = (new VirtualIdentityService($this->conn))->ensureForAccount($accountId, $name);
        $this->conn->table('characters')->insert([
            'telegram_user_id' => $tgUser, 'account_id' => $accountId, 'name' => $name,
            'level' => 1, 'experience' => 0.01, 'health' => 100, 'tired' => 100,
            'strength' => 0.01, 'agility' => 0.01, 'intellect' => 0.01, 'gold' => 1000,
        ]);
        $charId   = (int) $this->conn->insertID();
        $identity = (new VirtualIdentityService($this->conn))->identityForCharacter($charId);
        $this->assertNotNull($identity);

        return [['account_id' => $accountId, 'character_id' => $charId], $charId, $identity['telegram_id']];
    }

    /** @param array<string, int> $session */
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

    /** @return array<string, mixed> */
    private function json(TestResponse $res): array
    {
        $json = json_decode($this->body($res), true);
        $this->assertIsArray($json);

        return $json;
    }

    private function body(TestResponse $res): string
    {
        return (string) $res->response()->getBody();
    }

    private function installProbeStub(): void
    {
        TelegramDeliveryProbe::reset();
        TelegramDeliveryProbe::install();
        $stub = new Client(['handler' => static fn (RequestInterface $request): PromiseInterface => Create::promiseFor(new Response(200, [], '{"ok":true,"result":true}'))]);
        (new \ReflectionProperty(TelegramDeliveryProbe::class, 'client'))->setValue(null, $stub);
        LongmanRequest::setClient($stub);
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
        if ($this->createdSiteCategories) {
            $this->conn->query('DROP TABLE IF EXISTS `site_categories`');
            $this->createdSiteCategories = false;
        }
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
        $this->conn->resetDataCache();
    }
}
