<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Controllers\Worker;
use App\Services\Logging\ActionOrigin;
use App\Services\Logging\PlayerActionLogger;
use App\Services\Logging\TelegramDeliveryProbe;
use App\Services\Telegram\Request;
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
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request as LongmanRequest;
use Longman\TelegramBot\Telegram;
use Psr\Http\Message\RequestInterface;

/** Шпион настоящей отправки: что дошло до Telegram. */
final class TaskResultSpyRequest extends Request
{
    /** @var list<array{0:string, 1:array<string,mixed>}> */
    public static array $calls = [];

    protected static function transport(string $action, array $data): ServerResponse
    {
        self::$calls[] = [$action, $data];

        return new ServerResponse(['ok' => true, 'result' => ['message_id' => 77, 'chat' => ['id' => 1], 'date' => 1]], '');
    }

    protected static function transportSendMessage(array $data, ?array &$extras = []): ServerResponse
    {
        return self::transport('sendMessage', $data);
    }
}

/** Действие `/play` без настоящего конвейера: шов выполняет то, что сделал бы обработчик. */
final class TaskScreenActService extends WebActService
{
    /** @var (\Closure(): void)|null */
    public ?\Closure $during = null;

    protected function runPipeline(UpdatePipeline $pipeline, array $update): bool
    {
        if ($this->during !== null) {
            ($this->during)();
        }

        return true;
    }
}

/** Worker с подменённым классом обработчика. */
final class TaskContextWorker extends Worker
{
    public static string $handler = '';

    /** @param array<string,mixed> $task */
    public function run(array $task): void
    {
        $this->handleTask($task, ['name' => 'gather']);
    }

    protected function getHandlerClassName($taskName, ?string $explicitHandlerKey = null)
    {
        return self::$handler;
    }
}

/** Обработчик: шлёт итог задачи в чат и запоминает контекст. */
final class TaskResultSendingHandler
{
    public static int $chat = 0;

    public static ?int $seen = null;

    /** @param array<string,mixed> $task */
    public function handle(array $task): void
    {
        self::$seen = WebDelivery::taskContext();
        TaskResultSpyRequest::sendMessage(['chat_id' => self::$chat, 'text' => '✅ Сбор завершён: дерево ×3']);
    }
}

/** Обработчик, который падает. */
final class TaskResultThrowingHandler
{
    public static ?int $seen = null;

    /** @param array<string,mixed> $task */
    public function handle(array $task): void
    {
        self::$seen = WebDelivery::taskContext();

        throw new \RuntimeException('boom');
    }
}

/**
 * web-onboarding-02 — экран законченной задачи сам заменяется её итогом: действие `/play`,
 * начавшее задачу, помечает экран её id; фоновая отправка в контексте этой задачи (`Worker`)
 * заменяет экран; без контекста или в контексте другой задачи — только входящие.
 *
 * Схема — исполнением настоящих миграций.
 *
 * @internal
 */
