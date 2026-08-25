<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\CommunityAnswerModel;
use App\Models\CommunityMessageModel;
use App\Services\Community\CommunityAnswerMatcher;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use DateTimeImmutable;

/**
 * community-chat-bot-08 — `CommunityAnswerMatcher`: два порога, кулдауны, склейка
 * дублей. Изолированная схема (паттерн `CommunityChatSenderTest`, story 06): свои
 * `community_messages`/`community_answers` в `wildworld_tests`.
 *
 * @internal
 */
final class CommunityAnswerMatcherTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = ['community_messages', 'community_answers'];

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
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (self::TABLES as $t) {
            $this->conn->query("DROP TABLE IF EXISTS {$t}");
        }
    }

    // ── helpers ─────────────────────────────────────────────────────────

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
            'telegram_user_id'    => 4242,
            'username'            => 'tester',
            'text'                => 'как ваще крафтить',
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

    /** @param array<string, mixed> $overrides */
    private function insertBankAnswer(array $overrides = []): int
    {
        static $seq = 0;
        ++$seq;

        $row = array_merge([
            'client_key'       => 'test-answer-' . $seq,
            'question_pattern' => 'где найти теплицу для земледелия',
            'answer_text'      => 'Теплица строится на базе, доступна после разблокировки постройки.',
            'requires_setting' => null,
            'source_ref'       => 'test',
            'status'           => 'approved',
            'approved_at'      => date('Y-m-d H:i:s'),
            'approved_by'      => 'owner',
            'revoked_at'       => null,
            'created_at'       => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ], $overrides);

        $this->conn->table('community_answers')->insert($row);

        return (int) $this->conn->insertID();
    }

    /**
     * @param array<string, mixed> $overrides
     * @return callable(string, mixed): mixed
     */
    private function settingsWith(array $overrides = []): callable
    {
        $defaults = [
            'community.match.threshold_addressed' => 0.45,
            'community.match.threshold_overheard' => 0.80,
            'community.answer.max_age_days'       => 90,
            'community.question.max_age_hours'    => 48,
            'community.autoreply.delay_seconds'   => 75,
        ];
        $merged = array_merge($defaults, $overrides);

        return static fn (string $key, mixed $default = null): mixed
            => array_key_exists($key, $merged) ? $merged[$key] : $default;
    }

    private function matcher(array $settingsOverrides = []): CommunityAnswerMatcher
    {
        return new CommunityAnswerMatcher(
            new CommunityAnswerModel(),
            new CommunityMessageModel(),
            null,
            $this->settingsWith($settingsOverrides)
        );
    }

    // ── полоса A: адресное никогда не молчит ────────────────────────────

    public function testAddressedWithoutMatchAnswersUnknownAndEscalates(): void
    {
        $message = $this->insertMessage(['addressed_to_bot' => 1, 'text' => 'а расскажи про совсем другую тему']);

        $decision = $this->matcher()->match($message);

        $this->assertTrue($decision->isAnswerNow());
        $this->assertTrue($decision->escalated);
        $this->assertNull($decision->answerId);
        $this->assertStringContainsString('Не знаю', (string) $decision->text);
        $this->assertFalse($decision->isSilent());
    }

    public function testAddressedWithMatchAnswersBankTextImmediately(): void
    {
        $answerId = $this->insertBankAnswer([
            'question_pattern' => 'где найти теплицу для земледелия',
            'answer_text'      => 'Теплица строится на базе.',
        ]);
        $message = $this->insertMessage(['addressed_to_bot' => 1, 'text' => 'Роби, где найти теплицу для земледелия?']);

        $decision = $this->matcher()->match($message);

        $this->assertTrue($decision->isAnswerNow());
        $this->assertFalse($decision->escalated);
        $this->assertSame($answerId, $decision->answerId);
        $this->assertSame('Теплица строится на базе.', $decision->text);
        $this->assertNull($decision->delaySeconds);
    }

    // ── полоса B: два порога ─────────────────────────────────────────────

    public function testOverheardBetweenThresholdsGetsReceiptOnlyNotAnswer(): void
    {
        // Порог намеренно широкий (0.05..0.9), чтобы поймать реальную похожесть
        // между этим вопросом и записью банка строго между двумя порогами.
        $this->insertBankAnswer(['question_pattern' => 'где найти теплицу для земледелия огорода']);
        $message = $this->insertMessage(['addressed_to_bot' => 0, 'text' => 'где вообще теплицу искать']);

        $decision = $this->matcher(['community.match.threshold_addressed' => 0.01, 'community.match.threshold_overheard' => 0.90])
            ->match($message);

        $this->assertTrue($decision->isReceiptOnly());
        $this->assertFalse($decision->isAnswerAfterDelay());
    }

    public function testOverheardAboveThresholdGetsAnswerAfterDelay(): void
    {
        $answerId = $this->insertBankAnswer(['question_pattern' => 'где найти теплицу для земледелия']);
        $message  = $this->insertMessage(['addressed_to_bot' => 0, 'text' => 'где найти теплицу для земледелия']);

        $decision = $this->matcher(['community.autoreply.delay_seconds' => 75])->match($message);

        $this->assertTrue($decision->isAnswerAfterDelay());
        $this->assertSame($answerId, $decision->answerId);
        $this->assertSame(75, $decision->delaySeconds);
    }

    // ── отмена выдержки человеком ────────────────────────────────────────

    public function testDelayIsCancelledWhenHumanRepliedInThread(): void
    {
        $message = $this->insertMessage(['message_id' => 501, 'telegram_user_id' => 4242]);
        $this->assertFalse($this->matcher()->isCancelledByHumanReply($message));

        $this->insertMessage(['reply_to_message_id' => 501, 'telegram_user_id' => 999]);

        $this->assertTrue($this->matcher()->isCancelledByHumanReply($message));
    }

    public function testDelayIsNotCancelledByAuthorsOwnReply(): void
    {
        $message = $this->insertMessage(['message_id' => 502, 'telegram_user_id' => 4242]);

        $this->insertMessage(['reply_to_message_id' => 502, 'telegram_user_id' => 4242]);

        $this->assertFalse($this->matcher()->isCancelledByHumanReply($message));
    }

    public function testDelayIsCancelledByAnotherPersonEvenAfterAuthorsOwnReply(): void
    {
        $message = $this->insertMessage(['message_id' => 503, 'telegram_user_id' => 4242]);

        $this->insertMessage(['reply_to_message_id' => 503, 'telegram_user_id' => 4242]);
        $this->assertFalse($this->matcher()->isCancelledByHumanReply($message));

        $this->insertMessage(['reply_to_message_id' => 503, 'telegram_user_id' => 999]);
        $this->assertTrue($this->matcher()->isCancelledByHumanReply($message));
    }

    // ── банк: draft не матчится никогда ─────────────────────────────────

    public function testDraftBankRecordNeverMatches(): void
    {
        $this->insertBankAnswer(['status' => 'draft', 'question_pattern' => 'где найти теплицу для земледелия']);
        $message = $this->insertMessage(['addressed_to_bot' => 1, 'text' => 'где найти теплицу для земледелия']);

        $decision = $this->matcher()->match($message);

        $this->assertTrue($decision->isAnswerNow());
        $this->assertTrue($decision->escalated); // упало на UNKNOWN, банк не участвовал
        $this->assertNull($decision->answerId);
    }

    // ── банк: отозванная запись не матчится ─────────────────────────────

    public function testRevokedBankRecordDoesNotMatch(): void
    {
        $this->insertBankAnswer([
            'question_pattern' => 'где найти теплицу для земледелия',
            'revoked_at'       => date('Y-m-d H:i:s'),
        ]);
        $message = $this->insertMessage(['addressed_to_bot' => 1, 'text' => 'где найти теплицу для земледелия']);

        $decision = $this->matcher()->match($message);

        $this->assertTrue($decision->escalated);
        $this->assertNull($decision->answerId);
    }

    // ── банк: старше 90 дней не даёт публичного авто-ответа ─────────────

    public function testStaleApprovedRecordDoesNotYieldAutoAnswer(): void
    {
        $oldDate = (new DateTimeImmutable())->modify('-91 days')->format('Y-m-d H:i:s');
        $this->insertBankAnswer([
            'question_pattern' => 'где найти теплицу для земледелия',
            'approved_at'      => $oldDate,
        ]);
        $message = $this->insertMessage(['addressed_to_bot' => 0, 'text' => 'где найти теплицу для земледелия']);

        $decision = $this->matcher()->match($message);

        $this->assertFalse($decision->isAnswerAfterDelay(), 'запись старше 90 дней не должна давать авто-ответ полосы B');
        $this->assertTrue($decision->isReceiptOnly());
    }

    // ── вопрос старше 48 часов не отвечается публично ────────────────────

    public function testQuestionOlderThanMaxAgeIsNotAnsweredPublicly(): void
    {
        $this->insertBankAnswer(['question_pattern' => 'где найти теплицу для земледелия']);

        $now      = new DateTimeImmutable();
        $sentAt   = $now->modify('-49 hours')->format('Y-m-d H:i:s');
        $message  = $this->insertMessage(['addressed_to_bot' => 0, 'text' => 'где найти теплицу для земледелия', 'sent_at' => $sentAt]);

        $decision = $this->matcher()->match($message, $now);

        $this->assertFalse($decision->isAnswerAfterDelay());
        $this->assertTrue($decision->isReceiptOnly());

        // Адресный собрат того же возраста тоже не получает банк-текст — уходит в UNKNOWN,
        // но по-прежнему НЕ молчит (инвариант полосы A).
        $addressed = $this->insertMessage([
            'addressed_to_bot' => 1, 'text' => 'где найти теплицу для земледелия', 'sent_at' => $sentAt, 'message_id' => 777,
        ]);
        $decisionA = $this->matcher()->match($addressed, $now);
        $this->assertTrue($decisionA->isAnswerNow());
        $this->assertTrue($decisionA->escalated);
    }

    public function testQuestionWithinMaxAgeStillAnsweredAfterDelay(): void
    {
        $answerId = $this->insertBankAnswer(['question_pattern' => 'где найти теплицу для земледелия']);

        $now     = new DateTimeImmutable();
        $sentAt  = $now->modify('-47 hours')->format('Y-m-d H:i:s');
        $message = $this->insertMessage(['addressed_to_bot' => 0, 'text' => 'где найти теплицу для земледелия', 'sent_at' => $sentAt]);

        $decision = $this->matcher()->match($message, $now);

        $this->assertTrue($decision->isAnswerAfterDelay());
        $this->assertSame($answerId, $decision->answerId);
    }

    // ── склейка дублей ─────────────────────────────────────────────────

    public function testFiveDuplicateQuestionsInOneTopicYieldOneAnswerNotFive(): void
    {
        $this->insertBankAnswer(['question_pattern' => 'где найти теплицу для земледелия']);

        $messages = [];
        for ($i = 0; $i < 5; $i++) {
            $messages[] = $this->insertMessage([
                'addressed_to_bot' => 0,
                'text'             => 'где найти теплицу для земледелия',
                'telegram_user_id' => 5000 + $i,
            ]);
        }

        $decisions = array_map(fn (array $m) => $this->matcher()->match($m), $messages);

        $answering = array_filter($decisions, static fn ($d) => $d->isAnswerAfterDelay());
        $silenced  = array_filter($decisions, static fn ($d) => $d->isSilent());

        $this->assertCount(1, $answering, 'ровно одно из пяти дублирующих сообщений обязано дать реальный ответ');
        $this->assertCount(4, $silenced, 'остальные четыре покрыты первым ответом, не порождают свой');

        // Первое (наименьший id) решение — то, что содержит реальный ответ, и покрывает
        // все пять message-строк одним ответом, адресованным всем.
        $answerDecision = array_values($answering)[0];
        $coveredIds     = $answerDecision->coveredMessageIds;
        sort($coveredIds);
        $expectedIds = array_map(static fn (array $m) => $m['id'], $messages);
        sort($expectedIds);
        $this->assertSame($expectedIds, $coveredIds);
    }

    // ── регрессия: рейд-босс vs поход — самый опасный узел ──────────────

    public function testRaidBossQuestionDoesNotMatchKillingTimeOnMarchQuestion(): void
    {
        $answerId = $this->insertBankAnswer([
            'question_pattern' => 'как убить рейд-босса на острове, какая тактика',
            'answer_text'      => 'Рейд-босс бьётся отрядом, тактика описана в «Путь новичка».',
        ]);

        $marchQuestion = $this->insertMessage([
            'addressed_to_bot' => 1,
            'text'             => 'как убить время в походе, скучно идти',
        ]);

        $decision = $this->matcher()->match($marchQuestion);

        // Не тот ответ: либо не матчится вовсе (UNKNOWN), либо матчится на что-то
        // другое — но точно НЕ на запись про рейд-босса.
        $this->assertNotSame($answerId, $decision->answerId, '«как убить время в походе» не должно матчиться на «как убить рейд-босса»');
    }

    public function testRaidBossQuestionDoesMatchItsOwnBankRecord(): void
    {
        $answerId = $this->insertBankAnswer([
            'question_pattern' => 'как убить рейд-босса на острове, какая тактика',
            'answer_text'      => 'Рейд-босс бьётся отрядом, тактика описана в «Путь новичка».',
        ]);

        $bossQuestion = $this->insertMessage([
            'addressed_to_bot' => 1,
            'text'             => 'подскажи как убить рейд-босса, какая тактика лучше',
        ]);

        $decision = $this->matcher()->match($bossQuestion);

        $this->assertSame($answerId, $decision->answerId, 'близкая формулировка того же вопроса обязана матчиться');
    }
}
