<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers\Admin;

use App\Controllers\Admin\CommunityController;
use App\Models\CommunityAnswerModel;
use App\Models\CommunityMessageModel;
use App\Services\Community\CommunityChatSender;
use App\Services\Community\CommunityGuard;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use DateTimeImmutable;
use Longman\TelegramBot\Entities\ServerResponse;

/**
 * community-chat-bot-12 — `/admin/community`: единственный путь `draft` → `approved`,
 * отзыв, «стереть всё от игрока», метрики. Изолированная схема (паттерн
 * `CommunityAutoReplyHandlerTest`): свои `community_messages`/`community_answers`/
 * `admin_audit_log` в `wildworld_tests`, не общая прод-схема.
 *
 * Тестируется бизнес-логика напрямую ({@see CommunityController::approveAnswer()} и
 * т.п.) без HTTP-цикла — в этом репозитории нет FeatureTestTrait-инфраструктуры для
 * admin-контроллеров (Tier-2 MCP Chrome смок — отдельно, вне phpunit, см. story
 * `## Verification`). `CommunityChatSender` инжектируется с собственным
 * `$transport`-callable — реальный Bot API не звонит ни разу
 * (`feedback_taskhandler_telegram_init_in_tests`).
 *
 * @internal
 */
final class CommunityControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = ['community_messages', 'community_answers', 'admin_audit_log'];

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
            CREATE TABLE community_answers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                client_key VARCHAR(32) NOT NULL,
                question_pattern TEXT NOT NULL,
                answer_text TEXT NOT NULL,
                requires_setting VARCHAR(120) NULL,
                source_ref VARCHAR(255) NOT NULL DEFAULT \'test\',
                status VARCHAR(16) NOT NULL DEFAULT \'draft\',
                approved_at DATETIME NULL,
                approved_by VARCHAR(64) NULL,
                revoked_at DATETIME NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
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

    // ── fixtures ─────────────────────────────────────────────────────────

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function insertMessage(array $overrides = []): array
    {
        static $seq = 0;
        ++$seq;

        $row = array_merge([
            'chat_id'             => -1001111111111,
            'message_thread_id'   => 55,
            'message_id'          => 900 + $seq,
            'reply_to_message_id' => null,
            'telegram_user_id'    => 4000 + $seq,
            'username'            => 'tester',
            'text'                => 'где найти теплицу для земледелия',
            'sent_at'             => date('Y-m-d H:i:s'),
            'is_question'         => 1,
            'addressed_to_bot'    => 0,
            'status'              => 'new',
            'created_at'          => date('Y-m-d H:i:s'),
        ], $overrides);

        $this->conn->table('community_messages')->insert($row);
        $row['id'] = (int) $this->conn->insertID();

        return $row;
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function insertDraft(array $overrides = []): array
    {
        static $seq = 0;
        ++$seq;

        $row = array_merge([
            'client_key'       => 'test-draft-' . $seq,
            'question_pattern' => 'где найти теплицу для земледелия',
            'answer_text'      => 'Теплица строится на базе, доступна после разблокировки постройки.',
            'requires_setting' => null,
            'source_ref'       => 'guide:building',
            'status'           => 'draft',
            'created_at'       => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ], $overrides);

        $this->conn->table('community_answers')->insert($row);
        $row['id'] = (int) $this->conn->insertID();

        return $row;
    }

    private function statusOfMessage(int $id): ?string
    {
        $row = $this->conn->table('community_messages')->where('id', $id)->get(1)->getRowArray();

        return $row !== null && isset($row['status']) ? (string) $row['status'] : null;
    }

    private function statusOfAnswer(int $id): ?string
    {
        $row = $this->conn->table('community_answers')->where('id', $id)->get(1)->getRowArray();

        return $row !== null && isset($row['status']) ? (string) $row['status'] : null;
    }

    /**
     * Гвард с открытым белым корпусом — содержит текст черновика дословно, чтобы
     * рубеж провенанса пропускал fixture-ответы; здесь важна оркестровка контроллера,
     * не алгоритм гварда (тот покрыт `CommunityGuardTest`).
     */
    private function permissiveGuard(): CommunityGuard
    {
        return new CommunityGuard([
            ['source' => 'guide:building', 'text' => 'Теплица строится на базе, доступна после разблокировки постройки.'],
        ]);
    }

    /** Гвард, который ВСЕГДА отказывает — для теста атомарности (гвард отклонил → ничего не меняется). */
    private function denyingGuard(): CommunityGuard
    {
        return new CommunityGuard([['source' => 'x', 'text' => 'нерелевантный корпус без пересечения слов']]);
    }

    /** @param bool $ok Успех/провал транспорта Telegram (сеам, без сети). */
    private function sender(bool $ok = true): CommunityChatSender
    {
        $transport = static fn (string $method, array $data): ServerResponse => new ServerResponse(
            ['ok' => $ok, 'result' => ['message_id' => 1], 'description' => $ok ? null : 'boom'],
            ''
        );

        return new CommunityChatSender(
            new CommunityMessageModel(),
            null,
            null,
            $this->conn,
            $transport,
            static fn (string $key, mixed $default = null): mixed => match ($key) {
                'community.enabled', 'community.autoreply.enabled' => true,
                default                                             => $default,
            }
        );
    }

    private function controller(?CommunityGuard $guard = null, ?CommunityChatSender $sender = null): CommunityController
    {
        return new CommunityController(
            new CommunityMessageModel(),
            new CommunityAnswerModel(),
            $guard ?? $this->permissiveGuard(),
            $sender ?? $this->sender(true),
            $this->conn
        );
    }

    // ── одобрение ────────────────────────────────────────────────────────

    public function testApproveSendsReplyAndFlipsBothStatusesAtomically(): void
    {
        $message = $this->insertMessage();
        $draft   = $this->insertDraft();

        $result = $this->controller()->approveAnswer((int) $draft['id'], (int) $message['id']);

        $this->assertTrue($result['ok']);
        $this->assertSame('approved', $this->statusOfAnswer((int) $draft['id']));
        $this->assertSame('answered', $this->statusOfMessage((int) $message['id']));

        $sent = $this->conn->table('admin_audit_log')->where('action', 'COMMUNITY_ANSWER_APPROVED')->countAllResults();
        $this->assertSame(1, $sent);
    }

    public function testApproveWithoutTargetMessageOnlyPopulatesBank(): void
    {
        $draft = $this->insertDraft();

        $result = $this->controller()->approveAnswer((int) $draft['id'], null);

        $this->assertTrue($result['ok']);
        $this->assertSame('approved', $this->statusOfAnswer((int) $draft['id']));
    }

    public function testApproveIsAtomicWhenSendFails(): void
    {
        $message = $this->insertMessage();
        $draft   = $this->insertDraft();

        $controller = $this->controller(null, $this->sender(false));
        $result     = $controller->approveAnswer((int) $draft['id'], (int) $message['id']);

        $this->assertFalse($result['ok']);
        $this->assertSame('draft', $this->statusOfAnswer((int) $draft['id']), 'провал отправки не должен менять статус черновика');
        $this->assertSame('new', $this->statusOfMessage((int) $message['id']), 'провал отправки не должен менять статус сообщения');
    }

    public function testApproveRunsGuardAgainstCurrentTextAndBlocksOnDeny(): void
    {
        $message = $this->insertMessage();
        $draft   = $this->insertDraft();

        // Гвард, применённый к отредактированному (несовпадающему с корпусом) тексту —
        // владелец мог поменять текст в «Правке» перед одобрением, гвард обязан
        // перепроверить актуальный текст, а не пропустить его по факту прошлого импорта.
        $controller = $this->controller($this->denyingGuard(), $this->sender(true));
        $result     = $controller->approveAnswer((int) $draft['id'], (int) $message['id']);

        $this->assertFalse($result['ok']);
        $this->assertSame('draft', $this->statusOfAnswer((int) $draft['id']));
        $this->assertSame('new', $this->statusOfMessage((int) $message['id']));
    }

    public function testApproveRejectsNonDraftAnswer(): void
    {
        $draft = $this->insertDraft(['status' => 'approved']);

        $result = $this->controller()->approveAnswer((int) $draft['id'], null);

        $this->assertFalse($result['ok']);
    }

    // ── отзыв ────────────────────────────────────────────────────────────

    public function testRevokeSendsCorrectionToSameMessageAndFlipsStatus(): void
    {
        $message = $this->insertMessage();
        $draft   = $this->insertDraft(['status' => 'approved', 'approved_at' => date('Y-m-d H:i:s')]);
        $this->conn->table('community_messages')->where('id', $message['id'])->update([
            'status'         => 'answered',
            'answered_by_id' => $draft['id'],
        ]);

        $result = $this->controller()->revokeAnswer((int) $draft['id'], 'Поправка: было неверно.');

        $this->assertTrue($result['ok']);
        $this->assertSame('revoked', $this->statusOfAnswer((int) $draft['id']));

        $corrections = $this->conn->table('admin_audit_log')->where('action', 'COMMUNITY_ANSWER_SENT')->countAllResults();
        $this->assertSame(1, $corrections, 'поправка обязана уйти реплаем через CommunityChatSender');
    }

    public function testRevokeIsAtomicWhenCorrectionSendFails(): void
    {
        $message = $this->insertMessage();
        $draft   = $this->insertDraft(['status' => 'approved', 'approved_at' => date('Y-m-d H:i:s')]);
        $this->conn->table('community_messages')->where('id', $message['id'])->update([
            'status'         => 'answered',
            'answered_by_id' => $draft['id'],
        ]);

        $controller = $this->controller(null, $this->sender(false));
        $result     = $controller->revokeAnswer((int) $draft['id'], 'Поправка.');

        $this->assertFalse($result['ok']);
        $this->assertSame('approved', $this->statusOfAnswer((int) $draft['id']), 'провал отправки поправки не должен менять статус');
    }

    public function testRevokeWithoutKnownTargetStillFlipsStatus(): void
    {
        // Одобрен без цели (approveAnswer($id, null)) — отозвать банк-запись всё ещё
        // можно, просто без поправки в чат (нечего чинить, ответ никуда не уходил).
        $draft = $this->insertDraft(['status' => 'approved', 'approved_at' => date('Y-m-d H:i:s')]);

        $result = $this->controller()->revokeAnswer((int) $draft['id'], null);

        $this->assertTrue($result['ok']);
        $this->assertSame('revoked', $this->statusOfAnswer((int) $draft['id']));
    }

    public function testRevokeRejectsNonApprovedAnswer(): void
    {
        $draft = $this->insertDraft(); // ещё draft

        $result = $this->controller()->revokeAnswer((int) $draft['id'], null);

        $this->assertFalse($result['ok']);
    }

    // ── стереть всё от игрока ───────────────────────────────────────────

    public function testEraseMessagesFromPlayerDeletesAndCountsRows(): void
    {
        $victim = 555555;
        $this->insertMessage(['telegram_user_id' => $victim]);
        $this->insertMessage(['telegram_user_id' => $victim]);
        $this->insertMessage(['telegram_user_id' => 999999]); // другой игрок — не трогаем

        $deleted = $this->controller()->eraseMessagesFromPlayer($victim);

        $this->assertSame(2, $deleted);
        $remaining = $this->conn->table('community_messages')->where('telegram_user_id', $victim)->countAllResults();
        $this->assertSame(0, $remaining);
        $other = $this->conn->table('community_messages')->where('telegram_user_id', 999999)->countAllResults();
        $this->assertSame(1, $other, 'сообщения другого игрока не трогаем');

        $audited = $this->conn->table('admin_audit_log')->where('action', 'COMMUNITY_PLAYER_DATA_ERASED')->countAllResults();
        $this->assertSame(1, $audited);
    }

    // ── метрики ──────────────────────────────────────────────────────────

    public function testBotVsHumanMetricCountsWithinWindowOnly(): void
    {
        $now = new DateTimeImmutable('2026-08-25 12:00:00');

        // Бот ответил (status=answered) — в окне (7 дней).
        $this->insertMessage(['status' => 'answered', 'sent_at' => $now->modify('-2 days')->format('Y-m-d H:i:s')]);

        // Человек ответил человеку — в окне: reply на реальную строку другого автора.
        $author = $this->insertMessage([
            'telegram_user_id' => 111,
            'message_id'       => 5001,
            'sent_at'          => $now->modify('-3 days')->format('Y-m-d H:i:s'),
        ]);
        $this->insertMessage([
            'telegram_user_id'    => 222,
            'reply_to_message_id' => $author['message_id'],
            'sent_at'             => $now->modify('-3 days')->format('Y-m-d H:i:s'),
        ]);

        // Вне окна (10 дней назад) — не должно учитываться вовсе.
        $this->insertMessage(['status' => 'answered', 'sent_at' => $now->modify('-10 days')->format('Y-m-d H:i:s')]);

        $metrics = $this->controller()->computeMetrics($now);

        // 1 бот-ответ в окне против 1 человек-человеку в окне = 0.5, устаревшая строка не в счёте.
        $this->assertSame(0.5, $metrics['bot_vs_human_share']);
    }

    public function testStaleOpenQuestionsCountsIrrespectiveOfWindow(): void
    {
        $now = new DateTimeImmutable('2026-08-25 12:00:00');

        $this->insertMessage(['status' => 'new', 'sent_at' => $now->modify('-100 hours')->format('Y-m-d H:i:s')]);
        $this->insertMessage(['status' => 'escalated', 'sent_at' => $now->modify('-80 hours')->format('Y-m-d H:i:s')]);
        $this->insertMessage(['status' => 'new', 'sent_at' => $now->modify('-10 hours')->format('Y-m-d H:i:s')]); // свежий — не считается

        $metrics = $this->controller()->computeMetrics($now);

        $this->assertSame(2, $metrics['stale_open_questions']);
    }

    public function testGuardRejectionRateIsShareOfEscalatedAmongAnsweredAndEscalated(): void
    {
        $now = new DateTimeImmutable('2026-08-25 12:00:00');

        $this->insertMessage(['status' => 'answered', 'sent_at' => $now->modify('-1 day')->format('Y-m-d H:i:s')]);
        $this->insertMessage(['status' => 'answered', 'sent_at' => $now->modify('-1 day')->format('Y-m-d H:i:s')]);
        $this->insertMessage(['status' => 'escalated', 'sent_at' => $now->modify('-1 day')->format('Y-m-d H:i:s')]);

        $metrics = $this->controller()->computeMetrics($now);

        $this->assertEqualsWithDelta(1 / 3, $metrics['guard_rejection_rate'], 0.0001);
    }
}
