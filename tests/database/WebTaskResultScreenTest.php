<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Logging\ActionOrigin;
use App\Services\Onboarding\OnboardingChainCatalog;
use App\Services\Onboarding\OnboardingHintCatalog;
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
use App\Services\Web\WebInboxService;
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

/**
 * web-onboarding-02 — экран законченной задачи сам заменяется её итогом при чтении состояния:
 * действие `/play`, начавшее задачу, помечает экран её id; `WebActService::current()` видит, что
 * задача уже не `in_work`, и ставит на экран итог из входящих (`WebTaskResultPicker`: окно от
 * `updated_at`, чужие по типу отброшены) или нейтральное «✅ … завершена — итог во входящих 🔔».
 * Фоновые сообщения идут настоящим путём доставки (вне захвата → входящие); `Worker` не участвует.
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
        TaskResultSpyRequest::$calls = [];
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

    public function testInWorkTaskLeavesScreenAndRev(): void
    {
        [$accountId, $charId, $chat, $tuId] = $this->virtualCharacter();
        $store  = new WebScreenStore($this->conn);
        $taskId = $this->actStartingTask($accountId, $charId, $chat, $tuId);
        $screen = $store->state($charId)['screen'];
        $rev    = $store->revision($charId);

        TaskResultSpyRequest::sendMessage(['chat_id' => $chat, 'text' => 'Рассылка во время задачи']);
        $state = $this->service()->current($charId)['state'];

        $this->assertSame($screen, $state['screen']);
        $this->assertSame($rev, $store->revision($charId));
        $this->assertSame($taskId, $store->screenTaskId($charId));
    }

    // ── замена экрана итогом ─────────────────────────────────────────────

    public function testCompletedTaskResultReplacesScreenWholeAndOldScreenGoesFirstToHistory(): void
    {
        [$accountId, $charId, $chat, $tuId] = $this->virtualCharacter();
        $store  = new WebScreenStore($this->conn);
        $this->act($accountId, $charId, $chat, 'Карта', null);
        $taskId = $this->actStartingTask($accountId, $charId, $chat, $tuId);
        $before = $store->state($charId);
        $rev    = $store->revision($charId);

        $this->complete($taskId);
        $r = TaskResultSpyRequest::sendPhoto([
            'chat_id'      => $chat,
            'photo'        => 'AgACAgIAAxkBAAIfile-id',
            'caption'      => '✅ Сбор завершён: дерево ×3, камень ×1',
            'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '⛏ Ещё раз', 'callback_data' => 'gather']]]]),
        ]);
        $this->assertTrue($r->isOk());
        $this->assertSame([], TaskResultSpyRequest::$calls, 'виртуальный чат в Telegram не уходит');
        $rows = $this->inbox($charId);
        $this->assertCount(1, $rows);
        $inboxId = (int) $rows[0]['message_id'];
        $this->assertSame($before['screen'], $store->state($charId)['screen'], 'до чтения экран не меняется');

        $after = $this->service()->current($charId)['state'];

        $this->assertSame([(new WebInboxService($this->conn))->findMessage($charId, $inboxId)], $after['screen'], 'сообщение входящих целиком');
        $msg = $after['screen'][0];
        $this->assertSame($inboxId, $msg['message_id']);
        $this->assertNull($msg['text']);
        $this->assertSame('✅ Сбор завершён: дерево ×3, камень ×1', $msg['caption']);
        $this->assertSame([[['text' => '⛏ Ещё раз', 'callback_data' => 'gather']]], $msg['inline_keyboard']);
        $this->assertSame($before['screen'], $after['history'][0], 'прежний экран — первым в истории');
        $this->assertSame($before['history'], array_slice($after['history'], 1));
        $this->assertSame($rev + 1, $store->revision($charId));
        $this->assertNull($store->screenTaskId($charId));
        $this->assertSame([$inboxId], $store->screenMessageIds($charId));
        $this->assertStringNotContainsString('Прервать', (string) json_encode($after['screen'], JSON_UNESCAPED_UNICODE));
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

        $this->complete($taskId);
        TaskResultSpyRequest::sendMessage(['chat_id' => $chat, 'text' => 'Итог']);
        $state = $svc->current($charId)['state'];

        $this->assertSame('Итог', $state['screen'][0]['text']);
        $this->assertCount(2, $state['history']);
        $this->assertSame(self::STARTED, $state['history'][0][0]['text']);
    }

    public function testChainStepInSameSecondWithSmallerIdStaysInInbox(): void
    {
        [$accountId, $charId, $chat, $tuId] = $this->virtualCharacter();
        $taskId = $this->actStartingTask($accountId, $charId, $chat, $tuId);
        $step   = OnboardingChainCatalog::completion(OnboardingChainCatalog::STEP_GATHER);
        $this->assertNotNull($step);

        // Порядок холодного прохода 2026-10-11: шаг «💧 Первый ресурс в кармане!» раньше итога добычи.
        $this->complete($taskId);
        TaskResultSpyRequest::sendMessage(['chat_id' => $chat, 'text' => $step['text'], 'parse_mode' => 'Markdown', 'reply_markup' => $step['reply_markup']]);
        TaskResultSpyRequest::sendMessage(['chat_id' => $chat, 'text' => '⛏ Сбор ресурсов окончен! Добыто: дерево ×3']);
        $rows = $this->inbox($charId);
        $this->assertCount(2, $rows);
        $this->conn->table('web_inbox')->where('character_id', $charId)->update(['created_at' => $rows[0]['created_at']]);

        $result = $this->service()->current($charId);

        $this->assertSame('⛏ Сбор ресурсов окончен! Добыто: дерево ×3', $result['state']['screen'][0]['text']);
        $this->assertSame([(int) $rows[1]['message_id']], (new WebScreenStore($this->conn))->screenMessageIds($charId));
        $this->assertSame(2, $result['unread'], 'шаг цепочки остаётся во входящих (плашка)');
    }

    public function testChainedTaskNextStepInWorkLeavesScreen(): void
    {
        [$accountId, $charId, $chat, $tuId] = $this->virtualCharacter();
        $store  = new WebScreenStore($this->conn);
        $taskId = $this->actStartingTask($accountId, $charId, $chat, $tuId);
        $screen = $store->state($charId)['screen'];
        $rev    = $store->revision($charId);

        // Поход: тик закрыл шаг A давно и поставил шаг B `in_work`, правя то же сообщение.
        $this->complete($taskId, 180);
        $next = $this->insertTask($charId, $tuId);
        $state = $this->service()->current($charId)['state'];

        $this->assertSame($screen, $state['screen'], 'цепочка идёт — экран похода стоит');
        $this->assertSame($rev, $store->revision($charId));
        $this->assertSame($taskId, $store->screenTaskId($charId));

        // Последний шаг закончился, итог пришёл — экран заменяется им.
        $this->complete($next);
        TaskResultSpyRequest::sendMessage(['chat_id' => $chat, 'text' => 'Итог похода']);
        $this->assertSame('Итог похода', $this->service()->current($charId)['state']['screen'][0]['text']);
    }

    public function testOnlyForeignInWindowWaitsThenGivesNeutralScreen(): void
    {
        [$accountId, $charId, $chat, $tuId] = $this->virtualCharacter();
        $store  = new WebScreenStore($this->conn);
        $taskId = $this->actStartingTask($accountId, $charId, $chat, $tuId);
        $screen = $store->state($charId)['screen'];
        $rev    = $store->revision($charId);
        $step   = OnboardingChainCatalog::completion(OnboardingChainCatalog::STEP_GATHER);
        $hint   = OnboardingHintCatalog::get(OnboardingHintCatalog::DAILY_TASKS);
        $this->assertNotNull($step);
        $this->assertNotNull($hint);

        $this->complete($taskId);
        TaskResultSpyRequest::sendMessage(['chat_id' => $chat, 'text' => $step['text'], 'reply_markup' => $step['reply_markup']]);
        TaskResultSpyRequest::sendMessage(['chat_id' => $chat, 'text' => "🎖 *Новый титул!*\n\n🎖 *Новичок*\n", 'parse_mode' => 'Markdown']);
        TaskResultSpyRequest::sendMessage(array_filter(['chat_id' => $chat, 'text' => $hint['text'], 'reply_markup' => $hint['reply_markup'] ?? null]));

        $this->service()->current($charId);
        $this->assertSame($screen, $store->state($charId)['screen'], 'окно ещё идёт — итог может прийти');
        $this->assertSame($rev, $store->revision($charId));

        $this->shiftBack($charId, $taskId, (new WebPlay())->taskResultWindowSeconds + 1);
        $state = $this->service()->current($charId)['state'];

        $this->assertNeutral($state['screen']);
        $this->assertSame($screen, $state['history'][0]);
        $this->assertSame($rev + 1, $store->revision($charId));
        $this->assertNull($store->screenTaskId($charId));
    }

    public function testMessageBeforeTaskEndOrAfterWindowIsNotTaken(): void
    {
        [$accountId, $charId, $chat, $tuId] = $this->virtualCharacter();
        $taskId = $this->actStartingTask($accountId, $charId, $chat, $tuId);
        $window = (new WebPlay())->taskResultWindowSeconds;

        TaskResultSpyRequest::sendMessage(['chat_id' => $chat, 'text' => 'До конца задачи']);
        TaskResultSpyRequest::sendMessage(['chat_id' => $chat, 'text' => 'После окна']);
        [$early, $late] = array_map(static fn (array $r): int => (int) $r['id'], $this->inbox($charId));
        $this->complete($taskId, $window + 30);
        $this->conn->table('web_inbox')->where('id', $early)->update(['created_at' => date('Y-m-d H:i:s', time() - $window - 31)]);
        $this->conn->table('web_inbox')->where('id', $late)->update(['created_at' => date('Y-m-d H:i:s', time() - 28)]);

        $this->assertNeutral($this->service()->current($charId)['state']['screen']);
    }

    public function testInterruptedTaskGivesNeutralScreenAtOnce(): void
    {
        [$accountId, $charId, $chat, $tuId] = $this->virtualCharacter();
        $store  = new WebScreenStore($this->conn);
        $taskId = $this->actStartingTask($accountId, $charId, $chat, $tuId);
        $rev    = $store->revision($charId);

        $this->conn->table('character_tasks')->where('id', $taskId)->update(['status' => 'interrupted', 'updated_at' => date('Y-m-d H:i:s')]);
        TaskResultSpyRequest::sendMessage(['chat_id' => $chat, 'text' => 'Сообщение после прерывания']);
        $state = $this->service()->current($charId)['state'];

        $this->assertNeutral($state['screen']);
        $this->assertSame(self::STARTED, $state['history'][0][0]['text']);
        $this->assertSame($rev + 1, $store->revision($charId));
        $this->assertNull($store->screenTaskId($charId));
    }

    public function testRepeatedAndRacingReadsReplaceOnce(): void
    {
        [$accountId, $charId, $chat, $tuId] = $this->virtualCharacter();
        $store  = new WebScreenStore($this->conn);
        $taskId = $this->actStartingTask($accountId, $charId, $chat, $tuId);
        $this->complete($taskId);
        TaskResultSpyRequest::sendMessage(['chat_id' => $chat, 'text' => 'Итог']);

        $this->service()->current($charId);
        $state = $store->state($charId);
        $rev   = $store->revision($charId);
        $this->service()->current($charId);
        $this->assertSame($state, $store->state($charId), 'повторное чтение экран не трогает');
        $this->assertSame($rev, $store->revision($charId));

        // Параллельный читатель прочёл пометку до замены: под блокировкой строки он её уже не видит.
        $stale = ['message_id' => 1, 'text' => 'Второй итог', 'caption' => null, 'parse_mode' => null, 'photo_url' => null, 'inline_keyboard' => []];
        $this->assertFalse($store->replaceScreenForTask($charId, $taskId, $stale));
        $this->assertSame($state, $store->state($charId));
        $this->assertSame($rev, $store->revision($charId));
    }

    public function testBootstrapWithSavedScreenAlsoReplaces(): void
    {
        [$accountId, $charId, $chat, $tuId] = $this->virtualCharacter();
        $taskId = $this->actStartingTask($accountId, $charId, $chat, $tuId);
        $this->complete($taskId);
        TaskResultSpyRequest::sendMessage(['chat_id' => $chat, 'text' => 'Итог сбора']);

        $this->assertSame('Итог сбора', $this->service()->bootstrap($accountId, $charId)['state']['screen'][0]['text']);
    }

    // ── привязанный TG-игрок ─────────────────────────────────────────────

    public function testLinkedPlayerScreenReplacedAndTelegramSendUnchanged(): void
    {
        [$accountId, $charId, $tuId] = $this->linkedCharacter();
        $store  = new WebScreenStore($this->conn);
        $taskId = $this->actStartingTask($accountId, $charId, self::REAL_TG, $tuId);

        $this->complete($taskId);
        $r = TaskResultSpyRequest::sendMessage(['chat_id' => self::REAL_TG, 'text' => 'Итог сбора']);
        $this->assertTrue($r->isOk());
        $this->assertCount(1, TaskResultSpyRequest::$calls, 'в Telegram ушло как раньше');
        $this->assertSame('Итог сбора', TaskResultSpyRequest::$calls[0][1]['text'] ?? null);

        $this->service()->current($charId);
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

        $this->complete($taskId);
        TaskResultSpyRequest::sendPhoto([
            'chat_id' => $chat,
            'photo'   => 'AgACAgIAAxkBAAIfile-id',
            'caption' => 'Сбор завершён. Добыто: дерево 3, камень 1. Персонаж свободен.',
        ]);
        $state = $this->service()->current($charId)['state'];

        $this->assertNull($state['screen'][0]['photo_url'], 'фото не резолвится — экран без картинки');
        $current = $this->renderCurrent($state);
        $this->assertStringContainsString('Сбор завершён. Добыто: дерево 3, камень 1. Персонаж свободен.', $current);
        $this->assertStringNotContainsString('<img', $current);
        $this->assertStringNotContainsString('Прервать', $current);
    }

    public function testNeutralScreenRendersFullMeaningInText(): void
    {
        [$accountId, $charId, $chat, $tuId] = $this->virtualCharacter();
        $taskId = $this->actStartingTask($accountId, $charId, $chat, $tuId);
        $this->conn->table('character_tasks')->where('id', $taskId)->update(['status' => 'interrupted']);

        $current = $this->renderCurrent($this->service()->current($charId)['state']);

        $this->assertStringContainsString('✅ Задача «Сбор» завершена — итог во входящих 🔔', $current);
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

    /** Задача закончилась `$ago` секунд назад (как `Worker`: `completed` + `updated_at` часами PHP). */
    private function complete(int $taskId, int $ago = 0): void
    {
        $this->conn->table('character_tasks')->where('id', $taskId)->update([
            'status' => 'completed', 'updated_at' => date('Y-m-d H:i:s', time() - $ago),
        ]);
    }

    /** Сдвинуть конец задачи и все входящие персонажа на `$seconds` в прошлое. */
    private function shiftBack(int $charId, int $taskId, int $seconds): void
    {
        $this->conn->query('UPDATE character_tasks SET updated_at = updated_at - INTERVAL ? SECOND WHERE id = ?', [$seconds, $taskId]);
        $this->conn->query('UPDATE web_inbox SET created_at = created_at - INTERVAL ? SECOND WHERE character_id = ?', [$seconds, $charId]);
    }

    /** @param list<array<string,mixed>> $screen */
    private function assertNeutral(array $screen): void
    {
        $this->assertCount(1, $screen);
        $this->assertSame('✅ Задача «Сбор» завершена — итог во входящих 🔔', $screen[0]['text']);
        $this->assertSame([], $screen[0]['inline_keyboard'], 'без «❌ Прервать»');
        $this->assertNull($screen[0]['photo_url']);
    }

    /** @param array<string,mixed> $state */
    private function renderCurrent(array $state): string
    {
        helper(['url', 'form']);
        $html = view('site/_play/state', ['state' => $state, 'alert' => null]);
        // Текущий экран — до ленты истории (там прежний «начался» остаётся законно).
        $current = strstr($html, 'class="play-history"', true);
        $this->assertIsString($current);

        return $current;
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
