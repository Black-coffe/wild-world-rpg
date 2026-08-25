<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Database\Migrations\Adr176CreateCommunityMessagesTable;
use App\Database\Migrations\CreateAdminAuditLogTable;
use App\Models\AdminAuditLogModel;
use App\Services\Community\CommunityModerationService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * community-chat-bot-10 — `CommunityModerationService`, модерация ссылок и вербовки
 * в режиме `shadow`.
 *
 * Схема строится прогоном реальных миграций на группу `tests` (Forge) — паттерн
 * `CommunityCleanupTest`/`CommunityExportTest` (story community-chat-bot-36): изолированная
 * ручная `CREATE TABLE` разошлась с продовой миграцией и давала зелёный тест на схеме,
 * которой на проде нет. Реальная сеть не вызывается — `deleteMessage` идёт через
 * инжектируемый `$transport`, сигнал владельцу — через инжектируемый `$notifyOwner`,
 * оба перехватываются в замыкание вместо реального `Request::send()`/`BroadcastService`.
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

        $forge = Database::forge('tests');

        $this->requireMessagesMigrationClass();
        (new Adr176CreateCommunityMessagesTable($forge instanceof Forge ? $forge : null))->up();

        $this->requireAuditMigrationClass();
        (new CreateAdminAuditLogTable($forge instanceof Forge ? $forge : null))->up();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (self::TABLES as $t) {
            $this->conn->query("DROP TABLE IF EXISTS {$t}");
        }
    }

    private function requireMessagesMigrationClass(): void
    {
        if (! class_exists(Adr176CreateCommunityMessagesTable::class, false)) {
            require_once APPPATH . 'Database/Migrations/2026-08-25-100000_Adr176CreateCommunityMessagesTable.php';
        }
    }

    private function requireAuditMigrationClass(): void
    {
        if (! class_exists(CreateAdminAuditLogTable::class, false)) {
            require_once APPPATH . 'Database/Migrations/2026-05-04-110000_CreateAdminAuditLogTable.php';
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

    /**
     * Как `service()`, но `notifyOwner` не подменяется — используется дефолт сервиса
     * (прямой `sendMessage` через `$transport`, без `BroadcastService`/`parse_mode`).
     * Все вызовы транспорта (и `deleteMessage`, и `sendMessage`) попадают в один массив.
     *
     * @param array<string, mixed> $settingsOverrides
     * @param array<int, array{method: string, data: array<string, mixed>}> $transportCalls
     */
    private function serviceWithDefaultNotify(
        array $settingsOverrides,
        array &$transportCalls,
    ): CommunityModerationService {
        return new CommunityModerationService(
            new AdminAuditLogModel(),
            null,
            $this->settingsWith(array_merge($this->openSettings(), $settingsOverrides)),
            $this->conn,
            function (string $method, array $data) use (&$transportCalls): \Longman\TelegramBot\Entities\ServerResponse {
                $transportCalls[] = ['method' => $method, 'data' => $data];
                return new \Longman\TelegramBot\Entities\ServerResponse(['ok' => true], 'testbot');
            },
            null,
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

    // ── story 24: сигнал доходит при любом содержимом ───────────────────

    public function testDefaultNotifyOwnerDeliversMessageWithUnbalancedMarkdown(): void
    {
        $calls   = [];
        $service = $this->serviceWithDefaultNotify(['community.moderation.mode' => 'shadow'], $calls);

        $service->evaluate($this->update([
            'text' => 'заходи по ссылке https://example.tld/join_a*b`c[d злая ссылка',
        ]));

        $sendCalls = array_values(array_filter($calls, static fn (array $c): bool => $c['method'] === 'sendMessage'));
        $this->assertCount(1, $sendCalls, 'сигнал владельцу уходит через прямой sendMessage');
        $this->assertArrayNotHasKey(
            'parse_mode',
            $sendCalls[0]['data'],
            'без parse_mode — непарные _ * ` [ игрока не дают Telegram 400 «cant parse entities»'
        );
        $this->assertStringContainsString('join_a*b`c[d', (string) $sendCalls[0]['data']['text']);
    }

    public function testLongQuoteIsTruncatedToFitNoticeLimit(): void
    {
        $deleteCalls = [];
        $notices     = [];
        $service     = $this->service(['community.moderation.mode' => 'shadow'], $deleteCalls, $notices);

        $longText = 'куплю аккаунт ' . str_repeat('а', 5000);
        $service->evaluate($this->update(['text' => $longText]));

        $this->assertCount(1, $notices, 'очень длинная реплика не мешает сигналу уйти');
        $this->assertLessThanOrEqual(4096, mb_strlen($notices[0], 'UTF-8'));
    }

    // ── story 24: правка сообщения не обходит модерацию ──────────────────

    public function testEditedMessageWithLinkFromNewcomerTriggers(): void
    {
        $deleteCalls = [];
        $notices     = [];
        $service     = $this->service(['community.moderation.mode' => 'shadow'], $deleteCalls, $notices);

        $edited = $this->update(['text' => 'заходи по ссылке https://example-scam.tld/join']);

        $service->evaluate(['edited_message' => $edited['message']]);

        $this->assertCount(1, $notices, 'edited_message со ссылкой от новичка триггерит модерацию');
    }

    public function testEditedMessageWithoutSignalsDoesNothing(): void
    {
        $deleteCalls = [];
        $notices     = [];
        $service     = $this->service(['community.moderation.mode' => 'shadow'], $deleteCalls, $notices);

        $edited = $this->update(['text' => 'привет всем, как дела?']);

        $service->evaluate(['edited_message' => $edited['message']]);

        $this->assertSame([], $notices, 'edited_message без признаков не триггерит ничего');
        $this->assertSame(0, $this->conn->table('admin_audit_log')->countAllResults());
    }

    // ── story 62: created_at пишется часами БД, не PHP (расхождение таймзон) ───

    /**
     * `audit()` обязан писать `created_at` часами MySQL (`NOW()`), не PHP `date()` —
     * эти строки читаются тем же оконным запросом, что и аудит отправителя
     * (`CommunityController::autoClosedCount()` и соседние счётчики потолка,
     * memory `feedback_db_clock_seed_not_php_in_time_window_tests`, story -27/-62).
     *
     * Тест форсирует PHP-часы на 12 часов позади реального UTC (`Etc/GMT+12`), не
     * трогая MySQL, — так воспроизводится расхождение таймзон приложения и БД без
     * остановки времени. На старой реализации (`date('Y-m-d H:i:s')` в `audit()`)
     * запись уходит с меткой на 12 часов "в прошлом" относительно MySQL `NOW()`, и
     * оконный запрос `NOW() - INTERVAL 1 MINUTE` её не видит — тест краснеет. После
     * фикса запись идёт часами MySQL, окно видит её всегда.
     */
    public function testAuditCreatedAtUsesDbClockWhenAppAndDbClocksDiverge(): void
    {
        $deleteCalls = [];
        $notices     = [];
        $service     = $this->service(['community.moderation.mode' => 'live'], $deleteCalls, $notices);

        $originalTz = date_default_timezone_get();
        date_default_timezone_set('Etc/GMT+12');

        try {
            $service->evaluate($this->update([
                'message_id' => 4242,
                'text'       => 'прокачаю за донат, пишите',
            ]));
        } finally {
            date_default_timezone_set($originalTz);
        }

        $this->assertSame('COMMUNITY_MODERATION_DELETED', $this->lastAuditAction());

        $inWindow = $this->conn->query(
            "SELECT COUNT(*) AS n FROM admin_audit_log
             WHERE action = 'COMMUNITY_MODERATION_DELETED'
               AND created_at >= (NOW() - INTERVAL 1 MINUTE)"
        )->getRow('n');

        $this->assertSame(
            1,
            (int) $inWindow,
            'created_at обязан идти часами БД (NOW()), а не PHP date() — иначе запись выпадает из оконного запроса при расхождении таймзон'
        );
    }

    private function lastAuditAction(): ?string
    {
        $row = $this->conn->table('admin_audit_log')->orderBy('id', 'DESC')->get(1)->getRowArray();
        return $row !== null && isset($row['action']) && is_string($row['action']) ? $row['action'] : null;
    }
}
