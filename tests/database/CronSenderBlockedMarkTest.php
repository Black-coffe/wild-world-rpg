<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Logging\TelegramDeliveryProbe;
use App\Services\Notifications\BlockedRecipient;
use App\Services\Telegram\Request;
use App\Services\Telegram\TelegramBridge;
use App\Services\Web\VirtualChat;
use App\TaskHandlers\BaseTaskHandler;
use App\TaskHandlers\Objects\BaseObjectHandler;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\TestLogger;
use Config\Database;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Longman\TelegramBot\Request as LongmanRequest;
use Longman\TelegramBot\Telegram;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;

/** Читает общий журнал TestLogger (все `log_message` теста). */
final class CronSenderLogTap extends TestLogger
{
    /** @return list<array{level: mixed, message: string, file: string|null}> */
    public static function all(): array
    {
        return self::$op_logs;
    }
}

/** Наследник крон-базы: зовёт её собственные safeSend*, ничего не подменяя. */
final class BlockedMarkTaskProbe extends BaseTaskHandler
{
    public function handle(array $task = []): void
    {
    }

    public function message(int $chatId, string $text): string
    {
        $this->safeSendMessage($chatId, $text);

        return 'after';
    }

    public function photo(int $chatId, string $photoPath, string $caption): string
    {
        $this->safeSendPhoto($chatId, $photoPath, $caption);

        return 'after';
    }
}

/** Наследник базы object-handler'ов: её собственные safeSend*. */
final class BlockedMarkObjectProbe extends BaseObjectHandler
{
    public function message(int $chatId, string $text): string
    {
        $this->safeSendMessage($chatId, $text);

        return 'after';
    }

    public function photo(int $chatId, string $photoPath, string $caption): string
    {
        $this->safeSendPhoto($chatId, $photoPath, $caption);

        return 'after';
    }
}

/**
 * B16, вторая половина (спека blocked-mark-1124, S2): общие крон-отправщики
 * (`BaseTaskHandler` / `BaseObjectHandler` safeSend*) помечают заблокировавшего бота игрока
 * (`telegram_users.blocked_at`) одной WARNING-строкой вместо ERROR и не повторяют фото текстом.
 * Остальные отказы — как раньше, никого не помечают. Telegram — заглушка Guzzle-клиента Longman.
 */
