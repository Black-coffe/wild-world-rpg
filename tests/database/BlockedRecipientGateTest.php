<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Logging\TelegramDeliveryProbe;
use App\Services\Notifications\BlockedRecipientGate;
use App\Services\Telegram\Request;
use App\Services\Telegram\TelegramBridge;
use App\Services\Web\DeliveryContext;
use App\Services\Web\VirtualChat;
use App\Services\Web\WebDelivery;
use App\TaskHandlers\BaseTaskHandler;
use App\TaskHandlers\Objects\BaseObjectHandler;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\TestLogger;
use Config\Database;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request as LongmanRequest;
use Longman\TelegramBot\Telegram;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Http\Message\RequestInterface;

/** Общий журнал TestLogger (все `log_message` теста). */
final class BlockedGateLogTap extends TestLogger
{
    /** @return list<array{level: mixed, message: string, file: string|null}> */
    public static function all(): array
    {
        return self::$op_logs;
    }
}

/** Шпион настоящей отправки: дошёл ли вызов до Telegram (Longman). */
final class BlockedGateSpyRequest extends Request
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

/** Пробный крон на базе `BaseTaskHandler`: игровое действие в БД, затем уведомление. */
final class BlockedGateCronProbe extends BaseTaskHandler
{
    public int $characterId = 0;

    public int $chatId = 0;

    public string $photoPath = '';

    public function handle(array $task = []): void
    {
        \Config\Database::connect()->query('UPDATE characters SET health = health - 10 WHERE id = ?', [$this->characterId]);
        $this->safeSendMessage($this->chatId, 'Голод: −10 HP');
        $this->safeSendPhoto($this->chatId, $this->photoPath, 'Голод: −10 HP');
    }
}

/** Пробный object-handler: те же safeSend* второй базы. */
final class BlockedGateObjectProbe extends BaseObjectHandler
{
    public function run(int $chatId, string $photoPath): void
    {
        $this->safeSendMessage($chatId, 'Находка');
        $this->safeSendPhoto($chatId, $photoPath, 'Находка');
    }
}

/** Три фоновых отправителя вне кронов: открыт их собственный `send`. */
final class BlockedGatePassiveProbe extends \App\Services\Events\PassiveHealthWarning
{
    public function run(int $chatId): void
    {
        $this->send($chatId, 'Здоровье низкое', ['inline_keyboard' => []]);
    }
}

final class BlockedGateFloorProbe extends \App\Services\Events\GatherFloorInterrupt
{
    public function run(int $chatId): void
    {
        $this->send($chatId, 'Добыча прервана', ['inline_keyboard' => []]);
    }
}

final class BlockedGateDetectionProbe extends \App\Services\Player\PlayerDetectionService
{
    /** Без родительского конструктора: тот переинициализирует Telegram ключом окружения. */
    public function __construct()
    {
    }

    public function run(int $chatId): bool
    {
        return $this->sendDetectionNotification($chatId, ['text' => 'Рядом игрок', 'keyboard' => ['inline_keyboard' => []]]);
    }
}

