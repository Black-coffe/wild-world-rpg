<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Filters\AccountThrottleFilter;
use App\Services\Logging\ActionOrigin;
use App\Services\Logging\PlayerActionLogger;
use App\Services\Logging\TelegramDeliveryProbe;
use App\Services\Web\AccountService;
use App\Services\Web\AccountSession;
use App\Services\Web\DeliveryContext;
use App\Services\Web\VirtualIdentityService;
use App\Services\Web\WebActService;
use App\Services\Web\WebDelivery;
use App\Services\Web\WebInboxService;
use App\Services\Web\WebScreenStore;
use CodeIgniter\Config\Factories;
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
use RuntimeException;

/**
 * web-onboarding-03 — плашка нового сообщения над экраном `/play` и подхват новой ревизии экрана
 * опросом `/play/inbox`.
 *
 * Схема — исполнением настоящих миграций; сеть — заглушка за клиентом Probe.
 *
 * @internal
 */
final class PlayInboxBannerTest extends CIUnitTestCase
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

    /** Таблица, которую читает `site/layout.php` (навигация); создаётся, только если её нет. */
    private const SITE_MIGRATION = '2026-05-25-180000_CreateSiteCategoriesTable';

    private BaseConnection $conn;

    /** Эта таблица создана этим тестом — и только тогда он её сносит. */
    private bool $createdSiteCategories = false;

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


    // ── Выборка плашки ───────────────────────────────────────────────────

    public function testBannerSelectionLimitsToNewestExcludesScreenAndReadOnes(): void
    {
        [, $charId] = $this->virtualCharacter();
        $inbox      = new WebInboxService($this->conn);
        foreach ([1, 2, 3, 4, 5] as $id) {
            $inbox->append($charId, $this->msg($id, "Сообщение {$id}"), 'virtual');
        }

        $all = $inbox->banner($charId, [], 3);
        $this->assertSame([5, 4, 3], array_column($all['items'], 'message_id'), 'новейшие, лимит bannerMax');
        $this->assertSame(2, $all['more']);

        $skip = $inbox->banner($charId, [5], 3);
        $this->assertSame([4, 3, 2], array_column($skip['items'], 'message_id'), 'экранное сообщение исключено');
        $this->assertSame(1, $skip['more']);

        $inbox->markAllRead($charId);
        $this->assertSame(['items' => [], 'more' => 0], $inbox->banner($charId, [], 3), 'прочитанные не идут');
        $this->assertSame(3, (new WebPlay())->bannerMax);
    }

    public function testUpsertEditUpdatesTheSameBannerCard(): void
    {
        [, $charId] = $this->virtualCharacter();
        $inbox      = new WebInboxService($this->conn);
        $inbox->append($charId, $this->msg(7, 'Старый текст'), 'virtual');
        $inbox->markAllRead($charId);
        $inbox->upsertEdit($charId, 7, $this->msg(7, 'Новый текст'), 'virtual');

        $banner = $inbox->banner($charId, [], 3);
        $this->assertCount(1, $banner['items']);
        $this->assertSame('Новый текст', $banner['items'][0]['text']);
    }

    // ── Страница без JS ──────────────────────────────────────────────────

    public function testPlayRendersBannerWithFullMessageAndButtonsWithoutJs(): void
    {
        [$session, $charId] = $this->virtualCharacter();
        $this->withSession($session)->get('play')->assertStatus(200);
        $inbox = new WebInboxService($this->conn);
        $msg   = $this->msg(41, 'x');
        // Фото без файла (media-off): весь смысл — в подписи.
        $msg['text']            = null;
        $msg['caption']         = 'Шаг закрыт, награда 20 золота. Следующий шаг — собери воду.';
        $msg['photo_url']       = '/uploads/web/missing.jpg';
        $msg['inline_keyboard'] = [[['text' => 'Дальше', 'callback_data' => 'step:next']]];
        $inbox->append($charId, $msg, 'virtual');

        $html = $this->body($this->withSession($session)->get('play'));
        $this->assertStringContainsString('id="play-banner"', $html);
        $this->assertStringContainsString('Шаг закрыт, награда 20 золота. Следующий шаг — собери воду.', $html);
        $this->assertStringNotContainsString('missing.jpg', $html, 'нет файла — одна подпись');
        $this->assertStringContainsString('name="message_id" value="41"', $html, 'кнопка идёт через /play/act с message_id');
        $this->assertMatchesRegularExpression('/name="data" value="step(:|&#x3A;)next"/', $html);
        $this->assertStringContainsString('✕ Скрыть', $html);
    }

    public function testBannerOverLimitShowsMoreLine(): void
    {
        [$session, $charId] = $this->virtualCharacter();
        $this->withSession($session)->get('play')->assertStatus(200);
        $inbox = new WebInboxService($this->conn);
        foreach ([1, 2, 3, 4, 5] as $id) {
            $inbox->append($charId, $this->msg($id, "Входящее {$id}"), 'virtual');
        }

        $html = $this->body($this->withSession($session)->get('play'));
        $this->assertStringContainsString('ещё 2 — во входящих 🔔', $html);
        $this->assertStringContainsString('Входящее 5', $html);
        $this->assertStringNotContainsString('Входящее 1<', $html);
    }

    public function testBannerMessagesAreCollapsedToOneLineSummary(): void
    {
        [$session, $charId] = $this->virtualCharacter();
        $this->withSession($session)->get('play')->assertStatus(200);
        $inbox = new WebInboxService($this->conn);

        $marked               = $this->msg(51, "\n*Урожай созрел* на базе\nВторая строка про грядки");
        $marked['parse_mode'] = 'Markdown';
        $inbox->append($charId, $marked, 'virtual');

        $photoOnly              = $this->msg(52, '');
        $photoOnly['text']      = null;
        $photoOnly['photo_url'] = '/uploads/web/missing.jpg';
        $inbox->append($charId, $photoOnly, 'virtual');

        $html = $this->body($this->withSession($session)->get('play'));
        $this->assertStringContainsString('<details class="play-banner-item" data-message-id="51">', $html);
        $this->assertMatchesRegularExpression('~<summary class="play-banner-summary"><span class="play-banner-line">Урожай созрел на базе</span></summary>~u', $html, 'первая непустая строка без разметки');
        $this->assertStringContainsString('Вторая строка про грядки', $html, 'полный текст под строкой, без JS');
        $this->assertMatchesRegularExpression('~data-message-id="52">\s*<summary class="play-banner-summary"><span class="play-banner-line">Сообщение</span>~u', $html, 'ни текста, ни подписи — «Сообщение»');
    }

    public function testMessageAlreadyOnScreenIsNotInBanner(): void
    {
        [$session, $charId] = $this->virtualCharacter();
        $this->withSession($session)->get('play')->assertStatus(200);
        $onScreen = (new WebScreenStore($this->conn))->screenMessageIds($charId);
        $this->assertNotSame([], $onScreen);
        (new WebInboxService($this->conn))->append($charId, $this->msg($onScreen[0], 'Дубль экрана УНИКАЛЬНЫЙ'), 'virtual');

        $json = json_decode($this->body($this->withSession($session)->get('play/inbox')), true);
        $this->assertIsArray($json);
        $this->assertStringNotContainsString('Дубль экрана УНИКАЛЬНЫЙ', $json['banner_html']);
    }

    // ── Опрос ────────────────────────────────────────────────────────────

    public function testPollCarriesBannerRevAndScreenOnlyOnRevMismatch(): void
    {
        [$session, $charId] = $this->virtualCharacter();
        $this->withSession($session)->get('play')->assertStatus(200);
        (new WebInboxService($this->conn))->append($charId, $this->msg(90, 'Новое с крона'), 'virtual');
        $rev = (new WebScreenStore($this->conn))->revision($charId);

        $same = json_decode($this->body($this->withSession($session)->get('play/inbox?rev=' . $rev)), true);
        $this->assertIsArray($same);
        $this->assertSame($rev, $same['rev']);
        $this->assertStringContainsString('Новое с крона', $same['banner_html']);
        $this->assertArrayNotHasKey('screen_html', $same, 'ревизия та же — экран не отдаётся');

        $stale = json_decode($this->body($this->withSession($session)->get('play/inbox?rev=' . ($rev + 5))), true);
        $this->assertIsArray($stale);
        $this->assertIsString($stale['screen_html']);
        $this->assertStringContainsString('play-input-form', $stale['screen_html']);
    }

    public function testHideMarksReadAndBannerDoesNotComeBack(): void
    {
        [$session, $charId] = $this->virtualCharacter();
        $this->withSession($session)->get('play')->assertStatus(200);
        (new WebInboxService($this->conn))->append($charId, $this->msg(91, 'Скрой меня'), 'virtual');
        $this->assertStringContainsString('Скрой меня', $this->body($this->withSession($session)->get('play')));

        $this->postWithCsrf($session, 'play/inbox/read', [], true)->assertStatus(200);

        $this->assertStringNotContainsString('Скрой меня', $this->body($this->withSession($session)->get('play')));
        $json = json_decode($this->body($this->withSession($session)->get('play/inbox')), true);
        $this->assertIsArray($json);
        $this->assertStringNotContainsString('Скрой меня', $json['banner_html']);
    }

    public function testHideWithoutJsRedirectsBackToPlay(): void
    {
        [$session] = $this->virtualCharacter();
        $res       = $this->postWithCsrf($session, 'play/inbox/read', []);

        $this->assertSame(303, $res->response()->getStatusCode());
        $this->assertStringEndsWith('/play', $res->response()->getHeaderLine('Location'));
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
        if ($this->createdSiteCategories) {
            $this->conn->query('DROP TABLE IF EXISTS `site_categories`');
            $this->createdSiteCategories = false;
        }
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
        $this->conn->resetDataCache();
    }
}
