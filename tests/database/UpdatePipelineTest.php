<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Controllers\Telegram\BotController;
use App\Services\GameSettings\GameSettingsService;
use App\Services\Logging\ActionOrigin;
use App\Services\Logging\PlayerActionLogger;
use App\Services\Logging\TelegramDeliveryProbe;
use App\Services\Player\ReturnDigestService;
use App\Services\Quest\DailyTaskService;
use App\Services\Telegram\UpdatePipeline;
use App\Services\Web\AccountService;
use App\Services\Web\DeliveryContext;
use App\Services\Web\SyntheticUpdateFactory;
use App\Services\Web\VirtualChat;
use App\Services\Web\VirtualIdentityService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Longman\TelegramBot\Request as LongmanRequest;
use Longman\TelegramBot\Telegram;
use Psr\Http\Message\RequestInterface;

/**
 * Спай вебхука: тот же seam `dispatchToTelegram()`, что у `BotController*Test`.
 */
final class PipelineSpyBotController extends BotController
{
    public int $dispatchCalls = 0;

    protected function dispatchToTelegram(): void
    {
        $this->dispatchCalls++;
    }
}

/** ReturnDigest с включённым killswitch; фиксирует, дошёл ли вызов до резолва персонажа. */
final class ProbeReturnDigestService extends ReturnDigestService
{
    /** @var list<int> */
    public array $resolved = [];

    protected function enabled(): bool
    {
        return true;
    }

    protected function resolveContext(int $telegramId): ?array
    {
        $this->resolved[] = $telegramId;

        return null;
    }
}

/** DailyTask с включённым killswitch; фиксирует назначение набора. */
final class ProbeDailyTaskService extends DailyTaskService
{
    /** @var list<int> */
    public array $assigned = [];

    public function enabled(): bool
    {
        return true;
    }

    public function ensureAssigned(array|\App\Entities\CharacterEntity $character): bool
    {
        $this->assigned[] = is_array($character) && is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;

        return false;
    }
}

/**
 * web-bridge-p1-05 (ADR-189 §1) — {@see UpdatePipeline}: вебхук после выноса тела ведёт себя как
 * раньше (Ask 5); синтетический апдейт персонажа с виртуальной строкой доходит до настоящего
 * диспетчера Longman и пишет строку firehose `source=web` (Ask 1, Ask 6); актёр доставки живёт
 * ровно один апдейт, даже при исключении.
 *
 * Схема — исполнением настоящих миграций.
 *
 * @internal
 */
