<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\AdminAuditLogModel;
use App\Services\Community\CommunityModerationService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * community-chat-bot-10 — `CommunityModerationService`, модерация ссылок и вербовки
 * в режиме `shadow`.
 *
 * Изолированная схема (паттерн `CommunityChatSenderTest`): свои `community_messages`/
 * `admin_audit_log` в `wildworld_tests`, реальная сеть не вызывается — `deleteMessage`
 * идёт через инжектируемый `$transport`, сигнал владельцу — через инжектируемый
 * `$notifyOwner`, оба перехватываются в замыкание вместо реального
 * `Request::send()`/`BroadcastService`.
 *
 * @internal
 */
final class CommunityModerationServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = ['community_messages', 'admin_audit_log'];

    private const CHAT_ID = -1001111111111;

    /** @var BaseConnection<\mysqli, \mysqli_result> */
    private BaseConnection $conn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->conn = Database::connect('tests');

        foreach (self::TABLES as $t) {
            $this->conn->query("DROP TABLE IF EXISTS {$t}");
        }

        $this->conn->query('
            CREATE TABLE community_messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                chat_id BIGINT NOT NULL,
                message_thread_id INT NULL,
                message_id INT NOT NULL,
                reply_to_message_id INT NULL,
                telegram_user_id BIGINT NOT NULL,
                username VARCHAR(191) NULL,
                text TEXT NULL,
                sent_at DATETIME NULL,
                is_question TINYINT NOT NULL DEFAULT 0,
                addressed_to_bot TINYINT NOT NULL DEFAULT 0,
                status VARCHAR(16) NOT NULL DEFAULT \'new\',
                answered_by_id INT NULL,
                created_at DATETIME NULL
            )
        ');

        $this->conn->query('
            CREATE TABLE admin_audit_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                admin_user_id INT NOT NULL,
                action VARCHAR(64) NOT NULL,
                target_type VARCHAR(32) NULL,
                target_id BIGINT NULL,
                payload TEXT NULL,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,
                created_at DATETIME NOT NULL
            )
        ');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (self::TABLES as $t) {
            $this->conn->query("DROP TABLE IF EXISTS {$t}");
        }
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function seedPriorMessage(int $authorId, string $sentAt): void
    {
        $this->conn->table('community_messages')->insert([
            'chat_id'           => self::CHAT_ID,
            'message_thread_id' => 12,
            'message_id'        => random_int(1, 1_000_000),
            'telegram_user_id'  => $authorId,
            'username'          => 'veteran',
            'text'              => 'привет',
            'sent_at'           => $sentAt,
            'is_question'       => 0,
            'addressed_to_bot'  => 0,
            'status'            => 'new',
            'created_at'        => $sentAt,
        ]);
    }

    /** Стаж-ветеран: >3 прежних сообщений, первое — больше 24 часов назад. */
    private function seedVeteran(int $authorId, string $nowAt): void
    {
        $nowTs = (int) strtotime($nowAt);
        $this->seedPriorMessage($authorId, date('Y-m-d H:i:s', $nowTs - 30 * 3600));
        $this->seedPriorMessage($authorId, date('Y-m-d H:i:s', $nowTs - 20 * 3600));
        $this->seedPriorMessage($authorId, date('Y-m-d H:i:s', $nowTs - 10 * 3600));
        $this->seedPriorMessage($authorId, date('Y-m-d H:i:s', $nowTs - 5 * 3600));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return callable(string, mixed=): mixed
     */
    private function settingsWith(array $overrides): callable
    {
        return static fn (string $key, mixed $default = null): mixed
            => array_key_exists($key, $overrides) ? $overrides[$key] : $default;
    }

    /** @return array<string, mixed> */
    private function openSettings(string $mode = 'shadow'): array
    {
        return [
            'community.enabled'        => true,
            'community.chat_id'        => (string) self::CHAT_ID,
            'community.moderation.mode' => $mode,
        ];
    }

    /**
     * @param array<string, mixed> $settingsOverrides
     * @param array<int, array{method: string, data: array<string, mixed>}> $deleteCalls
     * @param list<string> $notices
     */
    private function service(
        array $settingsOverrides,
        array &$deleteCalls,
        array &$notices,
    ): CommunityModerationService {
        return new CommunityModerationService(
            new AdminAuditLogModel(),
            null,
            $this->settingsWith(array_merge($this->openSettings(), $settingsOverrides)),
            $this->conn,
            function (string $method, array $data) use (&$deleteCalls): \Longman\TelegramBot\Entities\ServerResponse {
                $deleteCalls[] = ['method' => $method, 'data' => $data];
                return new \Longman\TelegramBot\Entities\ServerResponse(['ok' => true], 'testbot');
            },
            function (string $text) use (&$notices): void {
                $notices[] = $text;
            },
            '352706554',
            '@wildworldrpg_bot',
        );
    }

    /** @param array<string, mixed> $overrides */
    private function update(array $overrides = []): array
    {
        $message = array_merge([
            'message_id' => 501,
            'date'       => time(),
            'chat'       => ['id' => self::CHAT_ID, 'type' => 'supergroup'],
            'from'       => ['id' => 999001, 'username' => 'newbie'],
            'text'       => 'привет',
        ], $overrides);

        return ['message' => $message];
    }

    // ── off ──────────────────────────────────────────────────────────────

    public function testOffModeDoesNothingEvenForObviousTrigger(): void
    {
        $deleteCalls = [];
        $notices     = [];
        $service     = $this->service(
            ['community.moderation.mode' => 'off'],
            $deleteCalls,
            $notices,
        );

        $service->evaluate($this->update(['text' => 'продам аккаунт дёшево, пиши в личку']));

        $this->assertSame([], $deleteCalls);
        $this->assertSame([], $notices);
        $this->assertSame(0, $this->conn->table('admin_audit_log')->countAllResults());
    }

    // ── shadow ───────────────────────────────────────────────────────────

    public function testShadowModeSendsSignalButNeverDeletes(): void
    {
        $deleteCalls = [];
        $notices     = [];
        $service     = $this->service(['community.moderation.mode' => 'shadow'], $deleteCalls, $notices);

        $service->evaluate($this->update([
            'text' => 'заходи по ссылке https://example-scam.tld/join',
        ]));

        $this->assertSame([], $deleteCalls, 'shadow не удаляет ничего');
        $this->assertCount(1, $notices, 'но сигнал владельцу уходит');
    }

    public function testSignalContainsQuoteAndMessageLink(): void
    {
        $deleteCalls = [];
        $notices     = [];
        $service     = $this->service(['community.moderation.mode' => 'shadow'], $deleteCalls, $notices);

        $service->evaluate($this->update([
            'message_id'        => 777,
            'message_thread_id' => 55,
            'text'              => 'куплю аккаунт с топ шмотом',
        ]));

        $this->assertCount(1, $notices);
        $this->assertStringContainsString('куплю аккаунт с топ шмотом', $notices[0]);
        $this->assertStringContainsString('t.me/c/', $notices[0]);
        $this->assertStringContainsString('/55/777', $notices[0]);
    }

    // ── ветеран не триггерит на ссылку ──────────────────────────────────

    public function testVeteranWithLinkDoesNotTrigger(): void
    {
        $authorId = 55501;
        $now      = date('Y-m-d H:i:s');
        $this->seedVeteran($authorId, $now);

        $deleteCalls = [];
        $notices     = [];
        $service     = $this->service(['community.moderation.mode' => 'shadow'], $deleteCalls, $notices);

        $service->evaluate($this->update([
            'from' => ['id' => $authorId, 'username' => 'veteran'],
            'text' => 'смотрите я нашёл гайд https://wiki.example.com/guide',
            'date' => (int) strtotime($now),
        ]));

        $this->assertSame([], $notices, 'ветеран со ссылкой — не сигнал модерации');
        $this->assertSame(0, $this->conn->table('admin_audit_log')->countAllResults());
    }

    // ── вербовка триггерит независимо от стажа ──────────────────────────

    public function testSellAccountPhraseTriggersRegardlessOfTenure(): void
    {
        $authorId = 55502;
        $now      = date('Y-m-d H:i:s');
        $this->seedVeteran($authorId, $now);

        $deleteCalls = [];
        $notices     = [];
        $service     = $this->service(['community.moderation.mode' => 'shadow'], $deleteCalls, $notices);

        $service->evaluate($this->update([
            'from' => ['id' => $authorId, 'username' => 'veteran'],
            'text' => 'продам аккаунт 50 уровень, недорого',
            'date' => (int) strtotime($now),
        ]));

        $this->assertCount(1, $notices, 'вербовка триггерит даже ветерана');
    }

    // ── негативный тест: мат и грубость не триггерят ничего ────────────

    public function testProfanityAndRudenessDoNotTriggerAnything(): void
    {
        $deleteCalls = [];
        $notices     = [];
        $service     = $this->service(['community.moderation.mode' => 'live'], $deleteCalls, $notices);

        // Явная грубость/оскорбление без ссылок/вербовки — от новичка (0 прежних сообщений).
        $service->evaluate($this->update([
            'from' => ['id' => 999777, 'username' => 'rude_guy'],
            'text' => 'да ты вообще дурак, играть не умеешь, иди отсюда придурок',
        ]));

        $this->assertSame([], $deleteCalls, 'токсичность/мат не модерируются вообще');
        $this->assertSame([], $notices);
        $this->assertSame(0, $this->conn->table('admin_audit_log')->countAllResults());
    }

    // ── live удаляет и сигналит ─────────────────────────────────────────

    public function testLiveModeDeletesAndSignals(): void
    {
        $deleteCalls = [];
        $notices     = [];
        $service     = $this->service(['community.moderation.mode' => 'live'], $deleteCalls, $notices);

        $service->evaluate($this->update([
            'message_id' => 909,
            'text'       => 'прокачаю за донат, пишите',
        ]));

        $this->assertCount(1, $deleteCalls);
        $this->assertSame('deleteMessage', $deleteCalls[0]['method']);
        $this->assertSame(909, $deleteCalls[0]['data']['message_id']);
        $this->assertCount(1, $notices);
        $this->assertSame('COMMUNITY_MODERATION_DELETED', $this->lastAuditAction());
    }

    // ── фото новичка никогда не удаляется, даже в live (ремонт, круг 1) ──

    public function testNewcomerPhotoAloneNeverDeletesEvenInLive(): void
    {
        $deleteCalls = [];
        $notices     = [];
        $service     = $this->service(['community.moderation.mode' => 'live'], $deleteCalls, $notices);

        // Новичок (0 прежних сообщений), фото есть, текста/ссылок/вербовки нет —
        // самое обычное действие («вот моя база»), не должно приводить к удалению.
        $service->evaluate($this->update([
            'from' => ['id' => 999888, 'username' => 'freshman'],
            'text' => null,
            'photo' => [['file_id' => 'abc', 'width' => 100, 'height' => 100]],
        ]));

        $this->assertSame([], $deleteCalls, 'фото у новичка не удаляется даже в live');
        $this->assertCount(1, $notices, 'но сигнал владельцу всё равно уходит');
        $this->assertSame('COMMUNITY_MODERATION_FLAGGED', $this->lastAuditAction());
    }

    // ── shadow → live меняет поведение без релиза ───────────────────────

    public function testSwitchingShadowToLiveChangesBehaviorAtRuntime(): void
    {
        $deleteCallsShadow = [];
        $noticesShadow     = [];
        $shadowService     = $this->service(['community.moderation.mode' => 'shadow'], $deleteCallsShadow, $noticesShadow);
        $shadowService->evaluate($this->update(['message_id' => 1, 'text' => 'донат в обход, пиши мне']));

        $deleteCallsLive = [];
        $noticesLive     = [];
        $liveService     = $this->service(['community.moderation.mode' => 'live'], $deleteCallsLive, $noticesLive);
        $liveService->evaluate($this->update(['message_id' => 2, 'text' => 'донат в обход, пиши мне']));

        $this->assertSame([], $deleteCallsShadow, 'shadow — без удаления');
        $this->assertCount(1, $noticesShadow);
        $this->assertCount(1, $deleteCallsLive, 'live — тот же контракт, но с удалением');
        $this->assertCount(1, $noticesLive);
    }

    private function lastAuditAction(): ?string
    {
        $row = $this->conn->table('admin_audit_log')->orderBy('id', 'DESC')->get(1)->getRowArray();
        return $row !== null && isset($row['action']) && is_string($row['action']) ? $row['action'] : null;
    }
}
