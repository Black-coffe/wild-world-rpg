<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Craft\CraftOrderService;
use App\Services\Craft\CraftQueueService;
use App\Services\Logging\TelegramDeliveryProbe;
use App\Services\Player\CharacterSheetService;
use App\Services\Player\EquipmentLoadoutService;
use App\Services\Player\InventoryViewService;
use App\Services\Telegram\BotMenuService;
use App\Services\Web\AccountService;
use App\Services\Web\DeliveryContext;
use App\Services\Web\VirtualIdentityService;
use App\Services\Web\WebActService;
use App\Services\Web\WebDelivery;
use App\Services\Web\WebNativeScreenService;
use App\Services\Web\WebScreenStore;
use App\Services\World\LiveMapService;
use App\Services\World\MoveService;
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
 * W2.N1-01/02 (ADR-190) — `POST /play/view` и HUD, нативные «Я» и инвентарь: нативный «Я» из модели персонажа, те же гейты,
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
        '2024-04-16-100640_CreateCraftedItemsTable',
        '2024-04-16-122053_CreateCraftedItemsLogTable',
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
        'characters', 'action_log', 'tasks', 'character_tasks', 'explored_cells', 'crafted_items', 'crafted_items_log', 'game_settings', 'player_action_log',
        'telegram_updates_seen', 'web_play_state', 'web_inbox', 'web_play_intents',
        'claimed_cells', 'buildings', 'character_buildings', 'base_storage', 'faction_endgame_scores', 'resources', 'character_resources',
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
        $this->seedScreen($charId, [['🧑 Я', '📋 Дела']]);

        $page = html_entity_decode($this->body($this->withSession($session)->get('play')), ENT_QUOTES | ENT_HTML5);

        $this->assertMatchesRegularExpression('~action="[^"]*/play/view" method="post">.*?name="view" value="me">.*?🧑 Я</button>~su', $page);
        $this->assertMatchesRegularExpression('~action="[^"]*/play/act" method="post">.*?name="data" value="📋 Дела">~su', $page);
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

        $res = $this->postWithCsrf($session, 'play/view', ['op' => 'bridge', 'data' => 'guide', 'intent_id' => 'b2'], true);
        $res->assertStatus(200);

        $this->assertSame([
            ['intent_id' => 'b2:card', 'kind' => 'text', 'data' => BotMenuService::menuLabel('me')],
            ['intent_id' => 'b2:cb', 'kind' => 'callback', 'data' => 'guide', 'message_id' => '41'],
        ], $act->calls);
        $this->assertStringContainsString('Экран моста', $this->json($res)['html']);
        $this->assertStringContainsString('id="play-hud"', $this->json($res)['hud']);
    }

    public function testNativeActionIsNotSentThroughTheBridge(): void
    {
        [$session] = $this->character('Ворон');
        $act       = $this->fakeAct();
        $this->stubSheets($act);

        $res = $this->postWithCsrf($session, 'play/view', ['op' => 'bridge', 'data' => 'inventory', 'intent_id' => 'b3'], true);

        $this->assertSame(400, $res->response()->getStatusCode(), '«Инвентарь» — нативный экран, не мост');
        $this->assertSame([], $act->calls);
    }

    // ── Инвентарь (02) ───────────────────────────────────────────────────

    public function testInventoryViewRendersShelvesTabsSearchAndBridgeButtons(): void
    {
        [$session] = $this->character('Ворон');
        $this->stubSheets(null, [
            [['quantity' => '1204', 'name' => 'Металлолом', 'rarity' => '2', 'price' => '1']],
            [['quantity' => '2', 'name_rus' => 'Рыбный суп', 'type' => 'food', 'price' => '1', 'name' => 'Рыбный суп']],
        ]);

        $html = html_entity_decode($this->json($this->postWithCsrf($session, 'play/view', ['view' => 'inventory'], true))['html'], ENT_QUOTES | ENT_HTML5);

        $this->assertStringContainsString('data-native="inventory"', $html);
        $this->assertMatchesRegularExpression('~data-inv-name="металлолом">.*?Металлолом.*?× 1 204~su', $html);
        $this->assertStringContainsString('href="#play-inv-resources"', $html, 'вкладка без JS — якорь к полке');
        $this->assertStringContainsString('id="play-inv-food"', $html);
        $this->assertStringContainsString(InventoryViewService::FOOD_MARKER, $html);
        $this->assertStringContainsString(InventoryViewService::FOOD_PATH_LINE, $html);
        $this->assertMatchesRegularExpression('~data-inv-search-row hidden~', $html, 'поиск — только с JS');
        foreach (['baseStorageList', 'whereItWent', 'resourceOverview'] as $cb) {
            $this->assertMatchesRegularExpression('~name="op" value="bridge">.*?name="data" value="' . $cb . '">~s', $html);
        }
    }

    public function testEmptyInventoryExplainsWhereThingsComeFrom(): void
    {
        [$session] = $this->character('Ворон');
        $this->stubSheets(null, [[], []]);

        $html = html_entity_decode($this->json($this->postWithCsrf($session, 'play/view', ['view' => 'inventory'], true))['html'], ENT_QUOTES | ENT_HTML5);

        $this->assertStringContainsString('Рюкзак пуст', $html);
        $this->assertStringContainsString('«🧑‍🌾 Действия 🛠️» → добыча на клетке', $html);
        $this->assertStringNotContainsString('data-inv-tab', $html);
    }

    public function testMeScreenOpensInventoryNatively(): void
    {
        [$session] = $this->character('Ворон');
        $this->stubSheets();

        $html = $this->json($this->postWithCsrf($session, 'play/view', ['view' => 'me'], true))['html'];

        $this->assertMatchesRegularExpression('~name="view" value="inventory"><button class="play-kb-btn" type="submit">🎒 Инвентарь</button>~u', $html);
    }

    public function testInventoryBridgeButtonWalksCardThenHubThenPressesIt(): void
    {
        [$session] = $this->character('Ворон');
        $act       = $this->fakeAct();
        $this->stubSheets($act);

        $this->postWithCsrf($session, 'play/view', ['op' => 'bridge', 'data' => 'whereItWent', 'intent_id' => 'w1'], true)->assertStatus(200);

        $this->assertSame([
            ['intent_id' => 'w1:card', 'kind' => 'text', 'data' => BotMenuService::menuLabel('me')],
            ['intent_id' => 'w1:s0', 'kind' => 'callback', 'data' => 'inventory', 'message_id' => '41'],
            ['intent_id' => 'w1:cb', 'kind' => 'callback', 'data' => 'whereItWent', 'message_id' => '43'],
        ], $act->calls);
    }

    // ── Карта «Мир» (W2.N2-01) ──────────────────────────────────────────

    public function testDockWorldButtonOpensNativeMap(): void
    {
        [$session, $charId] = $this->character('Ворон');
        $this->seedScreen($charId, [['🌍 Мир', '🧑 Я', '🏠 База']]);

        $page = html_entity_decode($this->body($this->withSession($session)->get('play')), ENT_QUOTES | ENT_HTML5);

        $this->assertMatchesRegularExpression('~action="[^"]*/play/view" method="post">.*?name="view" value="map">.*?🌍 Мир</button>~su', $page);
        $this->assertSame('map', WebNativeScreenService::viewForDockLabel('Карта'), 'при world_hub OFF в доке «Карта»');
    }

    public function testMapViewRendersGridRoseAndHud(): void
    {
        [$session, , $tg] = $this->character('Ворон');
        $this->stubSheets(null, [[], []], $this->stubMap());

        $res = $this->postWithCsrf($session, 'play/view', ['view' => 'map'], true);
        $res->assertStatus(200);
        $json = $this->json($res);
        $html = html_entity_decode($json['html'], ENT_QUOTES | ENT_HTML5);

        $this->assertStringContainsString('data-native="map"', $html);
        $this->assertSame(144, substr_count($html, 'class="play-map-cell'), 'окно 12×12');
        $this->assertStringContainsString('🙎‍♂️', $html);
        $this->assertStringContainsString('X=10 Y=10', $html);
        $this->assertStringContainsString('🏕 База: 3 ходов ↗️', $html);
        // Соседняя клетка и роза — нативный шаг (op=step); прочая клетка — подсказка.
        $this->assertMatchesRegularExpression('~name="op" value="step"><input type="hidden" name="dir" value="north"><input type="hidden" name="intent_id" value="[0-9a-f]{32}"><button class="play-map-cell is-biome is-step"~su', $html);
        $this->assertMatchesRegularExpression('~name="op" value="step"><input type="hidden" name="dir" value="north"><input type="hidden" name="intent_id" value="[0-9a-f]{32}"><button class="play-kb-btn" type="submit">⬆️ Север</button>~su', $html);
        $this->assertStringNotContainsString('value="move_dir_', $html, 'шаг больше не идёт через мост');
        $this->assertMatchesRegularExpression('~name="op" value="cell"><input type="hidden" name="x" value="4"><input type="hidden" name="y" value="5">~su', $html);
        // W2.N2-03: клетка на луче дальше соседней — превью Похода (n — расстояние по Чебышёву).
        $this->assertMatchesRegularExpression('~name="op" value="march_preview"><input type="hidden" name="dir" value="east"><input type="hidden" name="n" value="3"><button class="play-map-cell is-biome is-ray"~su', $html);
        $this->assertMatchesRegularExpression('~name="op" value="march_preview"><input type="hidden" name="dir" value="northwest"><input type="hidden" name="n" value="6"><button class="play-map-cell is-biome is-ray is-far"~su', $html);
        $this->assertStringContainsString('href="' . base_url('map') . '"', $html, 'ссылка «Весь мир»');
        $this->assertMatchesRegularExpression('~name="data" value="island"><button class="play-kb-btn" type="submit">🌍 Остров живёт</button>~su', $html);
        $this->assertStringContainsString('class="play-dock"', $html);
        $this->assertStringContainsString('id="play-hud"', $json['hud']);
        $this->assertStringNotContainsString((string) $tg, $this->body($res), 'ни одного telegram id');
    }

    public function testMapCellGivesHintAndRejectsCellOutsideTheWindow(): void
    {
        [$session] = $this->character('Ворон');
        $this->stubSheets(null, [[], []], $this->stubMap());

        $json = $this->json($this->postWithCsrf($session, 'play/view', ['view' => 'map', 'op' => 'cell', 'x' => '4', 'y' => '5'], true));
        $this->assertIsString($json['alert']);
        $this->assertStringContainsString('X=4, Y=5', $json['alert']);
        $this->assertStringContainsString('Лес', $json['alert']);

        $fog = $this->json($this->postWithCsrf($session, 'play/view', ['view' => 'map', 'op' => 'cell', 'x' => '5', 'y' => '5'], true));
        $this->assertStringContainsString('Не изучено', (string) $fog['alert']);

        foreach ([['x' => '40', 'y' => '5'], ['x' => 'a', 'y' => '5'], ['y' => '5']] as $i => $xy) {
            $res = $this->postWithCsrf($session, 'play/view', ['view' => 'map', 'op' => 'cell'] + $xy, true);
            $this->assertSame(400, $res->response()->getStatusCode(), "клетка #{$i}");
        }
    }

    public function testMapButtonWithoutOwnScreenGoesThroughTheBridgeFromTheWorldScreen(): void
    {
        [$session] = $this->character('Ворон');
        $act       = $this->fakeAct();
        $this->stubSheets($act, [[], []], $this->stubMap());

        $res = $this->postWithCsrf($session, 'play/view', ['op' => 'bridge', 'data' => 'island', 'intent_id' => 'm1'], true);
        $res->assertStatus(200);

        $this->assertSame([
            ['intent_id' => 'm1:card', 'kind' => 'command', 'data' => '/go'],
            ['intent_id' => 'm1:cb', 'kind' => 'callback', 'data' => 'island', 'message_id' => '44'],
        ], $act->calls);
    }

    /**
     * W2.N2-02: клик по соседней клетке и роза — нативный шаг тем же сервисом, что у бота; хуки шага
     * (здесь — подсказка) и события (рана) ложатся на экран моста и видны под картой, их кнопки —
     * `/play/act` с `message_id` своего сообщения. Повтор `intent_id` второго шага не делает.
     */
    public function testStepMovesShowsEventsUnderTheMapAndDedupsTheIntent(): void
    {
        [$session, $charId, $tg] = $this->character('Ворон');
        $move = $this->stubMove();
        $this->stubSheets(null, [[], []], $this->stubMap(), $move);

        $res = $this->postWithCsrf($session, 'play/view', ['view' => 'map', 'op' => 'step', 'dir' => 'north', 'intent_id' => 'st1'], true);
        $res->assertStatus(200);
        $json = $this->json($res);
        $html = html_entity_decode($json['html'], ENT_QUOTES | ENT_HTML5);

        $this->assertSame([[$charId, 'north', $tg]], $move->calls, 'шаг и хуки — персонаж сессии, чат его личности');
        $this->assertSame('Вы двинулись на: север.', $json['alert']);
        $this->assertStringContainsString('data-native="map"', $html);
        $this->assertStringContainsString('Подсказка новичку', $html, 'сообщение хука под картой');
        $this->assertStringContainsString('<b>Перелом!</b>', $html, 'событие шага под картой, Markdown отрисован');

        $screen = (new WebScreenStore($this->conn))->state($charId)['screen'];
        $this->assertCount(2, $screen, 'хук и событие — на экране моста');
        foreach ([[$screen[0], 'Base'], [$screen[1], 'pharmacy']] as [$msg, $cb]) {
            $this->assertMatchesRegularExpression(
                '~action="[^"]*/play/act" method="post">.*?name="kind" value="callback"><input type="hidden" name="data" value="' . $cb . '"><input type="hidden" name="message_id" value="' . $msg['message_id'] . '">~su',
                $html
            );
        }

        $again = $this->json($this->postWithCsrf($session, 'play/view', ['view' => 'map', 'op' => 'step', 'dir' => 'north', 'intent_id' => 'st1'], true));
        $this->assertCount(1, $move->calls, 'повтор intent_id — без второго шага');
        $this->assertNull($again['alert']);
        $this->assertSame(1, $this->conn->table('web_play_intents')->where('intent_id', 'st1:step')->countAllResults());

        $refused = $this->json($this->postWithCsrf($session, 'play/view', ['view' => 'map', 'op' => 'step', 'dir' => 'west', 'intent_id' => 'st2'], true));
        $this->assertSame('🧭 Там край острова — дальше на запад пути нет.', $refused['alert'], 'отказ — ответом кнопки');

        foreach ([['dir' => 'up', 'intent_id' => 'st3'], ['dir' => 'north'], ['dir' => 'north', 'intent_id' => str_repeat('a', 61)]] as $i => $bad) {
            $res = $this->postWithCsrf($session, 'play/view', ['view' => 'map', 'op' => 'step'] + $bad, true);
            $this->assertSame(400, $res->response()->getStatusCode(), "шаг #{$i}");
        }
        $this->assertCount(2, $move->calls);
    }

    public function testStepWithoutJsIsPrgToTheMapWithEventsUnderIt(): void
    {
        [$session, $charId] = $this->character('Ворон');
        $this->seedScreen($charId);
        $this->stubSheets(null, [[], []], $this->stubMap(), $this->stubMove());

        $res = $this->postWithCsrf($session, 'play/view', ['view' => 'map', 'op' => 'step', 'dir' => 'east', 'intent_id' => 'nj1']);
        $this->assertSame(303, $res->response()->getStatusCode());
        $this->assertStringEndsWith('/play?view=map', $res->response()->getHeaderLine('Location'));

        $flash = [
            'play_alert'      => $_SESSION['play_alert'] ?? null,
            'play_map_events' => $_SESSION['play_map_events'] ?? null,
            '__ci_vars'       => ['play_alert' => 'new', 'play_map_events' => 'new'],
        ];
        $this->assertSame('Вы двинулись на: восток.', $flash['play_alert']);
        $this->assertIsArray($flash['play_map_events']);

        $page = html_entity_decode($this->body($this->withSession($session + $flash)->get('play?view=map')), ENT_QUOTES | ENT_HTML5);
        $this->assertStringContainsString('data-native="map"', $page);
        $this->assertStringContainsString('Вы двинулись на: восток.', $page);
        $this->assertStringContainsString('Подсказка новичку', $page);
        $this->assertStringContainsString('<b>Перелом!</b>', $page);
    }

    /**
     * W2.N2-03: клик по клетке на луче — превью Похода тем же сервисом, что экран маршрута бота:
     * число клеток (зажато в потолок заказа), ETA, расход, ➖/➕ и «Выступить».
     */
    public function testRayCellOpensMarchPreviewWithCellsEtaAndCost(): void
    {
        [$session] = $this->character('Ворон');
        $this->enableMarch();
        $this->stubSheets(null, [[], []], $this->stubMap());

        $res = $this->postWithCsrf($session, 'play/view', ['view' => 'map', 'op' => 'march_preview', 'dir' => 'east', 'n' => '999'], true);
        $res->assertStatus(200);
        $html = html_entity_decode($this->json($res)['html'], ENT_QUOTES | ENT_HTML5);

        $this->assertStringContainsString('🚜 Поход: ➡️ Восток ×60', $html, 'n зажат в потолок заказа');
        $this->assertStringContainsString('60 из 60 возможных', $html);
        $this->assertStringContainsString('<dt>В пути</dt><dd>~0 мин</dd>', $html);
        $this->assertStringContainsString('❤️ 1.2 · 💤 30', $html, 'расход — world.march.* по умолчанию');
        $this->assertMatchesRegularExpression('~name="op" value="march_start"><input type="hidden" name="dir" value="east"><input type="hidden" name="n" value="60"><input type="hidden" name="intent_id" value="[0-9a-f]{32}"><button class="play-kb-btn is-primary" type="submit">🚜 Выступить</button>~su', $html);
        $this->assertMatchesRegularExpression('~name="op" value="march_preview"><input type="hidden" name="dir" value="east"><input type="hidden" name="n" value="59">~su', $html, '➖');
        $this->assertSame(0, $this->conn->table('character_tasks')->countAllResults(), 'превью ничего не пишет');

        $bad = $this->postWithCsrf($session, 'play/view', ['view' => 'map', 'op' => 'march_preview', 'dir' => 'up', 'n' => '3'], true);
        $this->assertSame(400, $bad->response()->getStatusCode());
    }

    /**
     * «Выступить» пишет ту же строку Похода, что бот, без `msg_*`; HUD и карта показывают прогресс,
     * «Продлить» и «Остановиться» работают; повтор `intent_id` не создаёт второй Поход.
     */
    public function testMarchStartShowsProgressExtendsStopsAndDedupsTheIntent(): void
    {
        [$session, $charId] = $this->character('Ворон');
        $this->enableMarch();
        $this->stubSheets(null, [[], []], $this->stubMap());

        $start = $this->json($this->postWithCsrf($session, 'play/view', ['view' => 'map', 'op' => 'march_start', 'dir' => 'east', 'n' => '3', 'intent_id' => 'ms1'], true));
        $this->assertStringStartsWith('🚜 Поход начат: ➡️ Восток ×3.', (string) $start['alert']);
        $html = html_entity_decode($start['html'], ENT_QUOTES | ENT_HTML5);
        $this->assertStringContainsString('🚜 Поход идёт: ➡️ Восток · 0/3 клеток', $html);
        $this->assertMatchesRegularExpression('~name="op" value="march_stop"><input type="hidden" name="intent_id" value="[0-9a-f]{32}"><button class="play-kb-btn" type="submit">❌ Остановиться</button>~su', $html);
        $hud = html_entity_decode($start['hud'], ENT_QUOTES | ENT_HTML5);
        $this->assertStringContainsString('class="play-hud-march"', $hud);
        $this->assertStringContainsString('🚜 Поход: ➡️ Восток · 0/3 клеток', $hud);
        $this->assertStringContainsString('value="march_stop"', $hud);

        $rows = $this->conn->table('character_tasks')->get()->getResultArray();
        $this->assertCount(1, $rows);
        $this->assertSame(['in_work', (string) $charId], [$rows[0]['status'], (string) $rows[0]['character_id']]);
        $settings = json_decode((string) $rows[0]['task_settings'], true);
        $this->assertSame(['heading' => 'east', 'steps_planned' => 3, 'steps_done' => 0, 'started_cell' => 0, 'acc' => [], 'log' => []], $settings, 'без msg_* — тик шлёт прогресс новым сообщением');

        $again = $this->json($this->postWithCsrf($session, 'play/view', ['view' => 'map', 'op' => 'march_start', 'dir' => 'east', 'n' => '3', 'intent_id' => 'ms1'], true));
        $this->assertNull($again['alert']);
        $this->assertSame(1, $this->conn->table('character_tasks')->countAllResults(), 'повтор intent_id — без второго Похода');

        $busy = $this->json($this->postWithCsrf($session, 'play/view', ['view' => 'map', 'op' => 'march_start', 'dir' => 'north', 'n' => '2', 'intent_id' => 'ms2'], true));
        $this->assertStringContainsString('Вы уже заняты задачей «Поход»', (string) $busy['alert'], 'второй Поход поверх идущего — отказ');

        $more = $this->json($this->postWithCsrf($session, 'play/view', ['view' => 'map', 'op' => 'march_extend', 'n' => '5', 'intent_id' => 'me1'], true));
        $this->assertSame('Поход продлён на 5 клеток. Всего: 8.', $more['alert']);

        $forged = $this->json($this->postWithCsrf($session, 'play/view', ['view' => 'map', 'op' => 'march_extend', 'n' => '9999', 'intent_id' => 'me2'], true));
        $this->assertSame('Поход продлён на 60 клеток. Всего: 68.', $forged['alert'], 'подделанный n зажат в потолок заказа');

        $stop = $this->json($this->postWithCsrf($session, 'play/view', ['view' => 'map', 'op' => 'march_stop', 'intent_id' => 'mx1'], true));
        $this->assertStringStartsWith('🚜 Поход прерван. Пройдено 0 клеток.', (string) $stop['alert']);
        $this->assertStringNotContainsString('Поход идёт', html_entity_decode($stop['html'], ENT_QUOTES | ENT_HTML5));
        $this->assertStringNotContainsString('play-hud-march', $stop['hud']);
        $this->assertSame('completed', $this->conn->table('character_tasks')->get()->getRowArray()['status'] ?? null);

        $resume = $this->json($this->postWithCsrf($session, 'play/view', ['view' => 'map', 'op' => 'march_resume', 'intent_id' => 'mr1'], true));
        $this->assertSame('Походов на паузе нет.', $resume['alert']);
        $this->assertSame(1, $this->conn->table('web_play_intents')->where('intent_id', 'ms1:march_start')->countAllResults());

        foreach ([['op' => 'march_start', 'dir' => 'up', 'n' => '3', 'intent_id' => 'b1'], ['op' => 'march_stop'], ['op' => 'march_fly', 'intent_id' => 'b2']] as $i => $bad) {
            $res = $this->postWithCsrf($session, 'play/view', ['view' => 'map'] + $bad, true);
            $this->assertSame(400, $res->response()->getStatusCode(), "Поход #{$i}");
        }
    }

    public function testMarchPreviewWithoutJsIsPrgToTheMapWithThePreview(): void
    {
        [$session, $charId] = $this->character('Ворон');
        $this->seedScreen($charId);
        $this->enableMarch();
        $this->stubSheets(null, [[], []], $this->stubMap());

        $res = $this->postWithCsrf($session, 'play/view', ['view' => 'map', 'op' => 'march_preview', 'dir' => 'south', 'n' => '4']);
        $this->assertSame(303, $res->response()->getStatusCode());
        $this->assertStringEndsWith('/play?view=map', $res->response()->getHeaderLine('Location'));
        $this->assertSame(['dir' => 'south', 'n' => 4], $_SESSION['play_map_preview'] ?? null);

        $flash = ['play_map_preview' => ['dir' => 'south', 'n' => 4], '__ci_vars' => ['play_map_preview' => 'new']];
        $page  = html_entity_decode($this->body($this->withSession($session + $flash)->get('play?view=map')), ENT_QUOTES | ENT_HTML5);
        $this->assertStringContainsString('🚜 Поход: ⬇️ Юг ×4', $page);
        $this->assertStringContainsString('value="march_start"', $page);
    }

    /** Хвост story 01 (Ask 7): без Арсенала надетую броню можно снять, замок — только на «Надеть». */
    public function testGearWithoutArsenalOffersUnequipOfWornArmor(): void
    {
        [$session] = $this->character('Ворон');
        $item = static fn (int $id, string $name, bool $on): array => [
            'kind' => EquipmentLoadoutService::KIND_ARMOR, 'row_id' => $id, 'name' => $name, 'name_en' => 'X', 'quantity' => 1,
            'equipped' => $on, 'slot' => 'Тело', 'soulbound' => null, 'info' => [],
        ];
        $loadout = new class ($this->conn, [$item(5, 'Кожанка', true), $item(6, 'Плащ', false)]) extends EquipmentLoadoutService {
            /**
             * @param BaseConnection<object, object> $conn
             * @param list<array<string, mixed>>     $armor
             */
            public function __construct(BaseConnection $conn, private array $armor)
            {
                parent::__construct($conn);
            }

            public function forCharacter(int $characterId): array
            {
                return [
                    'arsenal' => false, 'lock' => ['title' => 'Нужен Арсенал', 'required_level' => 3, 'callback' => 'genericBuildInfo_Arsenal', 'button' => '🏗 К стройке Арсенала'],
                    'on_base' => true, 'sale_enabled' => false, 'weapons' => [], 'armor' => $this->armor,
                ];
            }
        };
        Factories::injectMock('libraries', WebNativeScreenService::class, new WebNativeScreenService(null, null, null, $loadout, $this->stubMap()));

        $html = html_entity_decode($this->json($this->postWithCsrf($session, 'play/view', ['view' => 'gear'], true))['html'], ENT_QUOTES | ENT_HTML5);

        $this->assertStringContainsString('🔒 Экипировка (нужно: Арсенал)', $html);
        $this->assertMatchesRegularExpression('~name="op" value="unequip"><input type="hidden" name="kind" value="armor"><input type="hidden" name="item" value="5">~su', $html);
        $this->assertStringContainsString('Кожанка', $html);
        $this->assertStringNotContainsString('Плащ', $html, 'не надетое без Арсенала не показывается');
        $this->assertStringNotContainsString('value="equip"', $html, '«Надеть» под замком');
    }

    /** Хвост W2.N1: `intent_id` на пределе (60) с любым суффиксом ступени влезает в VARCHAR(64). */
    public function testLongestIntentWithLongestSuffixFitsTheDedupColumn(): void
    {
        $long  = str_repeat('a', WebNativeScreenService::INTENT_MAX);
        $other = str_repeat('a', WebNativeScreenService::INTENT_MAX - 1) . 'b';
        foreach ([':gear', ':step', ':card', ':cb', ':s0', ':s12'] as $suffix) {
            $key = WebNativeScreenService::intentKey($long, $suffix);
            $this->assertLessThanOrEqual(64, strlen($key), $suffix);
            $this->assertStringEndsWith($suffix, $key);
            $this->assertSame($key, WebNativeScreenService::intentKey($long, $suffix), 'повтор — тот же ключ');
            $this->assertNotSame($key, WebNativeScreenService::intentKey($other, $suffix), 'разные намерения — разные ключи');
        }
        $this->assertSame('short:gear', WebNativeScreenService::intentKey('short', ':gear'), 'короткий ключ не меняется');

        [$session] = $this->character('Ворон');
        $act       = $this->fakeAct();
        $this->stubSheets($act);
        $this->postWithCsrf($session, 'play/view', ['op' => 'bridge', 'data' => 'guide', 'intent_id' => $long], true)->assertStatus(200);
        $this->assertCount(2, $act->calls);
        foreach ($act->calls as $call) {
            $this->assertLessThanOrEqual(64, strlen((string) $call['intent_id']));
        }
        // Колонка сама: ключ на пределе записывается целиком.
        $key = WebNativeScreenService::intentKey($long, ':card');
        $this->conn->table('web_play_intents')->insert(['account_id' => 1, 'intent_id' => $key, 'created_at' => date('Y-m-d H:i:s')]);
        $this->assertSame(1, $this->conn->table('web_play_intents')->where('intent_id', $key)->countAllResults());

        $tooLong = $this->postWithCsrf($session, 'play/view', ['op' => 'bridge', 'data' => 'guide', 'intent_id' => $long . 'x'], true);
        $this->assertSame(400, $tooLong->response()->getStatusCode());
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

    // ── W2.N3-03: «🔨 Крафт» ─────────────────────────────────────────────

    public function testDockCraftButtonOpensNativeCraft(): void
    {
        [$session, $charId] = $this->character('Ворон');
        $this->seedScreen($charId, [['🧑 Я', '🔨 Крафт']]);

        $page = html_entity_decode($this->body($this->withSession($session)->get('play')), ENT_QUOTES | ENT_HTML5);
        $this->assertMatchesRegularExpression('~action="[^"]*/play/view" method="post">.*?name="view" value="craft">.*?🔨 Крафт</button>~su', $page);
    }

    /** Верстак → категория → карточка → очередь; запертый «Проф.» — замок с требованием и путём. */
    public function testCraftWalksBenchCategoryCardAndShowsTheQueue(): void
    {
        [$session] = $this->character('Ворон');
        [$orders, $queue] = $this->stubCraft();
        $this->stubSheets(null, [[], []], null, null, $orders, $queue);

        $hub = html_entity_decode($this->json($this->postWithCsrf($session, 'play/view', ['view' => 'craft'], true))['html'], ENT_QUOTES | ENT_HTML5);
        $this->assertStringContainsString('data-native="craft"', $hub);
        $this->assertStringContainsString('🔒 Профессиональный крафт (нужно: 🛠️ Профессиональный верстак)', $hub);
        $this->assertStringContainsString('Путь: 🔨 Общий крафт → 🔬 Верстаки → 🛠️ Профессиональный верстак', $hub);
        $this->assertMatchesRegularExpression('~name="bench" value="general"><input type="hidden" name="cat" value="workbenches"><input type="hidden" name="recipe" value="ProfessionalWorkbench"><button class="play-kb-btn is-locked"~su', $hub, 'замок ведёт к карточке цеха');
        $this->assertStringContainsString('📋 Очередь крафта', $hub, 'очередь видна сразу');

        $locked = html_entity_decode($this->json($this->postWithCsrf($session, 'play/view', ['view' => 'craft', 'bench' => 'pro', 'cat' => 'weapons'], true))['html'], ENT_QUOTES | ENT_HTML5);
        $this->assertStringNotContainsString('Оружие T3', $locked, 'запертый раздел не открывается подделанной формой');

        $bench = html_entity_decode($this->json($this->postWithCsrf($session, 'play/view', ['view' => 'craft', 'bench' => 'general'], true))['html'], ENT_QUOTES | ENT_HTML5);
        $this->assertStringContainsString('💊 Лекарства · 8', $bench);
        $this->assertStringContainsString('🔥 Костёр · 5', $bench, 'рыбные блюда скрыты тем же флагом, что у бота');

        $card = $this->json($this->postWithCsrf($session, 'play/view', ['view' => 'craft', 'bench' => 'general', 'cat' => 'medicine', 'recipe' => 'Bandage'], true));
        $html = html_entity_decode($card['html'], ENT_QUOTES | ENT_HTML5);
        $this->assertStringContainsString('name="recipe" value="Antiseptic"', $html, 'список категории');
        $this->assertStringContainsString('🩹 Повязка', $html);
        $this->assertStringContainsString('<dd>5 мин</dd>', $html);
        $this->assertStringContainsString('<dd>7 шт.</dd>', $html);
        $this->assertStringContainsString('<span class="play-craft-req-qty">14 / 2</span>', $html);
        $this->assertStringContainsString('🛠️ Крафт 1 шт', $html);
        $this->assertStringContainsString('🛠️ Крафт 5 шт', $html);
        $this->assertStringNotContainsString('🛠️ Крафт 10 шт', $html, 'шаги — не больше max_qty');
        $this->assertMatchesRegularExpression('~name="qty" type="number" inputmode="numeric" min="1" max="7"~', $html);
        $this->assertMatchesRegularExpression('~data-ends-at="\d+"[^>]*>\d+:\d\d</time>~', $html, 'активный — с таймером');
        $this->assertStringContainsString('№1 · Повязка ×2', $html);
        $this->assertStringContainsString('≈ старт через 10 мин · займёт ≈ 10 мин', $html);
        $this->assertMatchesRegularExpression('~name="op" value="craft_cancel">.*?name="task" value="12">~su', $html);
    }

    /** Старт кнопкой шага и «своим числом»: повтор `intent_id` второй раз не стартует, больше `max_qty` и меньше 1 — нет. */
    public function testCraftStartByStepAndOwnNumberDedupsAndCapsAtMaxQty(): void
    {
        [$session] = $this->character('Ворон');
        [$orders, $queue] = $this->stubCraft();
        $this->stubSheets(null, [[], []], null, null, $orders, $queue);
        $nav = ['view' => 'craft', 'bench' => 'general', 'cat' => 'medicine', 'recipe' => 'Bandage', 'op' => 'craft_start'];

        $one = $this->json($this->postWithCsrf($session, 'play/view', ['qty' => '1', 'intent_id' => 'c1'] + $nav, true));
        $this->assertSame('🛠 Крафт начат: 🩹 Повязка ×1. Готово через 5 мин.', $one['alert']);
        $this->assertNull($this->json($this->postWithCsrf($session, 'play/view', ['qty' => '1', 'intent_id' => 'c1'] + $nav, true))['alert']);
        $this->assertSame([['Bandage', 1]], $orders->starts, 'повтор intent_id — без второго старта');

        $own = $this->json($this->postWithCsrf($session, 'play/view', ['qty' => '7', 'intent_id' => 'c2'] + $nav, true));
        $this->assertSame('📋 В очереди: 🩹 Повязка ×7 — №2. Начнётся, когда закончится текущий.', $own['alert']);

        $over = $this->json($this->postWithCsrf($session, 'play/view', ['qty' => '8', 'intent_id' => 'c3'] + $nav, true));
        $this->assertSame('Столько не выйдет: сейчас можно поставить не больше 7 шт.', $over['alert']);
        $this->assertCount(2, $orders->starts, 'больше max_qty — без старта');

        foreach ([['qty' => '0', 'intent_id' => 'c4'], ['qty' => '-3', 'intent_id' => 'c5'], ['qty' => '1', 'intent_id' => 'c6', 'recipe' => 'GoldBar']] as $i => $bad) {
            $this->assertSame(400, $this->postWithCsrf($session, 'play/view', $bad + $nav, true)->response()->getStatusCode(), "плохой старт #{$i}");
        }
        $this->assertCount(2, $orders->starts);
        $this->assertSame(1, $this->conn->table('web_play_intents')->where('intent_id', 'c1:craft_start')->countAllResults());
    }

    public function testCraftCancelDedupsAndWithoutJsIsPrgToTheCard(): void
    {
        [$session, $charId] = $this->character('Ворон');
        $this->seedScreen($charId);
        [$orders, $queue] = $this->stubCraft();
        $this->stubSheets(null, [[], []], null, null, $orders, $queue);

        $cancel = $this->json($this->postWithCsrf($session, 'play/view', ['view' => 'craft', 'op' => 'craft_cancel', 'task' => '12', 'intent_id' => 'x1'], true));
        $this->assertSame('❌ Отменено: Повязка ×2. Сырьё и золото вернулись туда, откуда были взяты.', $cancel['alert']);
        $this->assertNull($this->json($this->postWithCsrf($session, 'play/view', ['view' => 'craft', 'op' => 'craft_cancel', 'task' => '12', 'intent_id' => 'x1'], true))['alert']);
        $this->assertSame([12], $queue->cancels);
        $this->assertSame(400, $this->postWithCsrf($session, 'play/view', ['view' => 'craft', 'op' => 'craft_cancel', 'task' => 'abc', 'intent_id' => 'x2'], true)->response()->getStatusCode());

        $res = $this->postWithCsrf($session, 'play/view', ['view' => 'craft', 'bench' => 'general', 'cat' => 'medicine', 'recipe' => 'Bandage', 'op' => 'craft_start', 'qty' => '5', 'intent_id' => 'n1']);
        $this->assertSame(303, $res->response()->getStatusCode());
        $this->assertStringEndsWith('/play?view=craft&bench=general&cat=medicine&recipe=Bandage', $res->response()->getHeaderLine('Location'));
        $this->assertSame([['Bandage', 5]], $orders->starts);

        $page = html_entity_decode($this->body($this->withSession($session)->get('play?view=craft&bench=general&cat=medicine&recipe=Bandage')), ENT_QUOTES | ENT_HTML5);
        $this->assertStringContainsString('data-native="craft"', $page);
        $this->assertStringContainsString('🛠️ Крафт 5 шт', $page);
    }

    /** Нехватка: кнопок старта нет, «Чего не хватает?» идёт мостом от хаба `/craft` по пути бота. */
    public function testShortageCardBridgesToTheBotShortageScreen(): void
    {
        [$session] = $this->character('Ворон');
        [$orders, $queue] = $this->stubCraft(0);
        $act = $this->fakeAct();
        $this->stubSheets($act, [[], []], null, null, $orders, $queue);

        $html = html_entity_decode($this->json($this->postWithCsrf($session, 'play/view', ['view' => 'craft', 'bench' => 'general', 'cat' => 'medicine', 'recipe' => 'Bandage'], true))['html'], ENT_QUOTES | ENT_HTML5);
        $this->assertStringNotContainsString('value="craft_start"', $html);
        $this->assertMatchesRegularExpression('~name="op" value="bridge">.*?name="data" value="genericCraft_Bandage_1"><button class="play-kb-btn" type="submit">🛒 Чего не хватает\?</button>~su', $html);

        $this->postWithCsrf($session, 'play/view', ['op' => 'bridge', 'data' => 'genericCraft_Bandage_1', 'intent_id' => 'sh1'], true)->assertStatus(200);
        $this->assertSame(['intent_id' => 'sh1:card', 'kind' => 'command', 'data' => '/craft'], $act->calls[0]);

        $this->assertSame(400, $this->postWithCsrf($session, 'play/view', ['op' => 'bridge', 'data' => 'genericCraft_GoldBar_1', 'intent_id' => 'sh2'], true)->response()->getStatusCode(), 'чужой рецепт — не кнопка веб-крафта');
    }

    /** Хвосты W2.N2: строка задач у идущего Похода не «готово»; клетки дальше 3 — `is-far` (окно 7×7 на 375). */
    public function testMarchTaskRowCarriesNoPastDeadlineAndFarCellsAreMarked(): void
    {
        $stats = ['health' => '100', 'tired' => '100', 'gold' => 0, 'level' => 1, 'experience' => '0'];
        $row   = ['name' => 'Marching', 'name_rus' => 'Поход', 'end_time' => date('Y-m-d H:i:s', time() - 5)];
        $task = CharacterSheetService::buildHud($stats, null, null, null, null, [$row])['task'];
        $this->assertNotNull($task);
        $this->assertSame('Поход', $task['name']);
        $this->assertNull($task['ends_at'], 'end_time = start_time Похода — не срок');
        $eta = time() + 300;
        $this->assertSame($eta, CharacterSheetService::buildHud($stats, null, null, null, null, [$row], $eta)['task']['ends_at'] ?? null);

        [$session] = $this->character('Ворон');
        $this->enableMarch();
        $this->stubSheets(null, [[], []], $this->stubMap());
        $start = $this->json($this->postWithCsrf($session, 'play/view', ['view' => 'map', 'op' => 'march_start', 'dir' => 'east', 'n' => '3', 'intent_id' => 'hm1'], true));
        $hud   = html_entity_decode($start['hud'], ENT_QUOTES | ENT_HTML5);
        $this->assertStringContainsString('⏳ Поход', $hud);
        preg_match_all('~data-ends-at="(\d+)"~', $hud, $m);
        $this->assertCount(2, $m[1], 'таймер строки задач и таймер Похода');
        $this->assertSame($m[1][1], $m[1][0], 'срок строки «Поход» — прибытие из статуса Похода, а не end_time = start_time');

        $html = html_entity_decode($start['html'], ENT_QUOTES | ENT_HTML5);
        $this->assertStringContainsString('class="play-map-cell is-fog is-ray is-far"', $html, '(5,5) дальше 3 от (10,10)');
        $this->assertStringContainsString('class="play-map-cell is-biome is-ray is-far"', $html, 'луч дальше 3 — тоже вне окна 7×7');
        $this->assertStringContainsString('<button class="play-map-cell is-biome is-ray" type="submit" aria-label="Поход ×3: X=13 Y=10">', $html, '(13,10) в окне 7×7');
    }

    // ── Фикстура ─────────────────────────────────────────────────────────


    // ── W2.N4-03: «🏠 База» ──────────────────────────────────────────────

    public function testDockBaseButtonOpensNativeBase(): void
    {
        [$session, $charId] = $this->character('Ворон');
        $this->seedScreen($charId, [['🧑 Я', '🏠 База']]);

        $page = html_entity_decode($this->body($this->withSession($session)->get('play')), ENT_QUOTES | ENT_HTML5);
        $this->assertMatchesRegularExpression('~action="[^"]*/play/view" method="post">.*?name="view" value="base">.*?🏠 База</button>~su', $page);
    }

    /** 2+ базы под сигналом — пикер; выбор — обзор этой базы со стопками и кнопками моста; чужая `b` — отказ. */
    public function testBasePickerOverviewAndForeignBaseRefusal(): void
    {
        [$session, $charId] = $this->character('Ворон');
        [, $otherId]        = $this->character('Чужак');
        $this->enableBase();
        $this->place($charId, 300);
        $b1 = $this->addBase($charId, 100, 'Первая', 1);
        $b2 = $this->addBase($charId, 200, 'Вторая', 1);
        $this->building($charId, 2, 200, 2, 900, 3);
        $foreign = $this->addBase($otherId, 400, 'Чужая', null);
        $this->building($otherId, 2, 400, 1, 1, 1);
        $this->stubSheets();

        $picker = html_entity_decode($this->json($this->postWithCsrf($session, 'play/view', ['view' => 'base'], true))['html'], ENT_QUOTES | ENT_HTML5);
        $this->assertStringContainsString('data-native="base"', $picker);
        $this->assertStringContainsString('Активных баз: 2', $picker);
        $this->assertMatchesRegularExpression('~name="view" value="base"><input type="hidden" name="b" value="' . $b1 . '"><button class="play-kb-btn" type="submit">🏠 Первая \(X=10, Y=10\)~su', $picker);
        $this->assertStringContainsString('name="b" value="' . $b2 . '"', $picker);

        $html = html_entity_decode($this->json($this->postWithCsrf($session, 'play/view', ['view' => 'base', 'b' => (string) $b2], true))['html'], ENT_QUOTES | ENT_HTML5);
        $this->assertStringContainsString('Мастерская <span class="play-base-stack">×3</span>', $html, 'стопка — из amount');
        $this->assertStringContainsString('ур. 2 · налог 900', $html);
        $this->assertStringContainsString('X=20 Y=20', $html);
        $this->assertStringContainsString('сигнал Вышки связи (ур. 1)', $html, 'дистанционно — видно, откуда управление');
        foreach (["building_2_Workshop_b{$b2}", "hangar_b{$b2}", "baseDevelopment_b{$b2}", 'teleportBeacon', 'DeleteBase', 'demolishBuilding', 'TeleportToCamp'] as $cb) {
            $this->assertMatchesRegularExpression('~name="op" value="bridge">.*?name="data" value="' . preg_quote($cb, '~') . '">~su', $html, "кнопка моста {$cb}");
        }
        $this->assertMatchesRegularExpression('~name="section" value="upgrade"><input type="hidden" name="id" value="2">~su', $html, 'у постройки — «Улучшить»');

        $refused = html_entity_decode($this->json($this->postWithCsrf($session, 'play/view', ['view' => 'base', 'b' => (string) $foreign], true))['html'], ENT_QUOTES | ENT_HTML5);
        $this->assertStringContainsString('Эта база сейчас недоступна', $refused);
        $this->assertStringNotContainsString('X=40', $refused, 'чужая база не показывается');
        $this->assertStringNotContainsString('Чужая', $refused);
    }

    public function testNoBaseOffersCampThroughTheBridgeFromTheBaseMenu(): void
    {
        [$session, $charId] = $this->character('Ворон');
        $this->enableBase();
        $this->place($charId, 100);
        $act = $this->baseAct();
        $this->stubSheets($act);

        $html = html_entity_decode($this->json($this->postWithCsrf($session, 'play/view', ['view' => 'base'], true))['html'], ENT_QUOTES | ENT_HTML5);
        $this->assertStringContainsString('У тебя нет базы', $html);
        $this->assertMatchesRegularExpression('~name="data" value="Camp"><button class="play-kb-btn" type="submit">🏕 Разбить лагерь</button>~su', $html);

        $this->postWithCsrf($session, 'play/view', ['op' => 'bridge', 'data' => 'Camp', 'intent_id' => 'cb1'], true)->assertStatus(200);
        $this->assertSame([
            ['intent_id' => 'cb1:card', 'kind' => 'text', 'data' => BotMenuService::menuLabel('base')],
            ['intent_id' => 'cb1:cb', 'kind' => 'callback', 'data' => 'Camp', 'message_id' => '51'],
        ], $act->calls);
    }

    /** Карточка здания с суффиксом базы: «🏠 База» → выбор базы на пикере → «🏘 Постройки» → карточка. */
    public function testBuildingCardBridgeWalksBaseMenuPickerAndConstruction(): void
    {
        [$session, $charId] = $this->character('Ворон');
        $this->enableBase();
        $act = $this->baseAct();
        $this->stubSheets($act);

        $this->postWithCsrf($session, 'play/view', ['op' => 'bridge', 'data' => 'building_2_Workshop_b7', 'intent_id' => 'w1'], true)->assertStatus(200);
        $this->assertSame([
            ['intent_id' => 'w1:card', 'kind' => 'text', 'data' => BotMenuService::menuLabel('base')],
            ['intent_id' => 'w1:s0', 'kind' => 'callback', 'data' => 'Base_b7', 'message_id' => '51'],
            ['intent_id' => 'w1:s1', 'kind' => 'callback', 'data' => 'construction_b7', 'message_id' => '52'],
            ['intent_id' => 'w1:cb', 'kind' => 'callback', 'data' => 'building_2_Workshop_b7', 'message_id' => '53'],
        ], $act->calls);

        $this->assertSame(400, $this->postWithCsrf($session, 'play/view', ['op' => 'bridge', 'data' => 'building_2_Workshop', 'intent_id' => 'w2'], true)->response()->getStatusCode(), 'карточка без базы — не кнопка веб-базы');
    }

    /** Обзор — визит, как в боте; подсказка «первая постройка» веб-игроку — во входящих. */
    public function testOpeningBaseIsAVisitAndWebOnlyHintLandsInTheInbox(): void
    {
        [$session, $charId] = $this->character('Ворон');
        $this->enableBase();
        $this->place($charId, 100);
        $b = $this->addBase($charId, 100, null, null);
        $this->stubSheets();

        $this->postWithCsrf($session, 'play/view', ['view' => 'base'], true)->assertStatus(200);

        $row = $this->conn->table('claimed_cells')->where('id', $b)->get()->getRowArray();
        $this->assertNotNull($row['last_visited_at'] ?? null, 'открытие базы продлевает срок');
        $inbox = $this->conn->table('web_inbox')->where('character_id', $charId)->get()->getResultArray();
        $this->assertNotEmpty(array_filter($inbox, static fn (array $r): bool => str_contains((string) $r['payload'], 'Построй первую постройку')), 'подсказка — во входящих');
    }

    /** Каталог: замок уровня с путём; карточка — есть/нужно; старт — одна задача на intent_id, HUD с таймером; без JS — PRG. */
    public function testBuildCatalogCardStartDedupsAndShowsTheTaskInHud(): void
    {
        [$session, $charId] = $this->character('Ворон');
        $this->enableBase();
        $this->place($charId, 100);
        $b = $this->addBase($charId, 100, null, null);
        $this->stockWorkshop($charId);
        $this->conn->table('game_settings')->insert(['setting_key' => 'onboarding.cold_open_v2.build_locks', 'value_type' => 'bool', 'value_bool' => 1, 'category' => 'experimental']);
        $this->stubSheets();
        $nav = ['view' => 'base', 'b' => (string) $b];

        $catalog = html_entity_decode($this->json($this->postWithCsrf($session, 'play/view', $nav + ['section' => 'catalog'], true))['html'], ENT_QUOTES | ENT_HTML5);
        $this->assertStringContainsString('🔒 ⚔️ Арсенал (нужно: уровень 15)', $catalog);
        $this->assertStringContainsString('Путь: уровень растёт с опытом', $catalog);
        $this->assertMatchesRegularExpression('~name="section" value="building"><input type="hidden" name="key" value="Workshop"><button class="play-kb-btn" type="submit">🔧 Мастерская · 500</button>~su', $catalog);

        $card = html_entity_decode($this->json($this->postWithCsrf($session, 'play/view', $nav + ['section' => 'building', 'key' => 'Workshop'], true))['html'], ENT_QUOTES | ENT_HTML5);
        $this->assertStringContainsString('<span class="play-craft-req-qty">1500 / 1500</span>', $card);
        $this->assertMatchesRegularExpression('~name="op" value="build_start"><input type="hidden" name="b" value="' . $b . '"><input type="hidden" name="key" value="Workshop">~su', $card);

        $start = $this->json($this->postWithCsrf($session, 'play/view', $nav + ['op' => 'build_start', 'key' => 'Workshop', 'intent_id' => 'bs1'], true));
        $this->assertStringStartsWith('🏗 Стройка начата: 🔧 Мастерская.', (string) $start['alert']);
        $this->assertMatchesRegularExpression('~Стройка[^<]*</span>\s*<time class="play-hud-timer" data-ends-at="\d+"~u', $start['hud'], 'стройка — в строке задач с таймером');
        $this->assertNull($this->json($this->postWithCsrf($session, 'play/view', $nav + ['op' => 'build_start', 'key' => 'Workshop', 'intent_id' => 'bs1'], true))['alert']);
        $this->assertSame(1, $this->conn->table('character_tasks')->where('character_id', $charId)->countAllResults(), 'повтор intent_id — без второй стройки');
        $settings = json_decode((string) $this->conn->table('character_tasks')->where('character_id', $charId)->get()->getRowArray()['task_settings'], true);
        $this->assertSame(100, $settings['base_cell'] ?? null);

        $prg = $this->postWithCsrf($session, 'play/view', $nav + ['section' => 'building', 'key' => 'Workshop']);
        $this->assertSame(303, $prg->response()->getStatusCode());
        $this->assertStringEndsWith('/play?view=base&b=' . $b . '&section=building&key=Workshop', $prg->response()->getHeaderLine('Location'));

        $this->assertSame(400, $this->postWithCsrf($session, 'play/view', $nav + ['op' => 'build_start', 'key' => 'Work shop', 'intent_id' => 'bs2'], true)->response()->getStatusCode());
    }

    /** Апгрейд: превью с ценой, подтверждение один раз на intent_id — +1 уровень и одна оплата. */
    public function testUpgradePreviewAndApplyDedups(): void
    {
        [$session, $charId] = $this->character('Ворон');
        $this->enableBase();
        $this->place($charId, 100);
        $b = $this->addBase($charId, 100, null, null);
        $this->building($charId, 2, 100, 1, 500, 1);
        $this->conn->table('characters')->where('id', $charId)->update(['gold' => 60000]);
        $this->stubSheets();
        $nav = ['view' => 'base', 'b' => (string) $b];

        $preview = html_entity_decode($this->json($this->postWithCsrf($session, 'play/view', $nav + ['section' => 'upgrade', 'id' => '2'], true))['html'], ENT_QUOTES | ENT_HTML5);
        $this->assertStringContainsString('<dd>1 → 2</dd>', $preview);
        $this->assertStringContainsString('50 000 (есть 60 000)', $preview);

        $done = $this->json($this->postWithCsrf($session, 'play/view', $nav + ['op' => 'upgrade', 'id' => '2', 'intent_id' => 'up1'], true));
        $this->assertSame('⬆️ «Мастерская»: уровень 1 → 2.', $done['alert']);
        $this->assertNull($this->json($this->postWithCsrf($session, 'play/view', $nav + ['op' => 'upgrade', 'id' => '2', 'intent_id' => 'up1'], true))['alert']);
        $this->assertSame(2, (int) $this->conn->table('character_buildings')->where('character_id', $charId)->get()->getRowArray()['level']);
        $this->assertSame(10000, (int) $this->conn->table('characters')->where('id', $charId)->get()->getRowArray()['gold'], 'одна оплата');

        $poor = $this->json($this->postWithCsrf($session, 'play/view', $nav + ['op' => 'upgrade', 'id' => '2', 'intent_id' => 'up2'], true));
        $this->assertStringContainsString('Нужно иметь уровень >= 12', (string) $poor['alert'], 'следующий уровень — отказ ядра текстом');
        $this->assertSame(2, (int) $this->conn->table('character_buildings')->where('character_id', $charId)->get()->getRowArray()['level']);
    }

    /** Хвосты W2.N3: один рыбный список; замок раздела — во всю строку, перенос по словам (375). */
    public function testN3TailsFishListHasOneSourceAndLockDoesNotBreakWords(): void
    {
        $this->assertSame(CraftOrderService::FISH_RECIPES, \App\Controllers\Telegram\Commands\Actions\Craft\Cooking\CampfireCookingSelect::FISH_RECIPES);
        $css = (string) file_get_contents(FCPATH . 'assets/css/wildworld-ui.css');
        $this->assertMatchesRegularExpression('~\.play-kb-grid \.play-kb-btn\.is-locked \{[^}]*grid-column: 1 / -1;[^}]*overflow-wrap: break-word;~', $css);
    }

    /**
     * Полная модель подменена фикстурой (имя — из БД), HUD — настоящий; инвентарь — из
     * заданных сырых строк `[gathered, crafted]` (по умолчанию пуст).
     *
     * @param array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>} $inventoryRows
     */
    private function stubSheets(?WebActService $act = null, array $inventoryRows = [[], []], ?LiveMapService $map = null, ?MoveService $move = null, ?CraftOrderService $orders = null, ?CraftQueueService $queue = null): void
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
        $inventory = new class ($conn, $inventoryRows) extends InventoryViewService {
            /**
             * @param BaseConnection<object, object>                                   $conn
             * @param array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>} $rows
             */
            public function __construct(BaseConnection $conn, private array $rows)
            {
                parent::__construct($conn);
            }

            public function gathered(int $characterId): array
            {
                return $this->rows[0];
            }

            public function crafted(int $characterId): array
            {
                return $this->rows[1];
            }
        };
        Factories::injectMock('libraries', WebNativeScreenService::class, new WebNativeScreenService($act, $sheets, $inventory, null, $map ?? $this->stubMap(), $move, null, $orders, $queue));
        if ($act !== null) {
            Factories::injectMock('libraries', WebActService::class, $act);
        }
    }

    /**
     * Мост-двойник: пишет намерения. «Карточка» (`:card`) — сообщение 41 с кнопками `inventory`
     * и `guide`; хаб инвентаря (`:s0`) — сообщение 43 с `whereItWent`; остальное — 42.
     */
    private function fakeAct(): WebActService
    {
        return new class () extends WebActService {
            /** @var list<array<string, mixed>> */
            public array $calls = [];

            public function act(int $accountId, int $characterId, array $intent): array
            {
                $this->calls[] = $intent;
                $id            = (string) ($intent['intent_id'] ?? '');
                [$mid, $text, $kb] = match (true) {
                    ($intent['data'] ?? null) === '/go' => [44, 'Карта', [[['text' => '⬆️ Север', 'callback_data' => 'move_dir_north'], ['text' => '🌍 Остров живёт', 'callback_data' => 'island']]]],
                    str_ends_with($id, ':card') => [41, 'Карточка', [[['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'], ['text' => '📖 Путь новичка', 'callback_data' => 'guide']]]],
                    str_ends_with($id, ':s0')   => [43, 'Хаб инвентаря', [[['text' => '🧾 Куда ушло', 'callback_data' => 'whereItWent']]]],
                    default                     => [42, 'Экран моста', []],
                };
                $msg = [
                    'message_id' => $mid, 'text' => $text, 'caption' => null,
                    'parse_mode' => null, 'photo_url' => null, 'inline_keyboard' => $kb,
                ];

                return ['state' => ['screen' => [$msg], 'history' => [], 'dock' => [['🧑 Я']], 'input' => null], 'alert' => null, 'unread' => 0];
            }

            public function current(int $characterId): array
            {
                return ['state' => ['screen' => [], 'history' => [], 'dock' => [['🧑 Я']], 'input' => null], 'alert' => null, 'unread' => 0];
            }
        };
    }

    /**
     * Модель карты-фикстура: игрок в (10, 10), окно 4..15; клетка (5, 5) — туман, (13, 7) — своя
     * база, остальное — лес. Действия — роза, база, Поход и «Остров живёт».
     */
    private function stubMap(): LiveMapService
    {
        return new class () extends LiveMapService {
            public function forCharacter(int $characterId): array
            {
                $cells = [];
                for ($y = 4; $y < 16; $y++) {
                    $row = [];
                    for ($x = 4; $x < 16; $x++) {
                        [$code, $biome, $marker, $open] = match (true) {
                            $x === 10 && $y === 10 => [self::CODE_PLAYER, 1, '🙎‍♂️', true],
                            $x === 5 && $y === 5   => [self::CODE_FOG, null, '⬛️', false],
                            $x === 13 && $y === 7  => [self::CODE_OWN_BASE, 1, '🏕', true],
                            default                => [self::CODE_BIOME, 1, '🌲', true],
                        };
                        $row[] = ['x' => $x, 'y' => $y, 'code' => $code, 'biome' => $biome, 'marker' => $marker, 'explored' => $open];
                    }
                    $cells[] = $row;
                }

                return [
                    'error' => null, 'center' => ['x' => 10, 'y' => 10, 'biome' => 1], 'window' => ['x0' => 4, 'y0' => 4, 'size' => 12],
                    'cells' => $cells, 'distance_to_base' => ['distance' => 3, 'x' => 13, 'y' => 7, 'arrow' => '↗️'],
                    'stats' => ['health' => 88.0, 'tired' => 12.0], 'actions' => $this->actions(['id' => $characterId]), 'legend' => self::legend(),
                ];
            }

            public function actions(array|\App\Entities\CharacterEntity $character, ?bool $islandEnabled = null, ?bool $worldHub = null, ?bool $finalGrid = null, ?bool $gatherOnCompass = null): array
            {
                $out = [];
                foreach (self::DIRECTIONS as $dir => [, , $label]) {
                    $out[] = ['id' => 'move_' . $dir, 'label' => $label, 'callback' => 'move_dir_' . $dir, 'group' => self::GROUP_DIR, 'dir' => $dir];
                }
                $out[] = ['id' => 'base', 'label' => '🏠 База', 'callback' => 'Base', 'group' => self::GROUP_CELL, 'dir' => null];
                $out[] = ['id' => 'march', 'label' => '🗺️ Поход', 'callback' => 'march', 'group' => self::GROUP_NAV, 'dir' => null];
                $out[] = ['id' => 'island', 'label' => '🌍 Остров живёт', 'callback' => 'island', 'group' => self::GROUP_WORLD, 'dir' => null];

                return $out;
            }
        };
    }

    /**
     * Шаг-двойник: на север/восток — успех с событием «рана», на запад — край мира. Хуки шага шлют
     * подсказку с кнопками в чат персонажа, как настоящие (`Request::sendMessage`).
     */
    private function stubMove(): MoveService
    {
        return new class () extends MoveService {
            /** @var list<array{0:int, 1:string, 2:?int}> */
            public array $calls = [];

            public function step(int $characterId, string $dir): array
            {
                $this->calls[] = [$characterId, $dir, null];
                if ($dir === 'west') {
                    return [
                        'ok' => false, 'code' => self::EDGE, 'message' => '🧭 Там край острова — дальше на запад пути нет.', 'from' => null, 'to' => null,
                        'cost' => null, 'events' => [], 'node_sighted' => false, 'character' => null, 'target' => null,
                    ];
                }

                return [
                    'ok' => true, 'code' => self::MOVED, 'message' => null, 'from' => ['x' => 10, 'y' => 10, 'cell' => 1], 'to' => ['x' => 10, 'y' => 9, 'cell' => 2],
                    'cost' => ['health' => 0.1, 'tired' => 3.35], 'node_sighted' => false, 'character' => null, 'target' => [],
                    'events' => [['type' => self::EVENT_DEBUFF, 'text' => '🦴 *Перелом!*', 'buttons' => [['text' => '💊 Аптечка', 'callback_data' => 'pharmacy'], ['text' => '⚕️ Скрафтить', 'callback_data' => 'medicinesCraft1']]]],
                ];
            }

            public function afterStep(array $outcome, int $chatId, bool $coldOpen = true): void
            {
                $last                  = count($this->calls) - 1;
                $this->calls[$last][2] = $chatId;
                \App\Services\Telegram\Request::sendMessage([
                    'chat_id'      => $chatId,
                    'text'         => '💡 Подсказка новичку',
                    'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🏠 База', 'callback_data' => 'Base'], ['text' => '🏗 Строить', 'callback_data' => 'build']]]]),
                ]);
            }
        };
    }

    /**
     * Ядро крафта-двойник: карточка «Повязки» (5 мин, хлопок 14/2, `max_qty` = $max) и очередь — активный
     * ×5 на 10 мин и ожидающий №1 ×2 (строка 12). Старт и отмена пишут вызовы.
     *
     * @return array{0: CraftOrderService, 1: CraftQueueService}
     */
    private function stubCraft(int $max = 7): array
    {
        $orders = new class ($max) extends CraftOrderService {
            /** @var list<array{0:string, 1:int}> */
            public array $starts = [];

            public function __construct(private int $max)
            {
                parent::__construct();
            }

            public function preview(int $characterId, string $recipeKey, int $qty): array
            {
                return [
                    'ok' => $this->max > 0, 'code' => $this->max > 0 ? self::STARTED : self::MISSING_MATERIALS,
                    'message' => $this->max > 0 ? '' : 'Недостаточно ресурсов для крафта 1 шт.',
                    'recipe' => ['key' => $recipeKey, 'name' => 'Повязка', 'icon' => '🩹', 'output_type' => 'item'],
                    'resources' => [['name' => 'Хлопок', 'need' => 2 * $qty, 'have' => 14]], 'items' => [], 'gold' => 0,
                    'minutes_one' => 5, 'minutes_total' => 5 * $qty, 'max_qty' => $this->max, 'queue_pos' => 1, 'gates' => [],
                ];
            }

            public function start(int $characterId, string $recipeKey, int $qty): array
            {
                $this->starts[] = [$recipeKey, $qty];
                $queued         = count($this->starts) > 1;

                return [
                    'ok' => true, 'code' => $queued ? self::QUEUED : self::STARTED, 'message' => '', 'log' => null,
                    'char_task_id' => count($this->starts), 'status' => $queued ? 'queued' : 'in_work', 'started_at' => null, 'ends_at' => null,
                    'minutes_total' => 5 * $qty, 'queue_pos' => count($this->starts), 'background' => true, 'breakdown' => null,
                    'missing_resources' => [], 'missing_items' => [],
                ];
            }
        };
        $queue = new class () extends CraftQueueService {
            /** @var list<int> */
            public array $cancels = [];

            public function forCharacter(int $characterId): array
            {
                return [
                    'active' => [['charTaskId' => 11, 'task_id' => 3, 'recipe' => 'Bandage', 'name' => 'Повязка', 'qty' => 5, 'ends_at' => date('Y-m-d H:i:s', time() + 600), 'seconds_left' => 600]],
                    'queued' => [['charTaskId' => 12, 'task_id' => 3, 'recipe' => 'Bandage', 'name' => 'Повязка', 'qty' => 2, 'position' => 1, 'minutes_total' => 10, 'starts_in_seconds' => 600]],
                ];
            }

            public function cancel(int $characterId, int $charTaskId): array
            {
                $this->cancels[] = $charTaskId;

                return ['ok' => true, 'code' => self::CANCELLED, 'message' => '', 'recipe' => 'Bandage', 'name' => 'Повязка', 'qty' => 2];
            }
        };

        return [$orders, $queue];
    }

    /** Задача «Поход» и legacy-колонка `task_settings` (создающей миграции нет) — для настоящего MarchService. */
    /** Таблицы базы и стройки (миграции там, где пишет чужой код). */
    private function enableBase(): void
    {
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 0');
        $forge = Database::forge();
        foreach ([
            '2024-05-23-061031_CreateClaimedCellsTable', '2024-05-23-090819_CreateBuildingsTable', '2024-05-27-105534_CreateCharacterBuildingsTable',
            '2026-05-29-500000_W3aCreateBaseStorage', '2026-05-08-190000_AddEndgameSystem',
        ] as $file) {
            $this->migration($file, $forge instanceof Forge ? $forge : null)->up();
        }
        $this->conn->query('ALTER TABLE claimed_cells ADD last_visited_at DATETIME NULL, ADD last_warned_at DATETIME NULL, ADD camp_name VARCHAR(64) NULL, ADD camp_flag VARCHAR(16) NULL');
        $this->conn->query('ALTER TABLE character_tasks ADD task_settings TEXT NULL');
        $this->conn->query('ALTER TABLE tasks ADD handler_key VARCHAR(64) NULL');
        $this->conn->query('CREATE TABLE resources (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, name_en VARCHAR(255) NULL, type VARCHAR(255) NULL, rarity INT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->conn->query('CREATE TABLE character_resources (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, id_characters INT UNSIGNED NOT NULL, id_resources INT UNSIGNED NOT NULL, quantity INT NOT NULL DEFAULT 0, custom_data TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
        $this->conn->table('biomes')->insert(['id' => 1, 'name' => 'Лес']);
        foreach ([[100, 10, 10], [200, 20, 20], [300, 15, 15], [400, 40, 40]] as [$cell, $x, $y]) {
            $this->conn->table('map')->insert(['id' => $cell, 'cell_number' => $cell, 'coordinate_x' => $x, 'coordinate_y' => $y, 'biome_id' => 1]);
        }
        foreach ([1 => ['Вышка связи', 'CommunicationTower'], 2 => ['Мастерская', 'Workshop']] as $id => [$ru, $en]) {
            $this->conn->table('buildings')->insert(['id' => $id, 'name_ru' => $ru, 'name_en' => $en, 'building_type' => 'production']);
        }
        $this->conn->table('tasks')->insert(['name' => 'buildWorkshop', 'name_rus' => 'Стройка Мастерской', 'min_duration' => 30, 'max_duration' => 90, 'parallel_execution_allowed' => 1, 'handler_key' => 'generic_building']);
        $this->conn->resetDataCache();
    }

    private function place(int $charId, int $cell): void
    {
        $this->conn->table('characters')->where('id', $charId)->update(['cell_number' => $cell]);
    }

    private function addBase(int $charId, int $cell, ?string $name, ?int $towerLevel): int
    {
        $this->conn->table('claimed_cells')->insert(['character_id' => $charId, 'map_cell_id' => $cell, 'status' => 'active', 'camp_name' => $name, 'claimed_at' => date('Y-m-d H:i:s')]);
        $id = (int) $this->conn->insertID();
        if ($towerLevel !== null) {
            $this->building($charId, 1, $cell, $towerLevel, 10, 1);
        }

        return $id;
    }

    private function building(int $charId, int $buildingId, int $cell, int $level, int $tax, int $amount): void
    {
        $this->conn->table('character_buildings')->insert(['character_id' => $charId, 'building_id' => $buildingId, 'map_cell_id' => $cell, 'level' => $level, 'tax' => $tax, 'amount' => $amount]);
    }

    /** Запас на одну Мастерскую: Wood 1500, Water 800, Clay 400; metalFragments 15, WoodMaterials 14, stoneBlocks 10. */
    private function stockWorkshop(int $charId): void
    {
        foreach ([1 => ['Древесина', 'Wood', 1500], 2 => ['Вода', 'Water', 800], 3 => ['Глина', 'Clay', 400]] as $id => [$name, $en, $qty]) {
            $this->conn->table('resources')->insert(['id' => $id, 'name' => $name, 'name_en' => $en]);
            $this->conn->table('character_resources')->insert(['id_characters' => $charId, 'id_resources' => $id, 'quantity' => $qty]);
        }
        foreach ([1 => ['Металлические фрагменты', 'metalFragments', 15], 2 => ['Деревянные материалы', 'WoodMaterials', 14], 3 => ['Каменные блоки', 'stoneBlocks', 10]] as $id => [$rus, $eng, $qty]) {
            $this->conn->table('crafted_items')->insert(['id' => $id, 'name_rus' => $rus, 'name_eng' => $eng, 'type' => 'component']);
            $this->conn->table('crafted_items_log')->insert(['character_id' => $charId, 'crafted_item_id' => $id, 'type' => 'component', 'quantity' => $qty]);
        }
    }

    /**
     * Мост-двойник экрана базы бота: «🏠 База» текстом — пикер (51) с `Base_b7` и `Camp`; `Base_b7` — экран базы (52)
     * с `construction_b7`; `construction_b7` — постройки (53) с карточкой `building_2_Workshop_b7`.
     */
    private function baseAct(): WebActService
    {
        return new class () extends WebActService {
            /** @var list<array<string, mixed>> */
            public array $calls = [];

            public function act(int $accountId, int $characterId, array $intent): array
            {
                $this->calls[] = $intent;
                [$mid, $kb] = match ($intent['data'] ?? null) {
                    'Base_b7'         => [52, [[['text' => '🏘 Постройки', 'callback_data' => 'construction_b7']]]],
                    'construction_b7' => [53, [[['text' => '🔧 Мастерская L1', 'callback_data' => 'building_2_Workshop_b7']]]],
                    default           => [51, [[['text' => '🏠 Первая', 'callback_data' => 'Base_b7'], ['text' => '🏕 Разбить лагерь', 'callback_data' => 'Camp']]]],
                };
                $msg = ['message_id' => $mid, 'text' => 'База', 'caption' => null, 'parse_mode' => null, 'photo_url' => null, 'inline_keyboard' => $kb];

                return ['state' => ['screen' => [$msg], 'history' => [], 'dock' => [['🧑 Я']], 'input' => null], 'alert' => null, 'unread' => 0];
            }

            public function current(int $characterId): array
            {
                return ['state' => ['screen' => [], 'history' => [], 'dock' => [['🧑 Я']], 'input' => null], 'alert' => null, 'unread' => 0];
            }
        };
    }

    private function enableMarch(): void
    {
        $this->conn->query('ALTER TABLE character_tasks ADD task_settings TEXT NULL');
        $this->conn->query("ALTER TABLE character_tasks MODIFY COLUMN status ENUM('in_work','completed','interrupted','queued','paused') NOT NULL DEFAULT 'in_work'");
        $this->conn->table('tasks')->insert(['name' => 'Marching', 'name_rus' => 'Поход', 'parallel_execution_allowed' => 0]);
        $this->conn->resetDataCache();
    }

    /**
     * Сохранённый экран моста с доком — `/play` не делает первый вход.
     *
     * @param list<list<string>> $dock
     */
    private function seedScreen(int $characterId, array $dock = [['🧑 Я', '🏠 База']]): void
    {
        $this->conn->table('web_play_state')->insert([
            'character_id' => $characterId,
            'screen'       => json_encode([['message_id' => 1000000000, 'text' => 'Экран', 'caption' => null, 'parse_mode' => null, 'photo_url' => null, 'inline_keyboard' => []]], JSON_UNESCAPED_UNICODE),
            'history'      => '[]',
            'dock'         => json_encode($dock, JSON_UNESCAPED_UNICODE),
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
