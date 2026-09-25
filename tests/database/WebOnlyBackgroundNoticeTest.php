<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Player\ReferralService;
use App\Services\Player\TeleportBeacon\BeaconCaptureService;
use App\Services\Player\TeleportBeacon\BeaconMessageFormatter;
use App\Services\Player\TitleService;
use App\Services\Telegram\Request;
use App\Services\Web\DeliveryContext;
use App\Services\Web\VirtualChat;
use App\Services\Web\WebDelivery;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Longman\TelegramBot\Entities\ServerResponse;

/** Шпион транспорта: доказывает, дошёл ли вызов до `parent::send()` (Longman → Telegram). */
final class WebOnlyNoticeSpyRequest extends Request
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

/** Титулы без БД: система включена, выдача всегда успешна. */
final class WebOnlyNoticeTitles extends TitleService
{
    public function enabled(): bool
    {
        return true;
    }

    public function award(int $characterId, int $titleId): bool
    {
        return true;
    }
}

/**
 * Реферальный сервис с одним дозревшим рефералом; поиск персонажа и chat id реферрера — настоящие
 * (строки `characters` / `telegram_users`), именно там живёт проверяемый guard.
 */
final class WebOnlyNoticeReferral extends ReferralService
{
    public function __construct(private int $referrerUserId)
    {
        parent::__construct(new WebOnlyNoticeTitles());
    }

    public function enabled(): bool
    {
        return true;
    }

    protected function referralTitleId(): int
    {
        return 1;
    }

    protected function findQualifiedUnrewarded(int $qualifyLevel, int $limit): array
    {
        return [['id' => 1, 'referrer_user_id' => $this->referrerUserId, 'referred_user_id' => 999]];
    }

    protected function markRewarded(int $referralId): void
    {
    }
}

/**
 * web-bridge-p1-16 (council round 5, Ask 4) — фоновое уведомление web-only игроку доходит до шва
 * `Request::send()` (→ входящие), а не срезается проверкой знака: захват маяка
 * ({@see BeaconCaptureService::lookupOwnerTelegramId}) и награда реферреру
 * ({@see ReferralService::qualifyAndReward}). Флаг off — ни входящих, ни транспорта (A6);
 * Telegram-игрок — транспорт как раньше (Ask 5); группа — по-прежнему отказ.
 *
 * Отправка повторяет строки вызывающих: `TeleportBeaconSetAction` (`if ($oldOwnerTgId) send(...)`)
 * и `ReferralQualifyCron` (`sendMessage` на каждый notice), но через шпиона транспорта.
 *
 * @internal
 */
final class WebOnlyBackgroundNoticeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const MIGRATIONS = [
        '2024-03-20-153728_CreateTelegramUsersTable',
        '2024-03-20-154155_CreateCharactersTable',
        '2026-12-10-100001_CreateAccountsTables',
        '2026-12-10-100002_LinkCharactersToAccounts',
        '2026-12-10-100010_NullableTelegramKeys',
        '2026-12-11-100001_CreateWebPlayTables',
        '2025-02-01-105109_CreateTeleportBeacons',
    ];

    private const TABLES = [
        'telegram_users', 'accounts', 'account_identities', 'account_tokens', 'account_link_codes',
        'characters', 'web_play_state', 'web_inbox', 'web_play_intents', 'teleport_beacons',
    ];

    private const FLAG_CACHE = 'game_settings_web_play_enabled';

    private const GROUP_ID = -1001234567890;

    private BaseConnection $conn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = Database::connect();
        $this->dropTables();
        try {
            // teleport_beacons держит FK на `map`, которой тесту не нужно: FK проверки выключены
            // только на время создания схемы.
            $this->conn->query('SET FOREIGN_KEY_CHECKS = 0');
            $forge = Database::forge();
            foreach (self::MIGRATIONS as $file) {
                require_once APPPATH . 'Database/Migrations/' . $file . '.php';
                $class = 'App\\Database\\Migrations\\' . substr($file, 18);
                $m     = new $class($forge instanceof Forge ? $forge : null);
                $this->assertInstanceOf(Migration::class, $m);
                $m->up();
            }
            $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
        } catch (\Throwable $e) {
            $this->dropTables();

            throw $e;
        }
        WebDelivery::reset();
        DeliveryContext::reset();
        WebOnlyNoticeSpyRequest::$calls = [];
        $this->setFlag(true);
    }

    protected function tearDown(): void
    {
        WebDelivery::reset();
        DeliveryContext::reset();
        service('cache')->delete(self::FLAG_CACHE);
        $this->dropTables();
        parent::tearDown();
    }

    // ── Guards ───────────────────────────────────────────────────────────

    public function testBeaconOwnerLookupAcceptsPositiveOrVirtualOnly(): void
    {
        $svc = new BeaconCaptureService();
        [$webChar, $virtual] = $this->webOnly();

        $this->assertSame($virtual, $svc->lookupOwnerTelegramId($webChar));
        $this->assertSame(5550001, $svc->lookupOwnerTelegramId($this->botOnly(5550001)));
        $this->assertNull($svc->lookupOwnerTelegramId($this->botOnly(self::GROUP_ID)), 'группа — отказ');
        $this->assertNull($svc->lookupOwnerTelegramId($this->botOnly(0)), 'ноль — пропуск');
    }

    public function testReferralNoticeAcceptsPositiveOrVirtualOnly(): void
    {
        [, $virtual, $webTu] = $this->webOnly();
        $this->assertSame([$virtual], array_column((new WebOnlyNoticeReferral($webTu))->qualifyAndReward(), 'chat_id'));

        $tgTu = $this->telegramUser(5550002);
        $this->character($tgTu, null);
        $this->assertSame([5550002], array_column((new WebOnlyNoticeReferral($tgTu))->qualifyAndReward(), 'chat_id'));

        foreach ([self::GROUP_ID, 0] as $bad) {
            $tu = $this->telegramUser($bad);
            $this->character($tu, null);
            $this->assertSame([], (new WebOnlyNoticeReferral($tu))->qualifyAndReward(), "chat id {$bad} — без уведомления");
        }
    }

    // ── Flag on: web-only → inbox, no transport ──────────────────────────

    public function testCapturedBeaconAlertReachesWebOnlyOwnerInbox(): void
    {
        [$owner, $virtual] = $this->webOnly();
        $captor            = $this->botOnly(5550003);

        $this->captureAndNotify($owner, $captor);

        $rows = $this->inbox($owner);
        $this->assertCount(1, $rows);
        $this->assertSame('virtual', $rows[0]['source']);
        $this->assertNoTransportTo($virtual);
        $this->assertSame([], WebOnlyNoticeSpyRequest::$calls);
    }

    public function testReferralRewardNoticeReachesWebOnlyReferrerInbox(): void
    {
        [$charId, $virtual, $tuId] = $this->webOnly();

        $this->rewardAndNotify($tuId);

        $rows = $this->inbox($charId);
        $this->assertCount(1, $rows);
        $this->assertSame('virtual', $rows[0]['source']);
        $this->assertNoTransportTo($virtual);
        $this->assertSame([], WebOnlyNoticeSpyRequest::$calls);
    }

    // ── Flag off (A6): nothing written, nothing sent ─────────────────────

    public function testFlagOffBothNoticesWriteNoInboxAndReachNoTransport(): void
    {
        $this->setFlag(false);
        [$owner]                   = $this->webOnly();
        [$referrer, , $referrerTu] = $this->webOnly();

        $this->captureAndNotify($owner, $this->botOnly(5550004));
        $this->rewardAndNotify($referrerTu);

        $this->assertCount(0, $this->inbox($owner));
        $this->assertCount(0, $this->inbox($referrer));
        $this->assertSame([], WebOnlyNoticeSpyRequest::$calls);
    }

    // ── Telegram player (Ask 5): transport as before ─────────────────────

    public function testTelegramPlayerStillGetsBothNoticesViaTransport(): void
    {
        $owner  = $this->botOnly(5550005);
        $captor = $this->botOnly(5550006);
        $this->captureAndNotify($owner, $captor);

        $referrerTu = $this->telegramUser(5550007);
        $referrer   = $this->character($referrerTu, null);
        $this->rewardAndNotify($referrerTu);

        $chats = array_map(static fn (array $c): mixed => $c[1]['chat_id'] ?? null, WebOnlyNoticeSpyRequest::$calls);
        $this->assertSame([5550005, 5550007], $chats);
        $this->assertCount(0, $this->inbox($owner));
        $this->assertCount(0, $this->inbox($referrer));
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** Захват маяка владельца $owner персонажем $captor + уведомление, как в TeleportBeaconSetAction. */
    private function captureAndNotify(int $owner, int $captor): void
    {
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 0');
        $this->conn->table('teleport_beacons')->insert([
            'character_id' => $owner, 'player_level_at_creation' => 1, 'map_cell_id' => 1,
            'coordinate_x' => 3, 'coordinate_y' => 4, 'remaining_uses' => 10,
        ]);
        $beaconId = (int) $this->conn->insertID();
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');

        $svc    = new BeaconCaptureService();
        $result = $svc->captureBeacon($beaconId, $captor);
        $this->assertSame('captured', $result['status']);
        $this->assertIsInt($result['oldOwnerCharId']);
        $this->assertIsArray($result['beacon']);

        $oldOwnerTgId = $svc->lookupOwnerTelegramId($result['oldOwnerCharId']);
        if ($oldOwnerTgId) {
            WebOnlyNoticeSpyRequest::sendMessage(array_merge(
                ['chat_id' => $oldOwnerTgId],
                (new BeaconMessageFormatter())->oldOwnerAlert($result['beacon'])
            ));
        }
    }

    /** Награда реферреру + рассылка notices, как в ReferralQualifyCron. */
    private function rewardAndNotify(int $referrerTu): void
    {
        foreach ((new WebOnlyNoticeReferral($referrerTu))->qualifyAndReward() as $n) {
            WebOnlyNoticeSpyRequest::sendMessage(['chat_id' => $n['chat_id'], 'text' => $n['text'], 'parse_mode' => 'Markdown']);
        }
    }

    private function assertNoTransportTo(int $chat): void
    {
        foreach (WebOnlyNoticeSpyRequest::$calls as [, $data]) {
            $this->assertNotSame((string) $chat, (string) ($data['chat_id'] ?? ''), 'виртуальный id не уходит в транспорт');
        }
    }

    /** @return array{0:int, 1:int, 2:int} [character_id, virtual chat id, telegram_users.id] */
    private function webOnly(): array
    {
        $this->conn->table('accounts')->insert(['acquisition_source' => 'web', 'created_at' => date('Y-m-d H:i:s')]);
        $accountId = (int) $this->conn->insertID();
        $chat      = VirtualChat::idForAccount($accountId);
        $tuId      = $this->telegramUser($chat);

        return [$this->character($tuId, $accountId), $chat, $tuId];
    }

    private function botOnly(int $chat): int
    {
        return $this->character($this->telegramUser($chat), null);
    }

    private function telegramUser(int $telegramId): int
    {
        $now = date('Y-m-d H:i:s');
        $this->conn->table('telegram_users')->insert(['telegram_id' => $telegramId, 'created_at' => $now, 'updated_at' => $now]);

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

    private function setFlag(bool $on): void
    {
        service('cache')->save(self::FLAG_CACHE, ['v' => $on, 't' => 'bool'], 60);
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