/**
 * B16 (спека b16-blocked-recipients-1126, S1): фоновая отправка игроку с `telegram_users.blocked_at`
 * не уходит в Telegram — пропуск в `App\Services\Telegram\Request` после `WebDelivery::route()` и до
 * транспорта. Копия во «входящие» `/play` и виртуальные чаты — как раньше; ответ на апдейт (actor есть)
 * уходит всегда; игровое действие крона выполняется, пропуск не пишет ERROR.
 *
 * Отдельные процессы: пробный крон шлёт настоящим `Request`, а Longman под `PHPUNIT_TESTSUITE`
 * (его определяют соседние тесты) отвечает фейком, не доходя до заглушки сети.
 *
 * @internal
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class BlockedRecipientGateTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const MIGRATIONS = [
        '2024-03-17-222643_CreateBiomesTable',
        '2024-03-18-105708_CreateMapTable',
        '2024-03-20-153728_CreateTelegramUsersTable',
        '2024-03-20-154155_CreateCharactersTable',
        '2026-05-08-220000_AddDisableMediaFlag',
        '2026-05-19-100000_CreateGameSettingsTable',
        '2026-06-16-130000_AddBlockedAtToTelegramUsers',
        '2026-07-17-100000_E6AddLastSeenToTelegramUsers',
        '2026-12-10-100001_CreateAccountsTables',
        '2026-12-10-100002_LinkCharactersToAccounts',
        '2026-12-10-100010_NullableTelegramKeys',
        '2026-12-11-100001_CreateWebPlayTables',
        '2026-12-11-100005_SignedTelegramIdColumns',
    ];

    private const TABLES = [
        'biomes', 'map', 'telegram_users', 'accounts', 'account_identities', 'account_tokens', 'account_link_codes',
        'characters', 'game_settings', 'web_play_state', 'web_inbox', 'web_play_intents',
    ];

    private const FLAG_CACHE = 'game_settings_web_play_enabled';

    private const MARK = '2026-11-01 10:00:00';

    private const PLAYER = 555000901;

    private const OTHER = 555000902;

    private const GROUP = -100200300;

    private BaseConnection $conn;

    /** @var list<string> методы Bot API, дошедшие до заглушки сети */
    private array $sent = [];

    /** @var list<callable(): PromiseInterface> ответы заглушки по очереди */
    private array $answers = [];

    private int $logStart = 0;

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
        service('cache')->save(self::FLAG_CACHE, ['v' => true, 't' => 'bool'], 60);
        WebDelivery::reset();
        DeliveryContext::reset();
        BlockedGateSpyRequest::$calls = [];

        TelegramBridge::ensure();
        TelegramDeliveryProbe::install();
        Request::initialize(new Telegram('123456:TEST_TOKEN', 'wildworldtest_bot'));
        $this->sent    = [];
        $this->answers = [];
        LongmanRequest::setClient(new Client(['handler' => function (RequestInterface $request): PromiseInterface {
            $this->sent[] = basename($request->getUri()->getPath());
            $next         = array_shift($this->answers);

            return $next !== null ? $next() : Create::promiseFor(new Response(200, [], '{"ok":true,"result":true}'));
        }]));

        $this->logStart = count(BlockedGateLogTap::all());
    }

    protected function tearDown(): void
    {
        TelegramDeliveryProbe::reset();
        WebDelivery::reset();
        DeliveryContext::reset();
        service('cache')->clean();
        $this->dropTables();
        parent::tearDown();
    }

    // ── Ask 1: фоновая отправка заблокировавшему не уходит в Telegram ────

    public function testBackgroundSendToBlockedRealChatSkipsTransport(): void
    {
        $this->botOnly(self::PLAYER, self::MARK);
        $this->botOnly(self::OTHER, null);

        $r = BlockedGateSpyRequest::sendMessage(['chat_id' => self::PLAYER, 'text' => 'Голод']);
        $p = BlockedGateSpyRequest::sendPhoto(['chat_id' => (string) self::PLAYER, 'photo' => 'https://example.org/x.png', 'caption' => 'Голод']);
        $e = BlockedGateSpyRequest::editMessageText(['chat_id' => self::PLAYER, 'message_id' => 5, 'text' => 'правка']);

        $this->assertSame([], BlockedGateSpyRequest::$calls, 'Telegram не вызван ни для send, ни для фото, ни для правки');
        foreach ([$r, $p, $e] as $resp) {
            $this->assertFalse($resp->isOk());
            $this->assertTrue(BlockedRecipientGate::isSkipped($resp), 'ответ — узнаваемый пропуск');
            $this->assertSame(BlockedRecipientGate::SKIPPED_DESCRIPTION, $resp->getDescription());
        }

        BlockedGateSpyRequest::sendMessage(['chat_id' => self::OTHER, 'text' => 'Голод']);
        $this->assertCount(1, BlockedGateSpyRequest::$calls, 'незаблокированный игрок получает как раньше');
        $this->assertSame(self::OTHER, BlockedGateSpyRequest::$calls[0][1]['chat_id']);
    }

    public function testGroupChatIsNeverSkipped(): void
    {
        $this->botOnly(self::GROUP, self::MARK);

        BlockedGateSpyRequest::sendMessage(['chat_id' => self::GROUP, 'text' => 'Сводка']);

        $this->assertCount(1, BlockedGateSpyRequest::$calls, 'группа (отрицательный id) не фильтруется');
    }

    public function testBlockedSetIsReadOnceAndAnswerCallbackIsUntouched(): void
    {
        $this->botOnly(self::PLAYER, self::MARK);
        $this->botOnly(self::OTHER, null);
        $reads = 0;
        Events::on('DBQuery', static function (Query $q) use (&$reads): void {
            if (str_contains($q->getQuery(), 'blocked_at')) {
                $reads++;
            }
        });

        BlockedGateSpyRequest::sendMessage(['chat_id' => self::PLAYER, 'text' => 'a']);
        BlockedGateSpyRequest::sendMessage(['chat_id' => self::OTHER, 'text' => 'b']);
        BlockedGateSpyRequest::sendMessage(['chat_id' => self::PLAYER, 'text' => 'c']);
        BlockedGateSpyRequest::answerCallbackQuery(['callback_query_id' => '1']);

        $this->assertSame(1, $reads, 'набор заблокированных читается одним запросом на процесс');
        $this->assertSame(['sendMessage', 'answerCallbackQuery'], array_column(BlockedGateSpyRequest::$calls, 0));
    }

    // ── Ask 3: «входящие» /play и виртуальные чаты — как раньше ──────────

    public function testLinkedLoggedInBlockedPlayerGetsInboxRowButNoTelegram(): void
    {
        $charId = $this->linked(self::PLAYER, self::MARK);

        $r = BlockedGateSpyRequest::sendMessage(['chat_id' => self::PLAYER, 'text' => 'Поход завершён']);

        $this->assertTrue(BlockedRecipientGate::isSkipped($r));
        $this->assertSame([], BlockedGateSpyRequest::$calls, 'Telegram не вызван');
        $rows = $this->inbox($charId);
        $this->assertCount(1, $rows, 'копия во входящие /play записана');
        $this->assertSame('mirror', $rows[0]['source']);
    }

    public function testVirtualChatBehavesAsBefore(): void
    {
        [$charId, $chat] = $this->webOnly();
        $this->conn->table('telegram_users')->where('telegram_id', $chat)->update(['blocked_at' => self::MARK]);

        $r = BlockedGateSpyRequest::sendMessage(['chat_id' => $chat, 'text' => 'Голод']);

        $this->assertTrue($r->isOk(), 'виртуальный чат отвечает ok, как раньше');
        $this->assertFalse(BlockedRecipientGate::isSkipped($r));
        $this->assertSame([], BlockedGateSpyRequest::$calls);
        $rows = $this->inbox($charId);
        $this->assertCount(1, $rows);
        $this->assertSame('virtual', $rows[0]['source']);
    }

    // ── Ответ на апдейт игрока (actor есть) уходит всегда ────────────────

    public function testReplyToActorIsSentEvenWhenMarked(): void
    {
        $this->botOnly(self::PLAYER, self::MARK);
        DeliveryContext::setActor(self::PLAYER);

        $r = BlockedGateSpyRequest::sendMessage(['chat_id' => self::PLAYER, 'text' => 'Привет снова']);

        $this->assertTrue($r->isOk());
        $this->assertCount(1, BlockedGateSpyRequest::$calls, 'ответ на апдейт уходит в Telegram');
    }

    // ── Ask 4: игровое действие крона выполняется, ERROR нет ─────────────

    public function testCronDoesItsGameWriteAndSkipsSendWithoutError(): void
    {
        $charId = $this->botOnly(self::PLAYER, self::MARK);
        $probe  = new BlockedGateCronProbe();
        $probe->characterId = $charId;
        $probe->chatId      = self::PLAYER;
        $probe->photoPath   = $this->photo();

        $probe->handle();

        $this->assertSame(90, $this->health($charId), 'игровое действие крона выполнено');
        $this->assertSame([], $this->sent, 'ни текст, ни фото, ни текст-деградация фото в Telegram не ушли');
        $this->assertSame([], $this->levels(BlockedGateCronProbe::class), 'пропуск не пишет ни ERROR, ни WARNING отправителя');
        $this->assertSame(self::MARK, $this->blockedAt(self::PLAYER), 'метка не переписана');
        $this->assertSame([], $this->errors(), 'ни одной ERROR-строки');
    }

    public function testObjectHandlerSkipsSendWithoutLog(): void
    {
        $this->botOnly(self::PLAYER, self::MARK);

        (new BlockedGateObjectProbe())->run(self::PLAYER, $this->photo());

        $this->assertSame([], $this->sent);
        $this->assertSame([], $this->levels(BlockedGateObjectProbe::class));
        $this->assertSame([], $this->errors());
    }

    public function testBackgroundSendersOutsideCronsSkipWithoutError(): void
    {
        $this->botOnly(self::PLAYER, self::MARK);

        (new BlockedGatePassiveProbe())->run(self::PLAYER);
        (new BlockedGateFloorProbe())->run(self::PLAYER);
        (new BlockedGateDetectionProbe())->run(self::PLAYER);

        $this->assertSame([], $this->sent, 'ни одна из трёх отправок не ушла в Telegram');
        $this->assertSame([], $this->errors(), 'пропуск не пишет ERROR ни в одном из трёх отправителей');
    }

    public function testMarkSetByBlockedRecipientInSameProcessSkipsNextSend(): void
    {
        $charId = $this->botOnly(self::PLAYER, null);
        $probe  = new BlockedGateCronProbe();
        $probe->characterId = $charId;
        $probe->chatId      = self::PLAYER;
        $probe->photoPath   = $this->photo();
        $this->answers[]    = static fn (): PromiseInterface => Create::promiseFor(new Response(403, [], (string) json_encode([
            'ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user',
        ])));

        $probe->handle();

        $this->assertNotNull($this->blockedAt(self::PLAYER), 'первый отказ ставит метку');
        $this->assertSame(['sendMessage'], $this->sent, 'фото после метки этого же прогона в Telegram не ушло');
        $this->assertSame(90, $this->health($charId), 'игровое действие выполнено');
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function health(int $charId): int
    {
        $row = $this->conn->table('characters')->where('id', $charId)->get()->getRowArray();
        $this->assertIsArray($row);

        return (int) $row['health'];
    }

    private function photo(): string
    {
        $path = FCPATH . 'uploads/logo-concepts/logo-1-monogram.jpg';
        $this->assertFileExists($path);

        return $path;
    }

    /** @return list<string> */
    private function levels(string $class): array
    {
        $prefix = '[' . $class . ']';
        $lines  = array_filter(
            array_slice(BlockedGateLogTap::all(), $this->logStart),
            static fn (array $l): bool => str_starts_with($l['message'], $prefix)
        );

        return array_values(array_map(static fn (array $l): string => strtolower((string) $l['level']), $lines));
    }

    /** @return list<string> */
    private function errors(): array
    {
        $lines = array_filter(
            array_slice(BlockedGateLogTap::all(), $this->logStart),
            static fn (array $l): bool => in_array(strtolower((string) $l['level']), ['error', 'critical', 'alert', 'emergency'], true)
        );

        return array_values(array_map(static fn (array $l): string => $l['message'], $lines));
    }

    private function blockedAt(int $telegramId): ?string
    {
        $row = $this->conn->table('telegram_users')->where('telegram_id', $telegramId)->get()->getRowArray();
        $this->assertIsArray($row);
        $value = $row['blocked_at'] ?? null;

        return is_string($value) ? $value : null;
    }

    /** @return array{0:int, 1:int} [character id, виртуальный chat id] */
    private function webOnly(): array
    {
        $this->conn->table('accounts')->insert(['acquisition_source' => 'web', 'created_at' => date('Y-m-d H:i:s')]);
        $accountId = (int) $this->conn->insertID();
        $chat      = VirtualChat::idForAccount($accountId);
        $this->assertTrue(VirtualChat::is($chat));

        return [$this->character($this->telegramUser($chat, null), $accountId), $chat];
    }

    private function linked(int $chat, ?string $blockedAt): int
    {
        $this->conn->table('accounts')->insert([
            'acquisition_source' => 'telegram',
            'created_at'         => date('Y-m-d H:i:s'),
            'last_login_at'      => date('Y-m-d H:i:s'),
        ]);
        $accountId = (int) $this->conn->insertID();

        return $this->character($this->telegramUser($chat, $blockedAt), $accountId);
    }

    private function botOnly(int $chat, ?string $blockedAt): int
    {
        return $this->character($this->telegramUser($chat, $blockedAt), null);
    }

    private function telegramUser(int $telegramId, ?string $blockedAt): int
    {
        $now = date('Y-m-d H:i:s');
        $this->conn->table('telegram_users')->insert([
            'telegram_id' => $telegramId, 'blocked_at' => $blockedAt, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return (int) $this->conn->insertID();
    }

    private function character(int $tuId, ?int $accountId): int
    {
        $this->conn->table('characters')->insert([
            'telegram_user_id' => $tuId, 'account_id' => $accountId, 'name' => 'Игрок' . $tuId,
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