final class UpdatePipelineTest extends CIUnitTestCase
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
        '2026-05-08-220000_AddDisableMediaFlag',
        '2026-05-19-100000_CreateGameSettingsTable',
        '2026-05-22-330000_TipsCAddDailyToggle',
        '2026-07-17-100000_E6AddLastSeenToTelegramUsers',
        '2026-07-19-100000_E6AddLoginStreakToCharacters',
        '2026-07-20-100000_E6LoginStreakGameSettings',
        '2026-09-02-120000_Adr181CreateTelegramUpdatesSeen',
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

    private const TABLES = [
        'biomes', 'map', 'telegram_users', 'accounts', 'account_identities', 'account_tokens', 'account_link_codes',
        'characters', 'action_log', 'tasks', 'character_tasks', 'explored_cells', 'game_settings', 'player_action_log',
        'telegram_updates_seen',
    ];

    private const REAL_TG = 555000333;

    private BaseConnection $conn;

    /** @var list<string> методы Bot API, дошедшие до заглушки сети */
    private array $sentMethods = [];

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
        service('cache')->clean();
        PlayerActionLogger::reset();
        ActionOrigin::reset();
        DeliveryContext::reset();
    }

    protected function tearDown(): void
    {
        PlayerActionLogger::reset();
        ActionOrigin::reset();
        DeliveryContext::reset();
        TelegramDeliveryProbe::reset();
        service('cache')->clean();
        $this->dropTables();
        parent::tearDown();
    }

    // ── Ask 5: вебхук как раньше ─────────────────────────────────────────

    public function testWebhookTelegramUpdateWritesSameFirehoseRowAndLastSeenStamp(): void
    {
        $tgUser = $this->makeTelegramUser(self::REAL_TG);
        $charId = $this->makeCharacter($tgUser);

        $controller = $this->controllerFor([
            'update_id'      => 920001,
            'callback_query' => [
                'id'            => '1',
                'from'          => ['id' => self::REAL_TG, 'is_bot' => false, 'first_name' => 'Т'],
                'chat_instance' => '1',
                'data'          => 'guide~cmp',
                'message'       => ['message_id' => 5, 'date' => 1, 'chat' => ['id' => self::REAL_TG, 'type' => 'private'], 'text' => 'x'],
            ],
        ]);
        $controller->webhook();

        $this->assertSame(1, $controller->dispatchCalls);
        $log = $this->row('player_action_log', ['telegram_user_id' => self::REAL_TG]);
        $this->assertSame('callback', $log['source']);
        $this->assertSame('guide', $log['action_name']);
        $this->assertSame('guide', $log['raw_input'], 'метка ADR-168 снята до firehose');
        $this->assertSame('cmp', $log['origin']);
        $this->assertSame('ok', $log['status']);
        $this->assertSame($charId, (int) $log['character_id']);
        $this->assertNotNull($this->row('telegram_users', ['id' => $tgUser])['last_seen']);
        $this->assertSame(1, $this->rowCount('telegram_updates_seen', ['update_id' => 920001]));
        $this->assertNull(DeliveryContext::actor());
    }

    public function testTelegramSourceStillRethrowsNonTelegramErrorsAndResetsActor(): void
    {
        $this->makeCharacter($this->makeTelegramUser(self::REAL_TG));
        $seen     = null;
        $pipeline = new UpdatePipeline(null, static function () use (&$seen): void {
            $seen = DeliveryContext::actor();

            throw new \RuntimeException('boom');
        });

        try {
            $pipeline->run($this->textUpdate(self::REAL_TG, 'база'), UpdatePipeline::SOURCE_TELEGRAM);
            $this->fail('источник telegram обязан пробросить не-Telegram исключение, как прежний вебхук');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(self::REAL_TG, $seen);
        $this->assertNull(DeliveryContext::actor());
        $this->assertSame('error', $this->row('player_action_log', ['telegram_user_id' => self::REAL_TG])['status']);
    }

    // ── Ask 1 / Ask 6: синтетика через настоящий диспетчер ────────────────

    public function testWebCallbackReachesRealDispatcherAndLogsWebRowWithOrigin(): void
    {
        [$identity] = $this->virtualCharacter();
        $update     = (new SyntheticUpdateFactory())->callback(
            $identity,
            $this->screen(1000000001),
            'guide~cmp',
            -101
        );

        $this->assertTrue($this->realPipeline()->run($update, UpdatePipeline::SOURCE_WEB));

        $this->assertNull(DeliveryContext::actor());
        $log = $this->row('player_action_log', ['telegram_user_id' => $identity['telegram_id']]);
        $this->assertSame('web', $log['source']);
        $this->assertSame('guide', $log['action_name']);
        $this->assertSame('ok', $log['status'], 'callback_data из CallbackRoutes дошёл до GuideAction, не unrouted');
        $this->assertSame('cmp', $log['origin']);
        // Экран виртуального чата уходит во входящие, не в сеть; до сети может дойти лишь chat-less
        // answerCallbackQuery (и то не всегда: при PHPUNIT_TESTSUITE Longman отвечает фейком сам).
        $this->assertSame([], array_values(array_diff($this->sentMethods, ['answerCallbackQuery'])));
        $this->assertSame(0, $this->rowCount('telegram_updates_seen', []), 'веб-апдейт не пишется в дедуп вебхука');
    }

    public function testWebTextMessageRoutesThroughGenericMessageForVirtualCharacter(): void
    {
        [$identity, $charId] = $this->virtualCharacter();
        $update              = (new SyntheticUpdateFactory())->message($identity, 'media_off', null, -102);

        $this->assertTrue($this->realPipeline()->run($update, UpdatePipeline::SOURCE_WEB));

        $log = $this->row('player_action_log', ['telegram_user_id' => $identity['telegram_id']]);
        $this->assertSame('web', $log['source']);
        $this->assertSame('media_off', $log['action_name']);
        $this->assertSame('ok', $log['status']);
        $this->assertSame($charId, (int) $log['character_id']);
        $this->assertSame(1, (int) $this->row('characters', ['id' => $charId])['disable_media'], 'резолв from.id → виртуальная строка → персонаж');
    }

    public function testWebCommandRoutesToCommandClass(): void
    {
        [$identity] = $this->virtualCharacter();
        $update     = (new SyntheticUpdateFactory())->message($identity, '/guide', null, -103);

        $this->assertTrue($this->realPipeline()->run($update, UpdatePipeline::SOURCE_WEB));

        $log = $this->row('player_action_log', ['telegram_user_id' => $identity['telegram_id']]);
        $this->assertSame('web', $log['source']);
        $this->assertSame('guide', $log['action_name']);
        $this->assertSame('ok', $log['status']);
    }

    public function testWebRunStampsLastSeenAndRunsLoginStreakForVirtualId(): void
    {
        $this->assertTrue((new GameSettingsService())->set('returnability.streak.enabled', true));
        [$identity, $charId] = $this->virtualCharacter();

        $this->realPipeline(static function (): void {
        })->run((new SyntheticUpdateFactory())->message($identity, 'база', null, -104), UpdatePipeline::SOURCE_WEB);

        $tu = $this->row('telegram_users', ['telegram_id' => $identity['telegram_id']]);
        $this->assertNotNull($tu['last_seen'], 'last_seen проставлен виртуальному telegram_id');
        $char = $this->row('characters', ['id' => $charId]);
        $this->assertSame(date('Y-m-d'), $char['login_streak_last_day'], 'хук E6 LoginStreak отработал для виртуального id');
        $this->assertSame(1, (int) $char['login_streak']);
    }

    public function testReturnDigestAndDailyTaskGuardsAcceptVirtualIdAndStillRejectGroups(): void
    {
        [$identity, $charId] = $this->virtualCharacter();
        $virtual             = $identity['telegram_id'];

        $digest = new ProbeReturnDigestService();
        $digest->maybeSendDigest($virtual, $virtual);
        $digest->maybeSendDigest(-1001234567890, -1001234567890);
        $this->assertSame([$virtual], $digest->resolved);

        $daily = new ProbeDailyTaskService();
        try {
            $daily->ensureForTelegramUser($virtual, $virtual);
        } catch (\Throwable) {
            // интро-подсказка после назначения набора — вне схемы этого теста; важен сам проход гарда
        }
        $daily->ensureForTelegramUser(-1001234567890, -1001234567890);
        $this->assertSame([$charId], $daily->assigned);
    }

    public function testActorIsUpdateChatDuringDispatchAndNullAfter(): void
    {
        [$identity] = $this->virtualCharacter();
        $seenActor  = null;

        $this->realPipeline(static function () use (&$seenActor): void {
            $seenActor = DeliveryContext::actor();
        })->run((new SyntheticUpdateFactory())->message($identity, 'база', null, -106), UpdatePipeline::SOURCE_WEB);

        $this->assertSame($identity['telegram_id'], $seenActor);
        $this->assertNull(DeliveryContext::actor());
    }

    public function testWebRunNeverThrowsAndResetsActorWhenHandlerThrows(): void
    {
        [$identity] = $this->virtualCharacter();
        $update     = (new SyntheticUpdateFactory())->message($identity, 'база', null, -105);

        $ok = $this->realPipeline(static function (): void {
            throw new \TypeError('handler blew up');
        })->run($update, UpdatePipeline::SOURCE_WEB);

        $this->assertFalse($ok);
        $this->assertNull(DeliveryContext::actor());
        $this->assertNull(ActionOrigin::current());
        $log = $this->row('player_action_log', ['telegram_user_id' => $identity['telegram_id']]);
        $this->assertSame('error', $log['status']);
        $this->assertSame('web', $log['source']);
    }

    // ── Фикстура ─────────────────────────────────────────────────────────

    /** @param (callable(array<array-key, mixed>|null): void)|null $dispatch */
    private function realPipeline(?callable $dispatch = null): UpdatePipeline
    {
        $telegram = new Telegram('123456:TEST_TOKEN', 'wildworldtest_bot');
        $telegram->addCommandsPath(APPPATH . 'Controllers/Telegram/Commands');

        // Сеть закрыта: probe ставится заранее (run() его уже не переставит), затем клиент Longman —
        // заглушка «ok». Запросы без chat_id (answerCallbackQuery) иначе ушли бы в api.telegram.org;
        // в /play их перехватывает BridgeClient (story 07), здесь — этот двойник.
        TelegramDeliveryProbe::install();
        $this->sentMethods = [];
        LongmanRequest::setClient(new Client(['handler' => function (RequestInterface $request): PromiseInterface {
            $this->sentMethods[] = basename($request->getUri()->getPath());

            return Create::promiseFor(new Response(200, [], '{"ok":true,"result":true}'));
        }]));

        return new UpdatePipeline($telegram, $dispatch);
    }

    /** @return array{0: array{telegram_user_id:int, telegram_id:int, first_name:string, username:?string, language_code:?string, virtual:bool}, 1: int} */
    private function virtualCharacter(): array
    {
        $accountId = (new AccountService($this->conn))->createAccount('web');
        $tgUser    = (new VirtualIdentityService($this->conn))->ensureForAccount($accountId, 'Странник');
        $charId    = $this->makeCharacter($tgUser, $accountId);
        $identity  = (new VirtualIdentityService($this->conn))->identityForCharacter($charId);
        $this->assertNotNull($identity);
        $this->assertTrue(VirtualChat::is($identity['telegram_id']));

        return [$identity, $charId];
    }

    /** @return array{message_id:int, text:?string, caption:?string, parse_mode:?string, photo_url:?string, inline_keyboard:list<list<array{text:string, callback_data?:string, url?:string}>>} */
    private function screen(int $messageId): array
    {
        return [
            'message_id' => $messageId, 'text' => 'Экран', 'caption' => null, 'parse_mode' => 'Markdown', 'photo_url' => null,
            'inline_keyboard' => [[['text' => '📖 Путь новичка', 'callback_data' => 'guide~cmp']]],
        ];
    }

    /** @return array<string, mixed> */
    private function textUpdate(int $tg, string $text): array
    {
        return [
            'update_id' => 920002,
            'message'   => [
                'message_id' => 10, 'date' => 1, 'text' => $text,
                'from'       => ['id' => $tg, 'is_bot' => false, 'first_name' => 'Т'],
                'chat'       => ['id' => $tg, 'type' => 'private'],
            ],
        ];
    }

    /** @param array<string, mixed> $update */
    private function controllerFor(array $update): PipelineSpyBotController
    {
        $request = $this->createMock(IncomingRequest::class);
        $request->method('getBody')->willReturn(json_encode($update));

        $controller = new PipelineSpyBotController();
        $controller->initController($request, service('response', null, false), service('logger'));

        return $controller;
    }

    private function makeCharacter(int $telegramUserId, ?int $accountId = null): int
    {
        $this->conn->table('characters')->insert([
            'telegram_user_id' => $telegramUserId, 'account_id' => $accountId, 'name' => 'Странник',
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
