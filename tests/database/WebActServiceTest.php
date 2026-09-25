<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Logging\ActionOrigin;
use App\Services\Logging\PlayerActionLogger;
use App\Services\Logging\TelegramDeliveryProbe;
use App\Services\Telegram\UpdatePipeline;
use App\Services\Web\AccountService;
use App\Services\Web\DeliveryContext;
use App\Services\Web\VirtualChat;
use App\Services\Web\VirtualIdentityService;
use App\Services\Web\WebActService;
use App\Services\Web\WebDelivery;
use App\Services\Web\WebScreenStore;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Config\WebPlay;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Longman\TelegramBot\Request as LongmanRequest;
use Longman\TelegramBot\Telegram;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Обёртка клиента Longman на время конвейера: записывает КАЖДЫЙ вызов, дошедший до
 * `parent::send()` (Longman → `$client->post()`), до того как BridgeClient что-либо захватит.
 */
final class ActRecordingClient implements ClientInterface
{
    /** @var list<array{method:string, chat_id:?string}> */
    public static array $calls = [];

    public function __construct(private ClientInterface $inner)
    {
    }

    /** @param array<int,mixed> $args */
    public function __call(string $method, array $args): mixed
    {
        $options = is_array($args[1] ?? null) ? $args[1] : [];
        $chat    = null;
        foreach (['form_params', 'multipart'] as $kind) {
            $fields = is_array($options[$kind] ?? null) ? $options[$kind] : [];
            foreach ($fields as $k => $v) {
                if ($k === 'chat_id' && is_scalar($v)) {
                    $chat = (string) $v;
                }
                if (is_array($v) && ($v['name'] ?? null) === 'chat_id' && is_scalar($v['contents'] ?? null)) {
                    $chat = (string) $v['contents'];
                }
            }
        }
        $uri           = $args[0] ?? '';
        self::$calls[] = ['method' => basename(is_string($uri) ? $uri : ''), 'chat_id' => $chat];

        return $this->inner->{$method}(...$args);
    }

    public function send(RequestInterface $request, array $options = []): ResponseInterface
    {
        return $this->inner->send($request, $options);
    }

    public function sendAsync(RequestInterface $request, array $options = []): PromiseInterface
    {
        return $this->inner->sendAsync($request, $options);
    }

    public function request(string $method, $uri = '', array $options = []): ResponseInterface
    {
        return $this->inner->request($method, $uri, $options);
    }

    public function requestAsync(string $method, $uri = '', array $options = []): PromiseInterface
    {
        return $this->inner->requestAsync($method, $uri, $options);
    }

    public function getConfig(?string $option = null)
    {
        return $this->inner->getConfig($option);
    }
}

/** Шов конвейера: оборачивает клиент моста записывающим (или бросает — для R4). */
final class SpyWebActService extends WebActService
{
    public ?\Throwable $throw = null;

    /** @var ClientInterface|null клиент Longman, который видел конвейер (BridgeClient) */
    public ?ClientInterface $seenClient = null;

    protected function runPipeline(UpdatePipeline $pipeline, array $update): bool
    {
        $bridge           = WebActServiceTest::longmanClient();
        $this->seenClient = $bridge;
        if ($this->throw !== null) {
            DeliveryContext::setActor(424242);

            throw $this->throw;
        }
        if ($bridge !== null) {
            LongmanRequest::setClient(new ActRecordingClient($bridge));
        }
        try {
            return parent::runPipeline($pipeline, $update);
        } finally {
            if ($bridge !== null) {
                LongmanRequest::setClient($bridge);
            }
        }
    }
}

/**
 * web-bridge-p1-07 (ADR-189 §1, §2, §6) — {@see WebActService}: действия привязанного и
 * виртуального персонажа идут через настоящий конвейер и меняют экран (Ask 1), белый список,
 * дедуп и личность только из сессии (Ask 6), транспорт восстановлен и при падении (R4).
 *
 * Схема — исполнением настоящих миграций.
 *
 * @internal
 */
