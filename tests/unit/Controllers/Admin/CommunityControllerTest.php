<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers\Admin;

use App\Controllers\Admin\CommunityController;
use App\Database\Migrations\Adr176CreateCommunityAnswersTable;
use App\Database\Migrations\Adr176CreateCommunityMessagesTable;
use App\Database\Migrations\CreateAdminAuditLogTable;
use App\Models\CommunityAnswerModel;
use App\Models\CommunityMessageModel;
use App\Services\Community\CommunityChatSender;
use App\Services\Community\CommunityGuard;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use DateTimeImmutable;
use Longman\TelegramBot\Entities\ServerResponse;

/**
 * community-chat-bot-12 — `/admin/community`: единственный путь `draft` → `approved`,
 * отзыв, «стереть всё от игрока», метрики. Схема строится прогоном реальных миграций
 * на группу `tests` (Forge) — паттерн `CommunityCleanupTest`/`CommunityExportTest`
 * (story community-chat-bot-36): изолированная ручная `CREATE TABLE` разошлась с
 * продовой миграцией и давала зелёный тест на схеме, которой на проде нет.
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

        $forge = Database::forge('tests');

        $this->requireMessagesMigrationClass();
        (new Adr176CreateCommunityMessagesTable($forge instanceof Forge ? $forge : null))->up();

        $this->requireAnswersMigrationClass();
        (new Adr176CreateCommunityAnswersTable($forge instanceof Forge ? $forge : null))->up();

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

    private function requireAnswersMigrationClass(): void
    {
        if (! class_exists(Adr176CreateCommunityAnswersTable::class, false)) {
            require_once APPPATH . 'Database/Migrations/2026-08-25-100100_Adr176CreateCommunityAnswersTable.php';
        }
    }

    private function requireAuditMigrationClass(): void
    {
        if (! class_exists(CreateAdminAuditLogTable::class, false)) {
            require_once APPPATH . 'Database/Migrations/2026-05-04-110000_CreateAdminAuditLogTable.php';
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

    /**
     * Как {@see sender()}, но с управляемыми гейтами — для story 19: подтвердить, что
     * контроллер зовёт `sendManualAnswer()` (пропускает `community.autoreply.enabled`),
     * а не `sendAnswer()` (блокируется им).
     *
     * @param array<string, bool> $settings
     */
    private function senderWithSettings(array $settings, bool $ok = true): CommunityChatSender
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
            static fn (string $key, mixed $default = null): mixed => $settings[$key] ?? $default
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

    /**
     * Story 19 — сквозное утверждение, которого не хватало: контроллер обязан звать
     * `sendManualAnswer()`, а не `sendAnswer()`. При выключенных автоответах
     * `sendAnswer()` откажет (килсвитч `community.autoreply.enabled`), и этот тест
     * ловил бы регресс на уровне наблюдаемого поведения, а не на факте вызова метода.
     */
    public function testApproveSendsAndFlipsStatusWhenAutoreplyDisabled(): void
    {
        $message = $this->insertMessage();
        $draft   = $this->insertDraft();

        $sender = $this->senderWithSettings([
            'community.enabled'            => true,
            'community.autoreply.enabled'  => false,
        ]);

        $result = $this->controller(null, $sender)->approveAnswer((int) $draft['id'], (int) $message['id']);

        $this->assertTrue($result['ok']);
        $this->assertSame('approved', $this->statusOfAnswer((int) $draft['id']));
        $this->assertSame('answered', $this->statusOfMessage((int) $message['id']));
    }

    /** Story 19: `community.enabled=false` (общий рубильник) должен блокировать одобрение как и раньше. */
    public function testApproveFailsAndKeepsStatusWhenCommunityDisabled(): void
    {
        $message = $this->insertMessage();
        $draft   = $this->insertDraft();

        $sender = $this->senderWithSettings([
            'community.enabled'           => false,
            'community.autoreply.enabled' => false,
        ]);

        $result = $this->controller(null, $sender)->approveAnswer((int) $draft['id'], (int) $message['id']);

        $this->assertFalse($result['ok']);
        $this->assertSame('draft', $this->statusOfAnswer((int) $draft['id']));
        $this->assertSame('new', $this->statusOfMessage((int) $message['id']));
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

        // Story 19: контроллер зовёт sendManualAnswer() — аудит различает ручную
        // отправку от автоматики отдельным префиксом (COMMUNITY_MANUAL_ANSWER_*).
        $corrections = $this->conn->table('admin_audit_log')->where('action', 'COMMUNITY_MANUAL_ANSWER_SENT')->countAllResults();
        $this->assertSame(1, $corrections, 'поправка обязана уйти реплаем через CommunityChatSender::sendManualAnswer()');
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

    /** Story 19: отзыв тоже обязан слать поправку через `sendManualAnswer()` при выключенных автоответах. */
    public function testRevokeSendsCorrectionAndFlipsStatusWhenAutoreplyDisabled(): void
    {
        $message = $this->insertMessage();
        $draft   = $this->insertDraft(['status' => 'approved', 'approved_at' => date('Y-m-d H:i:s')]);
        $this->conn->table('community_messages')->where('id', $message['id'])->update([
            'status'         => 'answered',
            'answered_by_id' => $draft['id'],
        ]);

        $sender = $this->senderWithSettings([
            'community.enabled'           => true,
            'community.autoreply.enabled' => false,
        ]);

        $result = $this->controller(null, $sender)->revokeAnswer((int) $draft['id'], 'Поправка: было неверно.');

        $this->assertTrue($result['ok']);
        $this->assertSame('revoked', $this->statusOfAnswer((int) $draft['id']));
    }

    /** Story 19: `community.enabled=false` должен блокировать и отзыв, как и одобрение. */
    public function testRevokeFailsAndKeepsStatusWhenCommunityDisabled(): void
    {
        $message = $this->insertMessage();
        $draft   = $this->insertDraft(['status' => 'approved', 'approved_at' => date('Y-m-d H:i:s')]);
        $this->conn->table('community_messages')->where('id', $message['id'])->update([
            'status'         => 'answered',
            'answered_by_id' => $draft['id'],
        ]);

        $sender = $this->senderWithSettings([
            'community.enabled'           => false,
            'community.autoreply.enabled' => false,
        ]);

        $result = $this->controller(null, $sender)->revokeAnswer((int) $draft['id'], 'Поправка.');

        $this->assertFalse($result['ok']);
        $this->assertSame('approved', $this->statusOfAnswer((int) $draft['id']));
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

    /** @param array<string, mixed> $overrides */
    private function insertAuditLog(string $action, int $targetId, string $createdAt, array $overrides = []): void
    {
        $row = array_merge([
            'admin_user_id' => 0,
            'action'        => $action,
            'target_type'   => 'community_message',
            'target_id'     => $targetId,
            'payload'       => null,
            'ip_address'    => null,
            'user_agent'    => null,
            'created_at'    => $createdAt,
        ], $overrides);

        $this->conn->table('admin_audit_log')->insert($row);
    }

    public function testBotVsHumanMetricCountsWithinWindowOnly(): void
    {
        $now = new DateTimeImmutable('2026-08-25 12:00:00');

        // Бот ответил (status=answered) — в окне (7 дней), сопровождается
        // автоматическим аудитом COMMUNITY_ANSWER_SENT (дефект 2: только он
        // должен учитываться в «бот против живых»).
        $auto = $this->insertMessage(['status' => 'answered', 'sent_at' => $now->modify('-2 days')->format('Y-m-d H:i:s')]);
        $this->insertAuditLog('COMMUNITY_ANSWER_SENT', (int) $auto['id'], $now->modify('-2 days')->format('Y-m-d H:i:s'));

        // Ручной ответ владельца (status=answered тоже, но аудит — MANUAL) — не
        // должен увеличивать долю «бот» (дефект 2, story 26 acceptance).
        $manual = $this->insertMessage(['status' => 'answered', 'sent_at' => $now->modify('-2 days')->format('Y-m-d H:i:s')]);
        $this->insertAuditLog('COMMUNITY_MANUAL_ANSWER_SENT', (int) $manual['id'], $now->modify('-2 days')->format('Y-m-d H:i:s'));

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
        $stale = $this->insertMessage(['status' => 'answered', 'sent_at' => $now->modify('-10 days')->format('Y-m-d H:i:s')]);
        $this->insertAuditLog('COMMUNITY_ANSWER_SENT', (int) $stale['id'], $now->modify('-10 days')->format('Y-m-d H:i:s'));

        $metrics = $this->controller()->computeMetrics($now);

        // 1 АВТО бот-ответ в окне против 1 человек-человеку в окне = 0.5, ручной
        // ответ и устаревшая строка не в счёте.
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

        $sentAt = $now->modify('-1 day')->format('Y-m-d H:i:s');

        $answered1 = $this->insertMessage(['status' => 'answered', 'sent_at' => $sentAt]);
        $this->insertAuditLog('COMMUNITY_ANSWER_SENT', (int) $answered1['id'], $sentAt);
        $answered2 = $this->insertMessage(['status' => 'answered', 'sent_at' => $sentAt]);
        $this->insertAuditLog('COMMUNITY_ANSWER_SENT', (int) $answered2['id'], $sentAt);

        // Настоящий отказ гварда: escalated с собственной COMMUNITY_ROUTE_LOGGED.
        $guardDenied = $this->insertMessage(['status' => 'escalated', 'sent_at' => $sentAt]);
        $this->insertAuditLog('COMMUNITY_ROUTE_LOGGED', (int) $guardDenied['id'], $sentAt);

        $metrics = $this->controller()->computeMetrics($now);

        $this->assertEqualsWithDelta(1 / 3, $metrics['guard_rejection_rate'], 0.0001);
    }

    /**
     * Дефект 3: полоса A «не знаю» тоже помечает строку escalated, но гвард её
     * пропустил и текст реально ушёл (COMMUNITY_ANSWER_SENT есть) — это НЕ отказ
     * гварда и не должно раздувать guard_rejection_rate.
     */
    public function testGuardRejectionRateExcludesHonestUnknownEscalations(): void
    {
        $now    = new DateTimeImmutable('2026-08-25 12:00:00');
        $sentAt = $now->modify('-1 day')->format('Y-m-d H:i:s');

        $answered = $this->insertMessage(['status' => 'answered', 'sent_at' => $sentAt]);
        $this->insertAuditLog('COMMUNITY_ANSWER_SENT', (int) $answered['id'], $sentAt);

        // Полоса A: escalated, но бот честно отправил «не знаю» — есть SENT-аудит.
        $honestUnknown = $this->insertMessage(['status' => 'escalated', 'sent_at' => $sentAt]);
        $this->insertAuditLog('COMMUNITY_ANSWER_SENT', (int) $honestUnknown['id'], $sentAt);

        $metrics = $this->controller()->computeMetrics($now);

        $this->assertSame(0.0, $metrics['guard_rejection_rate'], 'честное "не знаю" не должно считаться отказом гварда');
    }

    /**
     * Story 32, дефект 2: терминальный отказ ГЕЙТА ОТПРАВИТЕЛЯ (длина/непарный
     * markdown/неканоничное имя — `CommunityChatSender::checkGates()`, story 23)
     * тоже уходит в `escalated` без `COMMUNITY_ANSWER_SENT`, но это не отказ
     * гварда: гвард уже разрешил, отказал гейт отправки. Различитель на записи —
     * своя `COMMUNITY_ROUTE_LOGGED` (story 39: не отсутствие чужой `_REJECTED`).
     */
    public function testGuardRejectionRateExcludesSenderGateTerminalRejections(): void
    {
        $now    = new DateTimeImmutable('2026-08-25 12:00:00');
        $sentAt = $now->modify('-1 day')->format('Y-m-d H:i:s');

        $answered = $this->insertMessage(['status' => 'answered', 'sent_at' => $sentAt]);
        $this->insertAuditLog('COMMUNITY_ANSWER_SENT', (int) $answered['id'], $sentAt);

        // Настоящий отказ гварда: escalated со своей COMMUNITY_ROUTE_LOGGED.
        $guardDenied = $this->insertMessage(['status' => 'escalated', 'sent_at' => $sentAt]);
        $this->insertAuditLog('COMMUNITY_ROUTE_LOGGED', (int) $guardDenied['id'], $sentAt);

        // Гейт отправителя отказал по длине текста ПОСЛЕ того, как гвард разрешил —
        // тоже escalated, но с собственной COMMUNITY_ANSWER_REJECTED-записью, а не
        // COMMUNITY_ROUTE_LOGGED (гвард сюда вообще не дошёл — allow уже был выдан).
        $gateDenied = $this->insertMessage(['status' => 'escalated', 'sent_at' => $sentAt]);
        $this->insertAuditLog('COMMUNITY_ANSWER_REJECTED', (int) $gateDenied['id'], $sentAt, ['payload' => json_encode(['reason' => 'text_too_long'])]);

        $metrics = $this->controller()->computeMetrics($now);

        // guardTotal = 1 (answered) + 1 (guardDenied) = 2; доля = 1/2, не 2/3.
        $this->assertEqualsWithDelta(0.5, $metrics['guard_rejection_rate'], 0.0001, 'отказ гейта отправителя не должен считаться отказом гварда');
    }

    /**
     * Story 39: строка сначала упёрлась в `topic_rate_limit` (отправитель пишет
     * `COMMUNITY_ANSWER_REJECTED`, строка возвращается в `new`), а ПОЗЖЕ ту же
     * строку денит гвард (`escalated`, своя `COMMUNITY_ROUTE_LOGGED`). Старый
     * различитель `NOT EXISTS (... COMMUNITY_ANSWER_REJECTED ...)` смотрел на всю
     * историю строки и терял её из числителя — новый смотрит на собственную
     * положительную запись гварда и обязан её засчитать.
     */
    public function testGuardRejectionRateCountsGuardDenialAfterEarlierRateLimitRejection(): void
    {
        $now    = new DateTimeImmutable('2026-08-25 12:00:00');
        $sentAt = $now->modify('-1 day')->format('Y-m-d H:i:s');

        $answered = $this->insertMessage(['status' => 'answered', 'sent_at' => $sentAt]);
        $this->insertAuditLog('COMMUNITY_ANSWER_SENT', (int) $answered['id'], $sentAt);

        // Сначала — давний терминальный отказ гейта отправителя по лимиту темы.
        $rateLimited = $this->insertMessage(['status' => 'escalated', 'sent_at' => $sentAt]);
        $this->insertAuditLog(
            'COMMUNITY_ANSWER_REJECTED',
            (int) $rateLimited['id'],
            $now->modify('-3 days')->format('Y-m-d H:i:s'),
            ['payload' => json_encode(['reason' => 'topic_rate_limit'])]
        );
        // Позже — ту же строку денит гвард, своя положительная запись.
        $this->insertAuditLog('COMMUNITY_ROUTE_LOGGED', (int) $rateLimited['id'], $sentAt);

        $metrics = $this->controller()->computeMetrics($now);

        // guardTotal = 1 (answered) + 1 (guardDenied) = 2; доля = 1/2, строка не выпала.
        $this->assertEqualsWithDelta(0.5, $metrics['guard_rejection_rate'], 0.0001, 'давний отказ гейта не должен маскировать более поздний отказ гварда');
    }

    /**
     * Story 59: story 58 заводит отдельное действие `COMMUNITY_ROUTE_SENT` для
     * отправки маршрута отказа (story 57 переводит на него `CommunityAutoReplyHandler`).
     * Строка несёт СВОЮ `COMMUNITY_ROUTE_LOGGED` (маркер «записан как отказ») и
     * `COMMUNITY_ROUTE_SENT` (маркер отправки текста маршрута) — но НЕ
     * `COMMUNITY_ANSWER_SENT`. Тест краснеет, если `autoAnswerCount()` вернётся к счёту
     * по маркеру `COMMUNITY_ROUTE_LOGGED` вместо действия `COMMUNITY_ANSWER_SENT`.
     */
    public function testGuardDenialRouteTextDoesNotDoubleCountAsBotAnswer(): void
    {
        $now    = new DateTimeImmutable('2026-08-25 12:00:00');
        $sentAt = $now->modify('-1 day')->format('Y-m-d H:i:s');

        // Отказ гварда, доехавший до игрока: escalated, своя COMMUNITY_ROUTE_LOGGED,
        // и COMMUNITY_ROUTE_SENT — sendGuardRoute() отправил текст маршрута отдельным
        // действием (story 57/58), не COMMUNITY_ANSWER_SENT.
        $guardDenied = $this->insertMessage(['status' => 'escalated', 'sent_at' => $sentAt]);
        $this->insertAuditLog('COMMUNITY_ROUTE_LOGGED', (int) $guardDenied['id'], $sentAt);
        $this->insertAuditLog('COMMUNITY_ROUTE_SENT', (int) $guardDenied['id'], $sentAt);

        $metrics = $this->controller()->computeMetrics($now);

        // guardTotal обязан остаться 1 (одна строка), а не 2 — иначе доля отказов
        // гварда была бы задвоена/занижена в зависимости от знаменателя.
        $this->assertSame(1.0, $metrics['guard_rejection_rate'], 'отказ гварда не должен считаться и ответом бота одновременно');
    }

    /**
     * Story 59, главный сценарий: 10 вопросов, все отклонены гвардом (и доехали
     * до игроков маршрутом через `COMMUNITY_ROUTE_SENT`), живые игроки друг другу
     * отвечают отдельно от бота — «доля ответов бота» обязана быть 0%, а не 100%
     * (старый баг: отказ считался ответом бота, human-реплаи в знаменатель не попадали).
     */
    public function testAllGuardDeniedWithLiveHumanChatGivesZeroBotShareNotHundredPercent(): void
    {
        $now    = new DateTimeImmutable('2026-08-25 12:00:00');
        $sentAt = $now->modify('-1 day')->format('Y-m-d H:i:s');

        for ($i = 0; $i < 10; $i++) {
            $denied = $this->insertMessage(['status' => 'escalated', 'sent_at' => $sentAt]);
            $this->insertAuditLog('COMMUNITY_ROUTE_LOGGED', (int) $denied['id'], $sentAt);
            $this->insertAuditLog('COMMUNITY_ROUTE_SENT', (int) $denied['id'], $sentAt);
        }

        // Живые игроки общаются между собой, бот в этом не участвует.
        $author = $this->insertMessage(['telegram_user_id' => 111, 'message_id' => 6001, 'sent_at' => $sentAt]);
        $this->insertMessage([
            'telegram_user_id'    => 222,
            'reply_to_message_id' => $author['message_id'],
            'sent_at'             => $sentAt,
        ]);

        $metrics = $this->controller()->computeMetrics($now);

        $this->assertSame(0.0, $metrics['bot_vs_human_share'], 'все отказы гварда не должны выглядеть как 100% ответов бота');
        $this->assertSame(1.0, $metrics['guard_rejection_rate']);
    }

    // ── очередь: маршрут отказа виден рядом со строкой (дефект 3) ──────────

    /**
     * Story 32, дефект 3: `COMMUNITY_ROUTE_LOGGED` писался в журнал, но очередь
     * `/admin/community` его не читала — маршрут был виден только на общем
     * `/admin/audit-log`. Очередь обязана нести его рядом со строкой.
     */
    public function testOpenQuestionsFlatExposesLoggedRoute(): void
    {
        $escalated = $this->insertMessage(['status' => 'escalated', 'is_question' => 1]);
        $this->insertAuditLog(
            'COMMUNITY_ROUTE_LOGGED',
            (int) $escalated['id'],
            date('Y-m-d H:i:s'),
            ['payload' => json_encode(['reason' => 'dormant_setting_disabled', 'route' => 'Загляни в /guide про эту тему.'], JSON_UNESCAPED_UNICODE)]
        );

        $method = new \ReflectionMethod(CommunityController::class, 'openQuestionsFlat');
        $method->setAccessible(true);
        $rows = $method->invoke($this->controller());

        $this->assertCount(1, $rows);
        $this->assertSame('Загляни в /guide про эту тему.', $rows[0]['route']);
    }

    public function testOpenQuestionsFlatRouteIsNullWithoutLoggedRoute(): void
    {
        $this->insertMessage(['status' => 'new', 'is_question' => 1]);

        $method = new \ReflectionMethod(CommunityController::class, 'openQuestionsFlat');
        $method->setAccessible(true);
        $rows = $method->invoke($this->controller());

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['route']);
    }

    // ── очередь: открытые вопросы (дефект 1) ────────────────────────────────

    /**
     * Дефект 1: очередь фильтровала только `status`, не `is_question` — на
     * обкатке (авто-тик выключен) любая принятая реплика в статусе `new`
     * показывалась как «открытый вопрос».
     */
    public function testOpenQuestionsExcludesNonQuestionMessages(): void
    {
        $this->insertMessage(['status' => 'new', 'is_question' => 1]);
        $this->insertMessage(['status' => 'new', 'is_question' => 0]); // обычная реплика, не вопрос

        $method = new \ReflectionMethod(CommunityController::class, 'openQuestionsFlat');
        $method->setAccessible(true);
        $rows = $method->invoke($this->controller());

        $this->assertCount(1, $rows);
    }

    /** Дефект 1: то же определение — метрика просроченных тоже фильтрует is_question. */
    public function testStaleOpenQuestionsExcludesNonQuestionMessages(): void
    {
        $now = new DateTimeImmutable('2026-08-25 12:00:00');

        $this->insertMessage(['status' => 'new', 'is_question' => 1, 'sent_at' => $now->modify('-100 hours')->format('Y-m-d H:i:s')]);
        $this->insertMessage(['status' => 'new', 'is_question' => 0, 'sent_at' => $now->modify('-100 hours')->format('Y-m-d H:i:s')]); // не вопрос

        $metrics = $this->controller()->computeMetrics($now);

        $this->assertSame(1, $metrics['stale_open_questions']);
    }

    // ── отзыв: детерминированная цель среди дублей (дефект 4) ──────────────

    /**
     * Дефект 4: `answered_by_id` может стоять на нескольких строках (склейка
     * дублей) — отзыв обязан адресовать поправку предсказуемой из них (самой
     * ранней), а не произвольной строке через `first()` без сортировки.
     */
    public function testRevokeTargetsEarliestMessageAmongDuplicates(): void
    {
        $draft = $this->insertDraft(['status' => 'approved', 'approved_at' => date('Y-m-d H:i:s')]);

        // Story 32, дефект 4: вставка НАРОЧНО против порядка sent_at — $later получает
        // меньший id (вставлен первым). Без orderBy('sent_at') в revokeAnswer() голый
        // first() вернул бы insertion/PK-порядок, то есть $later — тест обязан
        // покраснеть, если сортировку убрать (прошлая версия вставляла в совпадающем
        // порядке и не ловила дефект).
        $later   = $this->insertMessage(['sent_at' => '2026-08-20 11:00:00']);
        $earlier = $this->insertMessage(['sent_at' => '2026-08-20 10:00:00']);

        $this->conn->table('community_messages')->where('id', $earlier['id'])->update([
            'status' => 'answered', 'answered_by_id' => $draft['id'],
        ]);
        $this->conn->table('community_messages')->where('id', $later['id'])->update([
            'status' => 'answered', 'answered_by_id' => $draft['id'],
        ]);

        $result = $this->controller()->revokeAnswer((int) $draft['id'], 'Поправка.');

        $this->assertTrue($result['ok']);
        $log = $this->conn->table('admin_audit_log')->where('action', 'COMMUNITY_ANSWER_REVOKED')->get(1)->getRowArray();
        $this->assertNotNull($log);
        $payload = json_decode((string) $log['payload'], true);
        $this->assertSame((int) $earlier['id'], $payload['target_message_id'], 'поправка обязана уйти на самое раннее сообщение');
    }
}
