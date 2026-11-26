<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Logging\TelegramDeliveryProbe;
use App\Services\Notifications\BroadcastService;
use App\Services\Telegram\Request;
use App\Services\Telegram\TelegramBridge;
use App\Services\Web\DeliveryContext;
use App\Services\Web\VirtualChat;
use App\Services\Web\WebDelivery;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\TestLogger;
use Config\Database;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Longman\TelegramBot\Request as LongmanRequest;
use Longman\TelegramBot\Telegram;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Http\Message\RequestInterface;

/** Общий журнал TestLogger (все `log_message` теста). */
final class BroadcastBlockedLogTap extends TestLogger
{
    /** @return list<array{level: mixed, message: string, file: string|null}> */
    public static function all(): array
    {
        return self::$op_logs;
    }
}

/**
 * B16 (спека b16-blocked-recipients-1126, S2): админ-рассылка `BroadcastService::broadcastToAll` не шлёт в
 * Telegram игроку с `telegram_users.blocked_at`. Пропущенный считается в счётчике `blocked`, ERROR не пишется,
 * дата метки не меняется. Обычный игрок получает как раньше, виртуальный чат `/play` — во «входящие».
 *
 * Отдельные процессы: рассылка шлёт настоящим `Request`, а Longman под `PHPUNIT_TESTSUITE` (его определяют
 * соседние тесты) отвечает фейком, не доходя до заглушки сети.
 *
 * @internal
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class BroadcastServiceBlockedTest extends CIUnitTestCase
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

    private const PLAYER = 555000911;

    private const BLOCKED = 555000912;

    private BaseConnection $conn;

    /** @var list<array{method:string, body:string}> вызовы Bot API, дошедшие до заглушки сети */
    private array $sent = [];

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
        // BroadcastService сам строит `Telegram` из окружения: ключ теста, а не из `.env` машины.
        putenv('telegram.API_KEY=123456:TEST_TOKEN');
        putenv('telegram.BOT_USERNAME=wildworldtest_bot');

        TelegramBridge::ensure();
        TelegramDeliveryProbe::install();
        Request::initialize(new Telegram('123456:TEST_TOKEN', 'wildworldtest_bot'));
        $this->sent = [];
        LongmanRequest::setClient(new Client(['handler' => function (RequestInterface $request): PromiseInterface {
            $this->sent[] = ['method' => basename($request->getUri()->getPath()), 'body' => (string) $request->getBody()];

            return Create::promiseFor(new Response(200, [], '{"ok":true,"result":{"message_id":1,"date":1,"chat":{"id":1,"type":"private"}}}'));
        }]));

        $this->logStart = count(BroadcastBlockedLogTap::all());
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

    public function testBroadcastToAllSkipsBlockedCountsItBlockedAndLogsNoError(): void
    {
        $this->botOnly(self::PLAYER, null);
        $this->botOnly(self::BLOCKED, self::MARK);
        [$virtualChar] = $this->webOnly();

        $result = (new BroadcastService())->broadcastToAll('Обновление мира');

        $this->assertCount(1, $this->sent, 'Telegram вызван ровно для одного реального незаблокированного игрока');
        $this->assertSame('sendMessage', $this->sent[0]['method']);
        $this->assertStringContainsString((string) self::PLAYER, $this->sent[0]['body']);
        $this->assertSame(
            ['sent' => 2, 'blocked' => 1, 'failed' => 0, 'total' => 3],
            $result,
            'заблокированный в счётчике blocked; обычный и виртуальный — доставлены'
        );
        $this->assertSame([], $this->errors(), 'пропуск заблокированного не пишет ERROR');
        $this->assertSame(self::MARK, $this->blockedAt(self::BLOCKED), 'дата метки не переписана');
        $this->assertCount(1, $this->inbox($virtualChar), 'веб-игрок получил рассылку во «входящие» /play');
    }

    public function testBroadcastToBlockedIdsWithPhotoSkipsTransport(): void
    {
        $this->botOnly(self::BLOCKED, self::MARK);
        $photo = FCPATH . 'uploads/logo-concepts/logo-1-monogram.jpg';
        $this->assertFileExists($photo);

        $result = (new BroadcastService())->broadcastTo([(string) self::BLOCKED], 'Обновление мира', $photo);

        $this->assertSame([], $this->sent, 'ни фото, ни текст в Telegram не ушли');
        $this->assertSame(['sent' => 0, 'blocked' => 1, 'failed' => 0, 'total' => 1], $result);
        $this->assertSame([], $this->errors());
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** @return list<string> */
    private function errors(): array
    {
        $lines = array_filter(
            array_slice(BroadcastBlockedLogTap::all(), $this->logStart),
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