final class WebActServiceTest extends CIUnitTestCase
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

    private const REAL_TG = 555000777;

    private BaseConnection $conn;

    private ClientInterface $probeClient;

    /** @var list<string> методы Bot API, дошедшие до сети (заглушки делегата) */
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
        service('cache')->clean();
        PlayerActionLogger::reset();
        ActionOrigin::reset();
        DeliveryContext::reset();
        WebDelivery::reset();
        ActRecordingClient::$calls = [];
        $this->installProbeStub();
    }

    protected function tearDown(): void
    {
        PlayerActionLogger::reset();
        ActionOrigin::reset();
        DeliveryContext::reset();
        WebDelivery::reset();
        TelegramDeliveryProbe::reset();
        service('cache')->clean();
        $this->dropTables();
        parent::tearDown();
    }

    // ── Ask 1: кнопка, док-текст и команда через конвейер, экран меняется ─────

    public function testLinkedAndVirtualActsDispatchAndChangeTheStoredScreen(): void
    {
        foreach (['linked' => $this->linkedCharacter(), 'virtual' => $this->virtualCharacter()] as $label => [$accountId, $charId, $chat]) {
            ActRecordingClient::$calls = [];
            $svc   = $this->service();
            $store = new WebScreenStore($this->conn);

            // Команда: отправка → экран.
            $r1 = $svc->act($accountId, $charId, ['intent_id' => "{$label}-1", 'kind' => 'command', 'data' => '/guide']);
            $this->assertCount(1, $r1['state']['screen'], "{$label}: /guide дал экран");
            $guide = $r1['state']['screen'][0];
            $this->assertSame([], $r1['state']['history']);
            $data = $this->firstCallback($guide);

            // Кнопка со своего экрана: правка → тот же message_id, новый текст, история не растёт.
            $r2 = $svc->act($accountId, $charId, [
                'intent_id' => "{$label}-2", 'kind' => 'callback', 'data' => $data, 'message_id' => (string) $guide['message_id'],
            ]);
            $this->assertCount(1, $r2['state']['screen']);
            $this->assertSame($guide['message_id'], $r2['state']['screen'][0]['message_id'], "{$label}: правка заменила сообщение");
            $this->assertNotSame($guide['text'], $r2['state']['screen'][0]['text'], "{$label}: текст экрана сменился");
            $this->assertSame([], $r2['state']['history'], "{$label}: правка не пушит историю");

            // Текст дока: отправка → новый экран, прежний уходит в историю.
            $r3 = $svc->act($accountId, $charId, ['intent_id' => "{$label}-3", 'kind' => 'text', 'data' => 'media_off']);
            $this->assertNotSame($guide['message_id'], $r3['state']['screen'][0]['message_id']);
            $this->assertCount(1, $r3['state']['history'], "{$label}: отправка пушит историю");
            $this->assertSame($guide['message_id'], $r3['state']['history'][0][0]['message_id']);
            $this->assertSame(1, (int) $this->row('characters', ['id' => $charId])['disable_media'], "{$label}: хендлер отработал");
            $this->assertSame($r3['state'], $store->state($charId), "{$label}: экран сохранён");

            $rows = $this->conn->table('player_action_log')->where('character_id', $charId)->orderBy('id')->get()->getResultArray();
            $this->assertSame(['guide', 'guide', 'media_off'], array_column($rows, 'action_name'), $label);
            $this->assertSame(['web', 'web', 'web'], array_column($rows, 'source'), $label);

            // Ничего актёра не дошло до parent::send() (Longman → клиент), и в сеть не ушло ничего.
            $this->assertNotNull($svc->seenClient);
            $this->assertSame([], ActRecordingClient::$calls, "{$label}: parent::send не достигнут");
            $this->assertSame([], $this->network, "{$label}: сеть не тронута");
            $this->assertSame(0, $this->conn->table('web_inbox')->where('character_id', $charId)->countAllResults());
            $this->assertIsInt($chat);
        }
    }

    // ── Ask 6: белый список, дедуп, личность из персонажа ──────────────────

    public function testCallbackNotOnTheCharacterScreensIsRejectedWithoutDispatch(): void
    {
        [$accountId, $charId] = $this->virtualCharacter();
        $svc                  = $this->service();
        $r1                   = $svc->act($accountId, $charId, ['intent_id' => 'a', 'kind' => 'command', 'data' => '/guide']);
        $messageId            = (string) $r1['state']['screen'][0]['message_id'];
        $before               = $this->conn->table('player_action_log')->countAllResults();

        foreach ([
            ['intent_id' => 'b', 'kind' => 'callback', 'data' => 'admin_give_gold', 'message_id' => $messageId],
            ['intent_id' => 'c', 'kind' => 'callback', 'data' => $this->firstCallback($r1['state']['screen'][0]), 'message_id' => '999'],
            ['intent_id' => 'd', 'kind' => 'callback', 'data' => $this->firstCallback($r1['state']['screen'][0])],
            ['intent_id' => 'e', 'kind' => 'command', 'data' => 'guide'],
            ['intent_id' => 'f', 'kind' => 'text', 'data' => str_repeat('я', 4097)],
            ['intent_id' => 'g', 'kind' => 'shell', 'data' => '/guide'],
            ['kind' => 'command', 'data' => '/guide'],
        ] as $i => $intent) {
            try {
                $svc->act($accountId, $charId, $intent);
                $this->fail("намерение #{$i} должно быть отвергнуто");
            } catch (\InvalidArgumentException) {
                // ожидаемо
            }
        }

        $this->assertSame($before, $this->conn->table('player_action_log')->countAllResults(), 'нет строки firehose');
        $this->assertSame(1, $this->conn->table('web_play_intents')->countAllResults(), 'отказ не занимает намерение');
    }

    /** #5: кнопка принадлежит своему сообщению — чужая пара (data, message_id) отвергается. */
    public function testCallbackIsAcceptedOnlyOnTheMessageThatCarriesIt(): void
    {
        [$accountId, $charId] = $this->virtualCharacter();
        $svc                  = $this->service();
        $r1                   = $svc->act($accountId, $charId, ['intent_id' => 'm-1', 'kind' => 'command', 'data' => '/guide']);
        $m1                   = $r1['state']['screen'][0];
        $d1                   = $this->firstCallback($m1);
        $m2                   = $this->msg($m1['message_id'] + 1, 'web_test_d2');
        $this->seedScreen($charId, [$m1, $m2]);
        $inboxWithD2 = $this->msg($m1['message_id'] + 500, 'web_test_d2');
        $inboxWithD1 = $this->msg($m1['message_id'] + 501, $d1);
        $this->seedInbox($charId, $inboxWithD2);
        $this->seedInbox($charId, $inboxWithD1);

        foreach (['screen' => $m2['message_id'], 'inbox' => $inboxWithD2['message_id']] as $where => $foreignId) {
            $logs    = $this->conn->table('player_action_log')->countAllResults();
            $intents = $this->conn->table('web_play_intents')->countAllResults();

            try {
                $svc->act($accountId, $charId, ['intent_id' => "x-{$where}", 'kind' => 'callback', 'data' => $d1, 'message_id' => (string) $foreignId]);
                $this->fail("{$where}: D1 на чужом сообщении должна быть отвергнута");
            } catch (\InvalidArgumentException) {
                // ожидаемо
            }
            $this->assertSame($logs, $this->conn->table('player_action_log')->countAllResults(), "{$where}: нет диспетча");
            $this->assertSame($intents, $this->conn->table('web_play_intents')->countAllResults(), "{$where}: намерение не занято");
        }

        foreach (['screen' => $m1['message_id'], 'inbox' => $inboxWithD1['message_id']] as $where => $ownId) {
            $logs = $this->conn->table('player_action_log')->countAllResults();
            $svc->act($accountId, $charId, ['intent_id' => "ok-{$where}", 'kind' => 'callback', 'data' => $d1, 'message_id' => (string) $ownId]);
            $this->assertSame($logs + 1, $this->conn->table('player_action_log')->countAllResults(), "{$where}: D1 на своём сообщении диспетчится");
        }
    }

    /** #11: тот же синтетический id у двух персонажей — кнопка с копии B не открывает A. */
    public function testCallbackFromAnotherCharactersMessageWithTheSameIdIsRejected(): void
    {
        [$accountA, $charA] = $this->virtualCharacter();
        [, $charB]          = $this->virtualCharacter();
        $first              = (new WebPlay())->firstMessageId;
        $d                  = 'web_test_only_on_b';
        $this->seedScreen($charA, [$this->msg($first, 'web_test_a_own')]);
        $this->seedScreen($charB, [$this->msg($first, $d)]);
        // Строка B во входящих вставлена первой: запрос без character_id нашёл бы её.
        $this->seedInbox($charB, $this->msg($first + 900, $d));
        $this->seedInbox($charA, $this->msg($first + 900, 'web_test_a_own'));
        $svc  = $this->service();
        $logs = $this->conn->table('player_action_log')->countAllResults();

        foreach (['screen' => $first, 'inbox' => $first + 900] as $where => $id) {
            try {
                $svc->act($accountA, $charA, ['intent_id' => "b-{$where}", 'kind' => 'callback', 'data' => $d, 'message_id' => (string) $id]);
                $this->fail("{$where}: кнопка с сообщения B не должна пройти у A");
            } catch (\InvalidArgumentException) {
                // ожидаемо
            }
        }

        $this->assertSame($logs, $this->conn->table('player_action_log')->countAllResults(), 'нет диспетча');
        $this->assertSame(0, $this->conn->table('web_play_intents')->countAllResults(), 'намерения не заняты');
    }

    /** #7 (plan A17): окно дедупа по часам БД, чистка при записи намерения. */
    public function testIntentsOlderThanTheWindowArePrunedAndFreshOnesStillDedup(): void
    {
        [$accountId, $charId] = $this->virtualCharacter();
        $hours                = (new WebPlay())->intentRetentionHours;
        $this->conn->query(
            'INSERT INTO web_play_intents (account_id, intent_id, created_at) VALUES (?, ?, NOW() - INTERVAL ? HOUR), (?, ?, NOW() - INTERVAL 1 HOUR)',
            [$accountId, 'old-1', $hours + 1, $accountId, 'fresh-1']
        );
        $svc = $this->service();

        $svc->act($accountId, $charId, ['intent_id' => 'new-1', 'kind' => 'command', 'data' => '/guide']);

        $this->assertSame(0, $this->conn->table('web_play_intents')->where('intent_id', 'old-1')->countAllResults(), 'старое намерение удалено');
        $this->assertSame(1, $this->conn->table('web_play_intents')->where('intent_id', 'fresh-1')->countAllResults(), 'свежее в окне живо');

        $logs = $this->conn->table('player_action_log')->countAllResults();
        $svc->act($accountId, $charId, ['intent_id' => 'fresh-1', 'kind' => 'command', 'data' => '/guide']);
        $this->assertSame($logs, $this->conn->table('player_action_log')->countAllResults(), 'повтор в окне не диспетчится');
    }

    public function testSameIntentTwiceDispatchesOnce(): void
    {
        [$accountId, $charId] = $this->virtualCharacter();
        $svc                  = $this->service();
        $intent               = ['intent_id' => 'dup-1', 'kind' => 'command', 'data' => '/guide'];

        $first  = $svc->act($accountId, $charId, $intent);
        $second = $svc->act($accountId, $charId, $intent);

        $this->assertSame(1, $this->conn->table('player_action_log')->countAllResults(), 'один диспетч');
        $this->assertSame($first['state'], $second['state'], 'дубль отдаёт текущее состояние');
        $intentRow = $this->row('web_play_intents', ['account_id' => $accountId, 'intent_id' => 'dup-1']);
        $this->assertGreaterThan(0, (int) $intentRow['id']);
        $this->assertSame(0, $this->conn->table('telegram_updates_seen')->countAllResults(), 'веб не пишет в дедуп вебхука');
    }

    public function testCharacterOfAnotherAccountIsRefused(): void
    {
        [$accountA]         = $this->virtualCharacter();
        [, $charB]          = $this->linkedCharacter();

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->act($accountA, $charB, ['intent_id' => 'x', 'kind' => 'command', 'data' => '/guide']);
    }

    public function testIdentityComesFromTheCharacterNotFromTheIntent(): void
    {
        [$accountId, $charId, $chat] = $this->virtualCharacter();
        $this->service()->act($accountId, $charId, [
            'intent_id' => 'id-1', 'kind' => 'command', 'data' => '/guide',
            'telegram_id' => (string) self::REAL_TG, 'chat_id' => (string) self::REAL_TG, 'character_id' => '999', 'account_id' => '999',
        ]);

        $log = $this->row('player_action_log', ['character_id' => $charId]);
        $this->assertSame($chat, (int) $log['telegram_user_id']);
        $this->assertSame(0, $this->conn->table('player_action_log')->where('telegram_user_id', self::REAL_TG)->countAllResults());
    }

    // ── R4: транспорт и захват восстановлены, даже когда конвейер бросает ────

    public function testTransportRestoredAfterActAndWhenThePipelineThrows(): void
    {
        [$accountId, $charId] = $this->virtualCharacter();

        $ok = $this->service();
        $ok->act($accountId, $charId, ['intent_id' => 'r4-ok', 'kind' => 'command', 'data' => '/guide']);
        $this->assertInstanceOf(\App\Services\Web\BridgeClient::class, $ok->seenClient, 'во время конвейера стоит BridgeClient');
        $this->assertTransportRestored();

        $boom        = $this->service();
        $boom->throw = new \RuntimeException('pipeline blew up');
        $result      = $boom->act($accountId, $charId, ['intent_id' => 'r4-boom', 'kind' => 'command', 'data' => '/guide']);
        $this->assertSame(WebActService::FAILED_ALERT, $result['alert']);
        $this->assertTransportRestored();
    }

    // ── Первый вход ─────────────────────────────────────────────────────

    public function testBootstrapDispatchesOnceOnlyWhenThereIsNoState(): void
    {
        [$accountId, $charId] = $this->virtualCharacter();
        $svc                  = $this->service();

        $svc->bootstrap($accountId, $charId);
        $svc->bootstrap($accountId, $charId);

        $this->assertSame(1, $this->conn->table('web_play_intents')->countAllResults());
        $this->assertSame(1, $this->conn->table('player_action_log')->where('action_name', 'start')->countAllResults());
    }

    // ── Фикстура ─────────────────────────────────────────────────────────

    /** Клиент, который сейчас стоит у Longman. */
    public static function longmanClient(): ?ClientInterface
    {
        $prop = new \ReflectionProperty(LongmanRequest::class, 'client');
        $v    = $prop->getValue();

        return $v instanceof ClientInterface ? $v : null;
    }

    private function assertTransportRestored(): void
    {
        $this->assertSame($this->probeClient, self::longmanClient(), 'у Request снова клиент Probe');
        $this->assertSame($this->probeClient, TelegramDeliveryProbe::client());
        $this->assertFalse(WebDelivery::isCapturing());
        $this->assertNull(DeliveryContext::actor());
    }

    private function service(): SpyWebActService
    {
        $telegram = new Telegram('123456:TEST_TOKEN', 'wildworldtest_bot');
        $telegram->addCommandsPath(APPPATH . 'Controllers/Telegram/Commands');
        // Конструктор Telegram мог поставить Longman'у свой клиент — возвращаем клиент Probe.
        LongmanRequest::setClient($this->probeClient);

        return new SpyWebActService($this->conn, new UpdatePipeline($telegram));
    }

    /** Probe ставится как в проде, его клиент подменяется заглушкой сети «ok». */
    private function installProbeStub(): void
    {
        TelegramDeliveryProbe::reset();
        TelegramDeliveryProbe::install();
        $this->network     = [];
        $this->probeClient = new Client(['handler' => function (RequestInterface $request): PromiseInterface {
            $this->network[] = basename($request->getUri()->getPath());

            return Create::promiseFor(new Response(200, [], '{"ok":true,"result":true}'));
        }]);
        (new \ReflectionProperty(TelegramDeliveryProbe::class, 'client'))->setValue(null, $this->probeClient);
        LongmanRequest::setClient($this->probeClient);
    }

    /** @param array<string, mixed> $msg */
    private function firstCallback(array $msg): string
    {
        $rows = is_array($msg['inline_keyboard'] ?? null) ? $msg['inline_keyboard'] : [];
        foreach ($rows as $row) {
            foreach (is_array($row) ? $row : [] as $button) {
                if (is_array($button) && is_string($button['callback_data'] ?? null)) {
                    return $button['callback_data'];
                }
            }
        }
        $this->fail('на экране нет callback-кнопки');
    }

    /** @return array<string, mixed> Msg с одной callback-кнопкой */
    private function msg(int $id, string $data): array
    {
        return [
            'message_id' => $id, 'text' => "msg {$id}", 'caption' => null, 'parse_mode' => null, 'photo_url' => null,
            'inline_keyboard' => [[['text' => 'D', 'callback_data' => $data]]],
        ];
    }

    /** @param list<array<string, mixed>> $screen */
    private function seedScreen(int $charId, array $screen): void
    {
        $this->conn->query(
            "INSERT INTO web_play_state (character_id, next_message_id, screen, history, dock, updated_at) VALUES (?, ?, ?, '[]', '[]', NOW())"
            . ' ON DUPLICATE KEY UPDATE screen = VALUES(screen)',
            [$charId, (new WebPlay())->firstMessageId + 1000, json_encode($screen, JSON_UNESCAPED_UNICODE)]
        );
    }

    /** @param array<string, mixed> $msg */
    private function seedInbox(int $charId, array $msg): void
    {
        $this->conn->table('web_inbox')->insert([
            'character_id' => $charId, 'message_id' => $msg['message_id'], 'source' => 'virtual',
            'payload'      => json_encode($msg, JSON_UNESCAPED_UNICODE), 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return array{0:int, 1:int, 2:int} account, character, telegram_id */
    private function virtualCharacter(): array
    {
        $accountId = (new AccountService($this->conn))->createAccount('web');
        $tgUser    = (new VirtualIdentityService($this->conn))->ensureForAccount($accountId, 'Странник');
        $charId    = $this->makeCharacter($tgUser, $accountId);
        $identity  = (new VirtualIdentityService($this->conn))->identityForCharacter($charId);
        $this->assertNotNull($identity);
        $this->assertTrue(VirtualChat::is($identity['telegram_id']));

        return [$accountId, $charId, $identity['telegram_id']];
    }

    /** @return array{0:int, 1:int, 2:int} */
    private function linkedCharacter(): array
    {
        $accountId = (new AccountService($this->conn))->createAccount('web');
        $now       = date('Y-m-d H:i:s');
        $this->conn->table('telegram_users')->insert(['telegram_id' => self::REAL_TG, 'first_name' => 'Т', 'created_at' => $now, 'updated_at' => $now]);
        $charId = $this->makeCharacter((int) $this->conn->insertID(), $accountId);

        return [$accountId, $charId, self::REAL_TG];
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