final class WebTaskResultScreenTest extends CIUnitTestCase
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
        '2026-05-08-220000_AddDisableMediaFlag',
        '2026-05-19-100000_CreateGameSettingsTable',
        '2026-07-17-100000_E6AddLastSeenToTelegramUsers',
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
        'characters', 'action_log', 'tasks', 'character_tasks', 'game_settings', 'player_action_log',
        'web_play_state', 'web_inbox', 'web_play_intents',
    ];

    private const FLAG_CACHE = 'game_settings_web_play_enabled';

    private const REAL_TG = 555000778;

    private const STARTED = '⛏ Сбор ресурсов начался! Вернись через 5 минут.';

    private BaseConnection $conn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = Database::connect();
        $this->dropTables();
        try {
            $forge = Database::forge();
            foreach (self::MIGRATIONS as $file) {
                require_once APPPATH . 'Database/Migrations/' . $file . '.php';
                $class = 'App\\Database\\Migrations\\' . substr($file, 18);
                $m     = new $class($forge instanceof Forge ? $forge : null);
                $this->assertInstanceOf(Migration::class, $m);
                $m->up();
            }
        } catch (\Throwable $e) {
            $this->dropTables();

            throw $e;
        }
        $now = date('Y-m-d H:i:s');
        $this->conn->table('tasks')->insert([
            'id' => 1, 'name' => 'gather', 'name_rus' => 'Сбор', 'description' => 'Сбор ресурсов', 'type' => 'gather',
            'difficulty_level' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        service('cache')->clean();
        PlayerActionLogger::reset();
        ActionOrigin::reset();
        DeliveryContext::reset();
        WebDelivery::reset();
        TaskResultSpyRequest::$calls      = [];
        TaskResultSendingHandler::$seen   = null;
        TaskResultThrowingHandler::$seen  = null;
        $this->installProbeStub();
        service('cache')->save(self::FLAG_CACHE, ['v' => true, 't' => 'bool'], 60);
    }

    protected function tearDown(): void
    {
        PlayerActionLogger::reset();
        ActionOrigin::reset();
        DeliveryContext::reset();
        WebDelivery::reset();
        TelegramDeliveryProbe::reset();
        service('cache')->delete(self::FLAG_CACHE);
        $this->dropTables();
        parent::tearDown();
    }

    // ── пометка экрана задачей ───────────────────────────────────────────

    public function testActStartingTaskMarksScreenAndActWithoutTaskClearsIt(): void
    {
        [$accountId, $charId, $chat, $tuId] = $this->virtualCharacter();
        $older = $this->insertTask($charId, $tuId); // была до действия — не «новая»
        $store = new WebScreenStore($this->conn);

        $taskId = $this->actStartingTask($accountId, $charId, $chat, $tuId);
        $this->assertGreaterThan($older, $taskId);
        $this->assertSame($taskId, $store->screenTaskId($charId));
        $this->assertSame(1, $store->revision($charId));

        $this->act($accountId, $charId, $chat, 'Инвентарь', null);
        $this->assertNull($store->screenTaskId($charId), 'действие без новой задачи пишет task_id = null');
        $this->assertSame(2, $store->revision($charId));
    }

    // ── замена экрана итогом ─────────────────────────────────────────────

    public function testTaskResultReplacesScreenWholeAndOldScreenGoesFirstToHistory(): void
    {
        [$accountId, $charId, $chat, $tuId] = $this->virtualCharacter();
        $store  = new WebScreenStore($this->conn);
        $this->act($accountId, $charId, $chat, 'Карта', null);
        $taskId = $this->actStartingTask($accountId, $charId, $chat, $tuId);
        $before = $store->state($charId);
        $rev    = $store->revision($charId);

        WebDelivery::setTaskContext($taskId);
        $r = TaskResultSpyRequest::sendPhoto([
            'chat_id'      => $chat,
            'photo'        => 'AgACAgIAAxkBAAIfile-id',
            'caption'      => '✅ Сбор завершён: дерево ×3, камень ×1',
            'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '⛏ Ещё раз', 'callback_data' => 'gather']]]]),
        ]);
        WebDelivery::setTaskContext(null);

        $this->assertTrue($r->isOk());
        $this->assertSame([], TaskResultSpyRequest::$calls, 'виртуальный чат в Telegram не уходит');
        $after = $store->state($charId);
        $this->assertCount(1, $after['screen']);
        $msg = $after['screen'][0];
        $this->assertNull($msg['text']);
        $this->assertSame('✅ Сбор завершён: дерево ×3, камень ×1', $msg['caption']);
        $this->assertSame([[['text' => '⛏ Ещё раз', 'callback_data' => 'gather']]], $msg['inline_keyboard']);
        $this->assertSame($before['screen'], $after['history'][0], 'прежний экран — первым в истории');
        $this->assertSame($before['history'], array_slice($after['history'], 1));
        $this->assertSame($rev + 1, $store->revision($charId));
        $this->assertNull($store->screenTaskId($charId));
        $this->assertStringNotContainsString('Прервать', (string) json_encode($after['screen'], JSON_UNESCAPED_UNICODE));

        $rows = $this->inbox($charId);
        $this->assertCount(1, $rows);
        $this->assertSame([(int) $rows[0]['message_id']], $store->screenMessageIds($charId), 'id на экране = id строки входящих');
    }

    public function testHistoryLimitHoldsOnReplacement(): void
    {
        $config              = new WebPlay();
        $config->historySize = 2;
        WebDelivery::useServices(new WebScreenStore($this->conn, $config));
        [$accountId, $charId, $chat, $tuId] = $this->virtualCharacter();
        $svc = $this->service($config);
        foreach (['А', 'Б', 'В'] as $t) {
            $this->act($accountId, $charId, $chat, $t, null, $svc);
        }
        $taskId = $this->actStartingTask($accountId, $charId, $chat, $tuId, $svc);

        WebDelivery::setTaskContext($taskId);
        TaskResultSpyRequest::sendMessage(['chat_id' => $chat, 'text' => 'Итог']);
        WebDelivery::setTaskContext(null);

        $state = (new WebScreenStore($this->conn, $config))->state($charId);
        $this->assertSame('Итог', $state['screen'][0]['text']);
        $this->assertCount(2, $state['history']);
        $this->assertSame(self::STARTED, $state['history'][0][0]['text']);
    }

    public function testSendWithoutContextOrInOtherTaskContextLeavesScreen(): void
    {
        [$accountId, $charId, $chat, $tuId] = $this->virtualCharacter();
        $store  = new WebScreenStore($this->conn);
        $taskId = $this->actStartingTask($accountId, $charId, $chat, $tuId);
        $screen = $store->state($charId)['screen'];
        $rev    = $store->revision($charId);

        TaskResultSpyRequest::sendMessage(['chat_id' => $chat, 'text' => 'Рассылка']);
        WebDelivery::setTaskContext($taskId + 100);
        TaskResultSpyRequest::sendMessage(['chat_id' => $chat, 'text' => 'Чужая задача']);
        WebDelivery::setTaskContext(null);

        $this->assertSame($screen, $store->state($charId)['screen']);
        $this->assertSame($rev, $store->revision($charId));
        $this->assertSame($taskId, $store->screenTaskId($charId));
        $this->assertCount(2, $this->inbox($charId), 'обе — во входящих');
    }

    public function testSecondSendInSameContextAfterReplacementLeavesScreen(): void
    {
        [$accountId, $charId, $chat, $tuId] = $this->virtualCharacter();
        $store  = new WebScreenStore($this->conn);
        $taskId = $this->actStartingTask($accountId, $charId, $chat, $tuId);

        WebDelivery::setTaskContext($taskId);
        TaskResultSpyRequest::sendMessage(['chat_id' => $chat, 'text' => 'Итог']);
        $rev = $store->revision($charId);
        TaskResultSpyRequest::sendMessage(['chat_id' => $chat, 'text' => 'Совет после итога']);
        WebDelivery::setTaskContext(null);

        $this->assertSame('Итог', $store->state($charId)['screen'][0]['text']);
        $this->assertSame($rev, $store->revision($charId));
        $this->assertCount(2, $this->inbox($charId));
    }

    public function testChainStepInSameMinuteGoesToInboxNotScreen(): void
    {
        [$accountId, $charId, $chat, $tuId] = $this->virtualCharacter();
        $store  = new WebScreenStore($this->conn);
        $taskId = $this->actStartingTask($accountId, $charId, $chat, $tuId);

        // Тот же тик: сначала шаг цепочки другой задачи, затем итог нашей, затем шаг вне задачи.
        WebDelivery::setTaskContext($taskId + 1);
        TaskResultSpyRequest::sendMessage(['chat_id' => $chat, 'text' => '📜 Шаг цепочки: поговори с Роби']);
        WebDelivery::setTaskContext($taskId);
        TaskResultSpyRequest::sendMessage(['chat_id' => $chat, 'text' => 'Итог сбора']);
        WebDelivery::setTaskContext(null);
        TaskResultSpyRequest::sendMessage(['chat_id' => $chat, 'text' => '📜 Следующий шаг']);

        $this->assertSame('Итог сбора', $store->state($charId)['screen'][0]['text']);
        $this->assertCount(3, $this->inbox($charId));
    }

    // ── Worker ───────────────────────────────────────────────────────────

    public function testWorkerSetsContextForHandleAndResultReplacesScreen(): void
    {
        [$accountId, $charId, $chat, $tuId] = $this->virtualCharacter();
        $taskId = $this->actStartingTask($accountId, $charId, $chat, $tuId);
        $this->conn->table('character_tasks')->where('id', $taskId)->update(['end_time' => date('Y-m-d H:i:s', time() - 60)]);

        TaskResultSendingHandler::$chat = $chat;
        TaskContextWorker::$handler     = TaskResultSendingHandler::class;
        (new TaskContextWorker())->run($this->taskRow($taskId));

        $this->assertSame($taskId, TaskResultSendingHandler::$seen);
        $this->assertNull(WebDelivery::taskContext());
        $store = new WebScreenStore($this->conn);
        $this->assertSame('✅ Сбор завершён: дерево ×3', $store->state($charId)['screen'][0]['text']);
        $this->assertSame([(int) $this->inbox($charId)[0]['message_id']], $store->screenMessageIds($charId));
    }

    public function testWorkerClearsContextWhenHandlerThrows(): void
    {
        [, $charId, , $tuId] = $this->virtualCharacter();
        $taskId = $this->insertTask($charId, $tuId);

        TaskContextWorker::$handler = TaskResultThrowingHandler::class;
        (new TaskContextWorker())->run($this->taskRow($taskId));

        $this->assertSame($taskId, TaskResultThrowingHandler::$seen);
        $this->assertNull(WebDelivery::taskContext(), 'исключение в handle() не оставляет контекст следующей задаче');
    }

    // ── привязанный TG-игрок ─────────────────────────────────────────────

    public function testLinkedPlayerScreenReplacedAndTelegramSendUnchanged(): void
    {
        [$accountId, $charId, $tuId] = $this->linkedCharacter();
        $store  = new WebScreenStore($this->conn);
        $taskId = $this->actStartingTask($accountId, $charId, self::REAL_TG, $tuId);

        WebDelivery::setTaskContext($taskId);
        $r = TaskResultSpyRequest::sendMessage(['chat_id' => self::REAL_TG, 'text' => 'Итог сбора']);
        WebDelivery::setTaskContext(null);

        $this->assertTrue($r->isOk());
        $this->assertCount(1, TaskResultSpyRequest::$calls, 'в Telegram ушло как раньше');
        $this->assertSame('Итог сбора', TaskResultSpyRequest::$calls[0][1]['text'] ?? null);
        $rows = $this->inbox($charId);
        $this->assertCount(1, $rows);
        $this->assertSame('mirror', $rows[0]['source']);
        $this->assertSame([(int) $rows[0]['message_id']], $store->screenMessageIds($charId));
        $this->assertSame('Итог сбора', $store->state($charId)['screen'][0]['text']);
    }

    // ── ревизия и старое состояние ───────────────────────────────────────

    public function testLegacyStateReadsAsRevZeroAndNoTask(): void
    {
        [, $charId] = $this->virtualCharacter();
        $first      = (new WebPlay())->firstMessageId;
        $screen     = [['message_id' => $first + 5, 'text' => 'Старый', 'caption' => null, 'parse_mode' => null, 'photo_url' => null, 'inline_keyboard' => []]];
        $this->conn->table('web_play_state')->insert([
            'character_id' => $charId, 'next_message_id' => $first + 10,
            'screen' => json_encode($screen, JSON_UNESCAPED_UNICODE), 'history' => '[]', 'dock' => '[]',
        ]);
        $store = new WebScreenStore($this->conn);

        $this->assertSame(0, $store->revision($charId));
        $this->assertNull($store->screenTaskId($charId));
        $this->assertSame([$first + 5], $store->screenMessageIds($charId));
        $this->assertEquals($screen, $store->state($charId)['screen']);
        $this->assertSame(0, $store->revision($charId + 999), 'нет строки — rev 0');
        $this->assertSame([], $store->screenMessageIds($charId + 999));
    }

    // ── media-off ────────────────────────────────────────────────────────

    public function testResultOnScreenRendersFullMeaningWithoutPhoto(): void
    {
        [$accountId, $charId, $chat, $tuId] = $this->virtualCharacter();
        $taskId = $this->actStartingTask($accountId, $charId, $chat, $tuId);

        WebDelivery::setTaskContext($taskId);
        TaskResultSpyRequest::sendPhoto([
            'chat_id' => $chat,
            'photo'   => 'AgACAgIAAxkBAAIfile-id',
            'caption' => 'Сбор завершён. Добыто: дерево 3, камень 1. Персонаж свободен.',
        ]);
        WebDelivery::setTaskContext(null);

        $state = (new WebScreenStore($this->conn))->state($charId);
        $this->assertNull($state['screen'][0]['photo_url'], 'фото не резолвится — экран без картинки');
        helper(['url', 'form']);
        $html = view('site/_play/state', ['state' => $state, 'alert' => null]);
        // Текущий экран — до ленты истории (там прежний «начался» остаётся законно).
        $current = strstr($html, 'class="play-history"', true);
        $this->assertIsString($current);
        $this->assertStringContainsString('Сбор завершён. Добыто: дерево 3, камень 1. Персонаж свободен.', $current);
        $this->assertStringNotContainsString('<img', $current);
        $this->assertStringNotContainsString('Прервать', $current);
    }

    // ── фикстура ─────────────────────────────────────────────────────────

    private function service(?WebPlay $config = null): TaskScreenActService
    {
        $telegram = new Telegram('123456:TEST_TOKEN', 'wildworldtest_bot');
        $this->installProbeStub();

        return new TaskScreenActService($this->conn, new UpdatePipeline($telegram), $config);
    }

    /** Действие `/play`, которое шлёт актору одно сообщение и (если `$tuId`) начинает задачу. */
    private function act(int $accountId, int $charId, int $chat, string $text, ?int $tuId, ?TaskScreenActService $svc = null): ?int
    {
        $svc ??= $this->service();
        $taskId = null;
        $svc->during = function () use ($chat, $text, $tuId, $charId, &$taskId): void {
            $markup = $tuId === null ? null : json_encode(['inline_keyboard' => [[['text' => '❌ Прервать добычу', 'callback_data' => 'stop_gather']]]]);
            TaskResultSpyRequest::sendMessage(array_filter(['chat_id' => $chat, 'text' => $text, 'reply_markup' => $markup]));
            if ($tuId !== null) {
                $taskId = $this->insertTask($charId, $tuId);
            }
        };
        $svc->act($accountId, $charId, ['intent_id' => bin2hex(random_bytes(8)), 'kind' => WebActService::KIND_TEXT, 'data' => $text]);

        return $taskId;
    }

    private function actStartingTask(int $accountId, int $charId, int $chat, int $tuId, ?TaskScreenActService $svc = null): int
    {
        $taskId = $this->act($accountId, $charId, $chat, self::STARTED, $tuId, $svc);
        $this->assertNotNull($taskId);

        return $taskId;
    }

    private function insertTask(int $charId, int $tuId): int
    {
        $now = date('Y-m-d H:i:s');
        $this->conn->table('character_tasks')->insert([
            'character_id' => $charId, 'telegram_user_id' => $tuId, 'task_id' => 1,
            'start_time' => $now, 'end_time' => date('Y-m-d H:i:s', time() + 300), 'status' => 'in_work',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return (int) $this->conn->insertID();
    }

    /** @return array<string,mixed> */
    private function taskRow(int $taskId): array
    {
        $row = $this->conn->table('character_tasks')->where('id', $taskId)->get()->getRowArray();
        $this->assertIsArray($row);

        return $row;
    }

    /** @return array{0:int, 1:int, 2:int, 3:int} аккаунт, персонаж, виртуальный чат, telegram_users.id */
    private function virtualCharacter(): array
    {
        $accountId = (new AccountService($this->conn))->createAccount('web');
        $tuId      = (new VirtualIdentityService($this->conn))->ensureForAccount($accountId, 'Странник');
        $charId    = $this->makeCharacter($tuId, $accountId);
        $identity  = (new VirtualIdentityService($this->conn))->identityForCharacter($charId);
        $this->assertNotNull($identity);
        $this->assertTrue(VirtualChat::is($identity['telegram_id']));

        return [$accountId, $charId, $identity['telegram_id'], $tuId];
    }

    /** @return array{0:int, 1:int, 2:int} аккаунт (входил на сайт), персонаж, telegram_users.id */
    private function linkedCharacter(): array
    {
        $accountId = (new AccountService($this->conn))->createAccount('web');
        $this->conn->table('accounts')->where('id', $accountId)->update(['last_login_at' => date('Y-m-d H:i:s')]);
        $now = date('Y-m-d H:i:s');
        $this->conn->table('telegram_users')->insert(['telegram_id' => self::REAL_TG, 'first_name' => 'Т', 'created_at' => $now, 'updated_at' => $now]);
        $tuId = (int) $this->conn->insertID();

        return [$accountId, $this->makeCharacter($tuId, $accountId), $tuId];
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

    /** @return list<array<string,mixed>> */
    private function inbox(int $charId): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = $this->conn->table('web_inbox')->where('character_id', $charId)->orderBy('id')->get()->getResultArray();

        return $rows;
    }

    /** Probe ставится как в проде, его клиент — заглушка сети «ok». */
    private function installProbeStub(): void
    {
        TelegramDeliveryProbe::reset();
        TelegramDeliveryProbe::install();
        $client = new Client(['handler' => static fn (RequestInterface $request): PromiseInterface => Create::promiseFor(new Response(200, [], '{"ok":true,"result":true}'))]);
        (new \ReflectionProperty(TelegramDeliveryProbe::class, 'client'))->setValue(null, $client);
        LongmanRequest::setClient($client);
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