final class CronSenderBlockedMarkTest extends CIUnitTestCase
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
        '2026-12-11-100005_SignedTelegramIdColumns',
    ];

    private const TABLES = [
        'biomes', 'map', 'telegram_users', 'accounts', 'account_identities', 'account_tokens', 'account_link_codes',
        'characters', 'game_settings',
    ];

    private const PLAYER = 555000777;

    private const GROUP = -100200300;

    private const OLD_MARK = '2026-11-01 10:00:00';

    private const BLOCKED = 'Forbidden: bot was blocked by the user';

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

        // Мост и probe поднимаются заранее (их повторный подъём — no-op), затем сеть закрывается
        // заглушкой: ни один запрос не уходит в api.telegram.org.
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

        $this->logStart = count(CronSenderLogTap::all());
    }

    protected function tearDown(): void
    {
        TelegramDeliveryProbe::reset();
        service('cache')->clean();
        $this->dropTables();
        parent::tearDown();
    }

    // ── Ask 2, 3: блок на тексте ─────────────────────────────────────────

    /** @return array<string, array{string}> */
    public static function blockAnswers(): array
    {
        return [
            'бот заблокирован' => [self::BLOCKED],
            'аккаунт удалён'   => ['Forbidden: user is deactivated'],
            'регистр'          => ['FORBIDDEN: BOT WAS BLOCKED BY THE USER'],
        ];
    }

    #[DataProvider('blockAnswers')]
    public function testTaskTextToBlockedPlayerMarksAndWarnsOnce(string $description): void
    {
        $this->makeTelegramUser(self::PLAYER);
        $this->answer(403, $description);

        $this->assertSame('after', (new BlockedMarkTaskProbe())->message(self::PLAYER, 'Добыча завершена'));

        $this->assertNotNull($this->blockedAt(self::PLAYER), 'игрок помечен');
        $this->assertSame(['sendMessage'], $this->sent);
        $this->assertSame(['warning'], $this->levels(BlockedMarkTaskProbe::class), 'одна WARNING, без ERROR');
    }

    // ── Ask 3: блок на фото — без повтора текстом ────────────────────────

    public function testTaskPhotoToBlockedPlayerMarksWithoutTextRetry(): void
    {
        $this->makeTelegramUser(self::PLAYER);
        $this->answer(403, self::BLOCKED);

        (new BlockedMarkTaskProbe())->photo(self::PLAYER, $this->photo(), 'Подпись');

        $this->assertNotNull($this->blockedAt(self::PLAYER));
        $this->assertSame(['sendPhoto'], $this->sent, 'один вызов Telegram, текстом не повторяем');
        $this->assertSame(['warning'], $this->levels(BlockedMarkTaskProbe::class));
    }

    public function testTaskPhotoFailsThenTextFallbackBlockedMarksOnce(): void
    {
        $this->makeTelegramUser(self::PLAYER);
        $this->answer(400, 'Bad Request: IMAGE_PROCESS_FAILED');
        $this->answer(403, self::BLOCKED);

        (new BlockedMarkTaskProbe())->photo(self::PLAYER, $this->photo(), 'Подпись');

        $this->assertNotNull($this->blockedAt(self::PLAYER));
        $this->assertSame(['sendPhoto', 'sendMessage'], $this->sent);
        $this->assertSame(['error', 'warning'], $this->levels(BlockedMarkTaskProbe::class), 'провал фото как раньше, блок текста — WARNING');
    }

    public function testTaskMissingPhotoTextToBlockedPlayerMarks(): void
    {
        $this->makeTelegramUser(self::PLAYER);
        $this->answer(403, self::BLOCKED);

        (new BlockedMarkTaskProbe())->photo(self::PLAYER, FCPATH . 'uploads/no-such-file-blocked-mark.png', 'Подпись');

        $this->assertNotNull($this->blockedAt(self::PLAYER));
        $this->assertSame(['sendMessage'], $this->sent);
        $this->assertSame(['warning', 'warning'], $this->levels(BlockedMarkTaskProbe::class), 'photo missing + блок, без ERROR');
    }

    // ── Ask 4: прочие отказы как раньше ──────────────────────────────────

    /** @return array<string, array{int, string}> */
    public static function otherRefusals(): array
    {
        return [
            '429'             => [429, 'Too Many Requests: retry after 5'],
            'chat not found'  => [400, 'Bad Request: chat not found'],
            "can't initiate"  => [403, "Forbidden: bot can't initiate conversation with a user"],
            'голый Forbidden' => [403, 'Forbidden'],
        ];
    }

    #[DataProvider('otherRefusals')]
    public function testTaskTextOtherRefusalDoesNotMarkAndLogsErrorAsBefore(int $code, string $description): void
    {
        $this->makeTelegramUser(self::PLAYER);
        $this->answer($code, $description);

        (new BlockedMarkTaskProbe())->message(self::PLAYER, 'Текст');

        $this->assertNull($this->blockedAt(self::PLAYER), 'не помечен');
        $this->assertSame(['error'], $this->levels(BlockedMarkTaskProbe::class));
        $this->assertLogged('error', '[' . BlockedMarkTaskProbe::class . '] Telegram sendMessage not ok: ' . $description);
    }

    public function testTaskTextExceptionDoesNotMarkAndLogsError(): void
    {
        $this->makeTelegramUser(self::PLAYER);
        $this->answers[] = static fn (): PromiseInterface => throw new ConnectException('network down', new \GuzzleHttp\Psr7\Request('POST', '/'));

        $this->assertSame('after', (new BlockedMarkTaskProbe())->message(self::PLAYER, 'Текст'));

        $this->assertNull($this->blockedAt(self::PLAYER));
        $this->assertSame(['error'], $this->levels(BlockedMarkTaskProbe::class));
        $this->assertLogged('error', '[' . BlockedMarkTaskProbe::class . '] sendMessage exception: network down');
    }

    public function testTaskPhotoOtherRefusalStillFallsBackToText(): void
    {
        $this->makeTelegramUser(self::PLAYER);
        $this->answer(403, 'Forbidden');

        (new BlockedMarkTaskProbe())->photo(self::PLAYER, $this->photo(), 'Подпись');

        $this->assertNull($this->blockedAt(self::PLAYER));
        $this->assertSame(['sendPhoto', 'sendMessage'], $this->sent, 'не-блок фото → текст, как раньше');
        $this->assertSame(['error'], $this->levels(BlockedMarkTaskProbe::class));
    }

    // ── Ask 4: уже помеченный — дата не переписывается; ask 10 — отправка всё равно идёт ──

    public function testAlreadyMarkedPlayerIsStillSentAndKeepsMarkDate(): void
    {
        $this->makeTelegramUser(self::PLAYER, self::OLD_MARK);
        $this->answer(403, self::BLOCKED);

        (new BlockedMarkTaskProbe())->message(self::PLAYER, 'Текст');

        $this->assertSame(['sendMessage'], $this->sent, 'помеченного не пропускаем');
        $this->assertSame(self::OLD_MARK, $this->blockedAt(self::PLAYER), 'дата метки не переписана');
        $this->assertSame(['warning'], $this->levels(BlockedMarkTaskProbe::class));
    }

    public function testAlreadyMarkedPlayerSuccessfulSendLeavesMark(): void
    {
        $this->makeTelegramUser(self::PLAYER, self::OLD_MARK);

        (new BlockedMarkTaskProbe())->message(self::PLAYER, 'Текст');

        $this->assertSame(['sendMessage'], $this->sent);
        $this->assertSame(self::OLD_MARK, $this->blockedAt(self::PLAYER), 'успех метку здесь не снимает');
        $this->assertSame([], $this->levels(BlockedMarkTaskProbe::class));
    }

    // ── Ask 4: сбой записи метки залогирован ─────────────────────────────

    public function testMarkWriteFailureIsLoggedAndHandlerGoesOn(): void
    {
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 0');
        $this->conn->query('DROP TABLE `telegram_users`');
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
        $this->answer(403, self::BLOCKED);

        $this->assertSame('after', (new BlockedMarkTaskProbe())->message(self::PLAYER, 'Текст'));

        $lines = $this->lines(BlockedMarkTaskProbe::class);
        $this->assertCount(1, $lines, 'одна строка на отправку');
        $this->assertSame('error', $lines[0]['level']);
        $this->assertStringContainsString('mark failed', $lines[0]['message']);
        $this->assertStringContainsString('telegram_users', $lines[0]['message'], 'причина в строке');
    }

    // ── Ask 5: группа и виртуальный чат не помечаются ────────────────────

    public function testGroupChatIdIsNeverMarked(): void
    {
        $this->makeTelegramUser(self::GROUP);
        $this->answer(403, self::BLOCKED);

        (new BlockedMarkTaskProbe())->message(self::GROUP, 'Текст');

        $this->assertNull($this->blockedAt(self::GROUP), 'группа не помечена');
        $this->assertSame(['error'], $this->levels(BlockedMarkTaskProbe::class), 'лог как раньше');
    }

    public function testVirtualChatIdIsNeverMarked(): void
    {
        $virtual = -(VirtualChat::VIRTUAL_BASE + 42);
        $this->assertTrue(VirtualChat::is($virtual));
        $this->makeTelegramUser($virtual);

        $this->assertFalse(BlockedRecipient::handle($virtual, self::BLOCKED, '[probe]', 'sendMessage'), 'helper: виртуальный — не помечаемый');
        $this->assertFalse(BlockedRecipient::handle(self::GROUP, self::BLOCKED, '[probe]', 'sendMessage'), 'helper: группа — не помечаемая');

        $this->answer(403, self::BLOCKED);
        (new BlockedMarkTaskProbe())->message($virtual, 'Текст');

        $this->assertNull($this->blockedAt($virtual));
        $this->assertSame([], $this->sent, 'виртуальный чат в сеть не ходит');
    }

    // ── Ask 2–4: BaseObjectHandler своими отправщиками ───────────────────

    public function testObjectTextToBlockedPlayerMarksAndWarnsOnce(): void
    {
        $this->makeTelegramUser(self::PLAYER);
        $this->answer(403, self::BLOCKED);

        $this->assertSame('after', (new BlockedMarkObjectProbe())->message(self::PLAYER, 'Находка'));

        $this->assertNotNull($this->blockedAt(self::PLAYER));
        $this->assertSame(['warning'], $this->levels(BlockedMarkObjectProbe::class));
        $this->assertLogged('warning', '[' . BlockedMarkObjectProbe::class . '] Telegram sendMessage: recipient ' . self::PLAYER . ' blocked the bot, marked: ' . self::BLOCKED);
    }

    public function testObjectPhotoToBlockedPlayerMarks(): void
    {
        $this->makeTelegramUser(self::PLAYER);
        $this->answer(403, self::BLOCKED);

        (new BlockedMarkObjectProbe())->photo(self::PLAYER, $this->photo(), 'Находка');

        $this->assertNotNull($this->blockedAt(self::PLAYER));
        $this->assertSame(['sendPhoto'], $this->sent);
        $this->assertSame(['warning'], $this->levels(BlockedMarkObjectProbe::class));
    }

    #[DataProvider('otherRefusals')]
    public function testObjectOtherRefusalDoesNotMarkAndLogsAsBefore(int $code, string $description): void
    {
        $this->makeTelegramUser(self::PLAYER);
        $this->answer($code, $description);

        (new BlockedMarkObjectProbe())->message(self::PLAYER, 'Находка');

        $this->assertNull($this->blockedAt(self::PLAYER));
        $this->assertSame(['warning'], $this->levels(BlockedMarkObjectProbe::class));
        $this->assertLogged('warning', '[' . BlockedMarkObjectProbe::class . '] Telegram sendMessage not ok: ' . $description);
    }

    public function testObjectExceptionDoesNotMarkAndLogsError(): void
    {
        $this->makeTelegramUser(self::PLAYER);
        $this->answers[] = static fn (): PromiseInterface => throw new ConnectException('network down', new \GuzzleHttp\Psr7\Request('POST', '/'));

        (new BlockedMarkObjectProbe())->message(self::PLAYER, 'Находка');

        $this->assertNull($this->blockedAt(self::PLAYER));
        $this->assertSame(['error'], $this->levels(BlockedMarkObjectProbe::class));
    }

    // ── Фикстура ─────────────────────────────────────────────────────────

    private function answer(int $code, string $description): void
    {
        $body            = (string) json_encode(['ok' => false, 'error_code' => $code, 'description' => $description]);
        $this->answers[] = static fn (): PromiseInterface => Create::promiseFor(new Response($code, [], $body));
    }

    private function photo(): string
    {
        $path = FCPATH . 'uploads/logo-concepts/logo-1-monogram.jpg';
        $this->assertFileExists($path);

        return $path;
    }

    /** @return list<array{level: mixed, message: string, file: string|null}> строки этого теста от класса */
    private function lines(string $class): array
    {
        $prefix = '[' . $class . ']';

        return array_values(array_filter(
            array_slice(CronSenderLogTap::all(), $this->logStart),
            static fn (array $l): bool => str_starts_with($l['message'], $prefix)
        ));
    }

    /** @return list<string> */
    private function levels(string $class): array
    {
        return array_map(static fn (array $l): string => strtolower((string) $l['level']), $this->lines($class));
    }

    private function blockedAt(int $telegramId): ?string
    {
        $row = $this->conn->table('telegram_users')->where('telegram_id', $telegramId)->get()->getRowArray();
        $this->assertIsArray($row);
        $value = $row['blocked_at'] ?? null;

        return is_string($value) ? $value : null;
    }

    private function makeTelegramUser(int $telegramId, ?string $blockedAt = null): void
    {
        $now = date('Y-m-d H:i:s');
        $this->conn->table('telegram_users')->insert(['telegram_id' => $telegramId, 'blocked_at' => $blockedAt, 'created_at' => $now, 'updated_at' => $now]);
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
