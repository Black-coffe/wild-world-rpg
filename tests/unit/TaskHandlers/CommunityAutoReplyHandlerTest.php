<?php

declare(strict_types=1);

namespace Tests\Unit\TaskHandlers;

use App\Database\Migrations\Adr176CreateCommunityAnswersTable;
use App\Database\Migrations\Adr176CreateCommunityMessagesTable;
use App\Database\Migrations\CreateAdminAuditLogTable;
use App\Models\AdminAuditLogModel;
use App\Models\CommunityAnswerModel;
use App\Models\CommunityMessageModel;
use App\Services\Community\CommunityAnswerMatcher;
use App\Services\Community\CommunityChatSender;
use App\Services\Community\CommunityGuard;
use App\Services\GameSettings\GameSettingsService;
use App\TaskHandlers\Community\CommunityAutoReplyHandler;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\CommunityVoice;
use Config\Database;
use DateTimeImmutable;
use Longman\TelegramBot\Entities\ServerResponse;
use ReflectionMethod;
use RuntimeException;

/**
 * community-chat-bot-09 — `CommunityAutoReplyHandler`: связывает матчер (story 08),
 * гвард (story 07) и отправителя (story 06) в один тик. Схема строится прогоном
 * реальных миграций на группу `tests` (Forge) — паттерн `CommunityCleanupTest`/
 * `CommunityExportTest` (story community-chat-bot-36): изолированная ручная
 * `CREATE TABLE` разошлась с продовой миграцией и давала зелёный тест на схеме,
 * которой на проде нет.
 *
 * Telegram нигде не инициализируется: `CommunityChatSender` инжектируется с
 * собственным `$transport`-callable, реальный Bot API не звонит ни разу
 * (`feedback_taskhandler_telegram_init_in_tests`).
 *
 * @internal
 */
final class CommunityAutoReplyHandlerTest extends CIUnitTestCase
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

    // ── helpers: fixtures ────────────────────────────────────────────────

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

    private function statusOf(int $rowId): ?string
    {
        $row = $this->conn->table('community_messages')->where('id', $rowId)->get(1)->getRowArray();

        return $row !== null && isset($row['status']) ? (string) $row['status'] : null;
    }

    private function reactionCount(int $rowId): int
    {
        return (int) $this->conn->table('admin_audit_log')
            ->where('action', 'COMMUNITY_REACTION_SENT')
            ->where('target_id', $rowId)
            ->countAllResults();
    }

    private function auditCount(int $rowId, string $action): int
    {
        return (int) $this->conn->table('admin_audit_log')
            ->where('action', $action)
            ->where('target_id', $rowId)
            ->countAllResults();
    }

    /** @return list<array<string, mixed>> */
    private function auditRows(int $rowId, string $action): array
    {
        return $this->conn->table('admin_audit_log')
            ->where('action', $action)
            ->where('target_id', $rowId)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * Тот же водораздел, что снимает {@see CommunityAutoReplyHandler} перед
     * `sendAnswer()` (story 51) — auto-increment `id`, а не часы БД.
     */
    private function auditWatermarkId(): int
    {
        $row = $this->conn->query('SELECT MAX(id) AS m FROM admin_audit_log')->getRowArray();

        return isset($row['m']) && is_numeric($row['m']) ? (int) $row['m'] : 0;
    }

    // ── helpers: сборка сервисов ────────────────────────────────────────

    /** @param array<string, mixed> $overrides @return callable(string, mixed): mixed */
    private function matcherSettings(array $overrides = []): callable
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
            $this->matcherSettings($settingsOverrides)
        );
    }

    /**
     * Гвард с широко открытым белым корпусом — пропускает и текст банка (fixture
     * `insertBankAnswer()`), и канонические строки `CommunityVoice` дословным
     * фрагментом-самим-собой (провенанс канона — предмет `CommunityVoiceCanonTest`,
     * story 04, не этой story; здесь важна только оркестровка, не алгоритм гварда).
     */
    private function permissiveGuard(): CommunityGuard
    {
        $corpus = [
            [
                'source' => 'guide:building',
                'text'   => 'Теплица строится на базе, доступна после разблокировки постройки. '
                    . 'Мастерская и Верстак общий открывают крафт на разных уровнях.',
            ],
            ['source' => 'voice:unknown', 'text' => implode(' ', \Config\CommunityVoice::UNKNOWN)],
        ];

        return new CommunityGuard($corpus, new GameSettingsService());
    }

    /**
     * Story 71 (ADR-178 сняла с провенанса право вето, story 63): пустой корпус
     * больше НЕ гарантирует deny — при default `provenance_mode=advisory` пустой
     * корпус даёт `allow()` с пометками, не `deny()`. Оставлен только для сценариев,
     * которым нужен произвольный `Verdict` (не важно, allow или deny) — реальный
     * отказ в тестах тика вызывается рубежом с сохранённым вето (лексический
     * стоп-лист/сравнительная форма/стоп-тема/килсвитч), см. `permissiveGuard()`
     * + текст-триггер в `insertBankAnswer()`.
     */
    private function denyingGuard(): CommunityGuard
    {
        return new CommunityGuard([], new GameSettingsService());
    }

    /** @param array<string, mixed> $overrides @return callable(string, mixed): mixed */
    private function senderSettings(array $overrides = []): callable
    {
        $defaults = [
            'community.enabled'                          => true,
            'community.autoreply.enabled'                 => true,
            'community.autoreply.silent_topics'            => '',
            'community.autoreply.max_per_hour_per_topic'   => 50,
            'community.autoreply.author_cooldown_seconds'  => 0,
            'community.autoreply.max_answer_chars'         => 600,
        ];
        $merged = array_merge($defaults, $overrides);

        return static fn (string $key, mixed $default = null): mixed
            => array_key_exists($key, $merged) ? $merged[$key] : $default;
    }

    private function okResponse(): ServerResponse
    {
        return new ServerResponse(['ok' => true, 'result' => ['message_id' => 1]], 'testbot');
    }

    /** @param list<array{method: string, data: array<string, mixed>}> $calls */
    private function sender(array &$calls, array $senderSettingsOverrides = []): CommunityChatSender
    {
        return new CommunityChatSender(
            new CommunityMessageModel(),
            null,
            new AdminAuditLogModel(),
            $this->conn,
            function (string $method, array $data) use (&$calls): ServerResponse {
                $calls[] = ['method' => $method, 'data' => $data];

                return $this->okResponse();
            },
            $this->senderSettings($senderSettingsOverrides)
        );
    }

    /**
     * @param array<string, mixed> $topLevelSettings
     * @param (callable(): void)|null $telegramInitializer story 52 — по умолчанию no-op:
     *        реальный `BaseTaskHandler::telegram()` в тестах не трогаем вовсе
     *        (`feedback_taskhandler_telegram_init_in_tests` — на CI нет `telegram.API_KEY`).
     */
    private function handler(
        CommunityChatSender $sender,
        ?CommunityGuard $guard = null,
        array $topLevelSettings = [],
        ?DateTimeImmutable $now = null,
        array $matcherSettingsOverrides = [],
        ?callable $telegramInitializer = null,
    ): CommunityAutoReplyHandler {
        $defaults = ['community.enabled' => true, 'community.autoreply.enabled' => true];
        $merged   = array_merge($defaults, $topLevelSettings);

        return new CommunityAutoReplyHandler(
            new CommunityMessageModel(),
            new CommunityAnswerModel(),
            $this->matcher($matcherSettingsOverrides),
            $guard ?? $this->permissiveGuard(),
            $sender,
            new AdminAuditLogModel(),
            null,
            static fn (string $key, mixed $default = null): mixed
                => array_key_exists($key, $merged) ? $merged[$key] : $default,
            $now,
            $telegramInitializer ?? static function (): void {
                // no-op — реальный Telegram-объект тестам не нужен и не безопасен на CI.
            },
        );
    }

    // ── килсвитч ─────────────────────────────────────────────────────────

    public function testDisabledAutoreplySendsNothingButLeavesMessageAsIsForIngest(): void
    {
        $this->insertBankAnswer();
        $message = $this->insertMessage(['addressed_to_bot' => 1]);

        $calls   = [];
        $sender  = $this->sender($calls);
        $handler = $this->handler($sender, null, ['community.autoreply.enabled' => false]);

        $handler->handle();

        $this->assertSame([], $calls, 'выключенный autoreply.enabled не должен звонить транспорту вовсе');
        $this->assertSame('new', $this->statusOf((int) $message['id']));
    }

    public function testDisabledCommunityEnabledSendsNothing(): void
    {
        $this->insertBankAnswer();
        $message = $this->insertMessage(['addressed_to_bot' => 1]);

        $calls   = [];
        $sender  = $this->sender($calls);
        $handler = $this->handler($sender, null, ['community.enabled' => false]);

        $handler->handle();

        $this->assertSame([], $calls);
        $this->assertSame('new', $this->statusOf((int) $message['id']));
    }

    // ── answer_now (полоса A) ────────────────────────────────────────────

    public function testAddressedMatchGetsAnsweredImmediately(): void
    {
        $this->insertBankAnswer([
            'question_pattern' => 'где найти теплицу для земледелия',
            'answer_text'      => 'Теплица строится на базе.',
        ]);
        $message = $this->insertMessage(['addressed_to_bot' => 1, 'text' => 'Роби, где найти теплицу для земледелия?']);

        $calls   = [];
        $sender  = $this->sender($calls);
        $handler = $this->handler($sender);

        $handler->handle();

        $this->assertCount(1, $calls);
        $this->assertSame('sendMessage', $calls[0]['method']);
        $this->assertSame('Теплица строится на базе.', $calls[0]['data']['text']);
        $this->assertSame('answered', $this->statusOf((int) $message['id']));
    }

    /**
     * Story 57, дефект 1: выборка тика раньше резала `WHERE is_question=1` — прямое
     * обращение вроде «Роби, подскажи» (story 56, честная эвристика оставляет
     * `is_question=0` для таких строк) до матчера не доезжало никогда. Проверяем
     * `is_question=0 AND addressed_to_bot=1`.
     */
    public function testAddressedWithoutQuestionHeuristicStillReachesMatcher(): void
    {
        $this->insertBankAnswer([
            'question_pattern' => 'где найти теплицу для земледелия',
            'answer_text'      => 'Теплица строится на базе.',
        ]);
        $message = $this->insertMessage([
            'is_question'      => 0,
            'addressed_to_bot' => 1,
            'text'             => 'Роби, где найти теплицу для земледелия',
        ]);

        $calls   = [];
        $sender  = $this->sender($calls);
        $handler = $this->handler($sender);

        $handler->handle();

        $this->assertCount(1, $calls, 'is_question=0 не должен блокировать addressed_to_bot=1 на входе в тик');
        $this->assertSame('sendMessage', $calls[0]['method']);
        $this->assertSame('answered', $this->statusOf((int) $message['id']));
    }

    /**
     * Story 57, дефект 1, контроль: подслушанное (не обращённое к боту) сообщение с
     * `is_question=0` по-прежнему не входит в выборку тика — расширение фильтра не
     * должно превратить полосу B в «отвечаем на всё подряд».
     */
    public function testOverheardWithoutQuestionHeuristicStillSkipsTick(): void
    {
        $this->insertBankAnswer([
            'question_pattern' => 'где найти теплицу для земледелия',
            'answer_text'      => 'Теплица строится на базе.',
        ]);
        $message = $this->insertMessage([
            'is_question'      => 0,
            'addressed_to_bot' => 0,
            'text'             => 'где найти теплицу для земледелия',
        ]);

        $calls   = [];
        $sender  = $this->sender($calls);
        $handler = $this->handler($sender);

        $handler->handle();

        $this->assertSame([], $calls, 'подслушанная строка без вопроса и без обращения не должна попадать в тик');
        $this->assertSame('new', $this->statusOf((int) $message['id']));
    }

    // ── story 52: Telegram-мост инициализируется лениво перед реальной отправкой ──

    public function testActualSendInitializesTelegramBeforeSending(): void
    {
        $this->insertBankAnswer([
            'question_pattern' => 'где найти теплицу для земледелия',
            'answer_text'      => 'Теплица строится на базе.',
        ]);
        $this->insertMessage(['addressed_to_bot' => 1, 'text' => 'Роби, где найти теплицу для земледелия?']);

        $calls        = [];
        $sender       = $this->sender($calls);
        $initializedCount = 0;
        $handler      = $this->handler($sender, null, [], null, [], function () use (&$initializedCount): void {
            ++$initializedCount;
        });

        $handler->handle();

        $this->assertCount(1, $calls, 'sanity: реплай реально ушёл');
        $this->assertSame(
            1,
            $initializedCount,
            'перед реальной отправкой (`CommunityChatSender::sendAnswer()`, минующей `safeSendMessage()`) '
                . 'тик обязан инициализировать Telegram-мост — иначе `Request::send()` в кроне падает '
                . 'на `getBotUsername() on null` (story 52)'
        );
    }

    public function testReceiptOnlyReactionAlsoInitializesTelegramBeforeSending(): void
    {
        $this->insertMessage(['addressed_to_bot' => 0, 'is_question' => 0]);

        $calls            = [];
        $sender           = $this->sender($calls);
        $initializedCount = 0;
        $handler          = $this->handler($sender, null, [], null, [], function () use (&$initializedCount): void {
            ++$initializedCount;
        });

        $handler->handle();

        // Не проверяем конкретную ветку матчера здесь — важно только: если ушла хоть
        // одна реакция/ответ, инициализация обязана была случиться ровно перед ней.
        if ($calls !== []) {
            $this->assertGreaterThanOrEqual(1, $initializedCount);
        } else {
            $this->assertSame(0, $initializedCount);
        }
    }

    public function testNoCandidatesToSendDoesNotInitializeTelegram(): void
    {
        // Килсвитч выключен — тик обязан выйти ДО первого запроса к БД сообщений
        // (контракт story 09/14), значит и до какой-либо инициализации Telegram.
        $this->insertBankAnswer();
        $this->insertMessage(['addressed_to_bot' => 1]);

        $calls            = [];
        $sender           = $this->sender($calls);
        $initializedCount = 0;
        $handler          = $this->handler($sender, null, ['community.autoreply.enabled' => false], null, [], function () use (&$initializedCount): void {
            ++$initializedCount;
        });

        $handler->handle();

        $this->assertSame([], $calls);
        $this->assertSame(
            0,
            $initializedCount,
            'тик без единого кандидата на отправку не должен платить за инициализацию Telegram (лень сохранена)'
        );
    }

    // ── answer_now без совпадения → UNKNOWN, ушло, но escalated ──────────

    public function testAddressedWithoutMatchSendsUnknownButStaysEscalated(): void
    {
        $message = $this->insertMessage(['addressed_to_bot' => 1, 'text' => 'а расскажи про совсем другую тему']);

        $calls   = [];
        $sender  = $this->sender($calls);
        $handler = $this->handler($sender);

        $handler->handle();

        $this->assertCount(1, $calls, 'полоса A обязана ответить даже без совпадения банка');
        $this->assertSame('escalated', $this->statusOf((int) $message['id']));
    }

    // ── story 55: вердикт гварда deny → escalated, но текст маршрута доезжает ───

    public function testGuardDenyEscalatesAndSendsRouteTextToPlayer(): void
    {
        // Story 71 (ADR-178 сняла вето с провенанса): деним рубежом 3 (лексический
        // стоп-лист, вето сохранено) — «быстрее» есть в CommunityGuard::LEXICAL_STOPLIST.
        $this->insertBankAnswer([
            'question_pattern' => 'где найти теплицу для земледелия',
            'answer_text'      => 'Теплица строится на базе быстрее.',
        ]);
        $message = $this->insertMessage(['addressed_to_bot' => 1, 'text' => 'Роби, где найти теплицу для земледелия?']);

        $calls   = [];
        $sender  = $this->sender($calls);
        $handler = $this->handler($sender, $this->permissiveGuard());

        $handler->handle();

        // При deny больше не молчание: реплай с текстом маршрута уходит игроку,
        // как и обычный банк-ответ — story 55, дефект «молчание вместо маршрута».
        $sendCalls = array_values(array_filter($calls, static fn (array $c): bool => $c['method'] === 'sendMessage'));
        $this->assertCount(1, $sendCalls, 'отказ гварда обязан дать игроку текст маршрута реплаем, не только реакцию');
        $this->assertContains($sendCalls[0]['data']['text'], CommunityVoice::REFUSAL_WITH_ROUTE);
        $this->assertSame((int) $message['message_id'], $sendCalls[0]['data']['reply_to_message_id'] ?? null);
        $this->assertSame('escalated', $this->statusOf((int) $message['id']));
        $this->assertSame(1, $this->reactionCount((int) $message['id']), 'реакция остаётся вместе с текстом, не вместо него');

        // Story 57, дефект 2: маршрут отказа обязан уйти через sendGuardRoute() и
        // писать своё аудит-действие COMMUNITY_ROUTE_SENT, а не COMMUNITY_ANSWER_SENT —
        // иначе отказ гварда считался бы ответом бота в метрике /admin/community.
        $this->assertSame(
            1,
            $this->auditCount((int) $message['id'], 'COMMUNITY_ROUTE_SENT'),
            'маршрут отказа обязан писать COMMUNITY_ROUTE_SENT, а не COMMUNITY_ANSWER_SENT'
        );
        $this->assertSame(0, $this->auditCount((int) $message['id'], 'COMMUNITY_ANSWER_SENT'));
    }

    /**
     * Story 55, анти-спам — потолок в час, у которого один и тот же гейт что и у
     * банк-ответа, обязан заблокировать и текст маршрута отказа: иначе поток
     * провокаций гварда стал бы отдельным, не ограниченным каналом сообщений бота.
     */
    public function testGuardDenyRouteTextRespectsHourlyCapLeavesOnlyReaction(): void
    {
        // Story 71 (ADR-178 сняла вето с провенанса) — деним рубежом 3 (лексический
        // стоп-лист, вето сохранено), не провенансом.
        $this->insertBankAnswer([
            'question_pattern' => 'где найти теплицу для земледелия',
            'answer_text'      => 'Теплица строится на базе быстрее.',
        ]);
        $message = $this->insertMessage(['addressed_to_bot' => 1, 'text' => 'Роби, где найти теплицу для земледелия?']);

        $calls   = [];
        $sender  = $this->sender($calls, ['community.autoreply.max_per_hour_per_topic' => 0]);
        $handler = $this->handler($sender, $this->permissiveGuard());

        $handler->handle();

        $methods = array_column($calls, 'method');
        $this->assertNotContains('sendMessage', $methods, 'исчерпанный потолок в час обязан заблокировать текст маршрута так же, как обычный ответ');
        $this->assertContains('setMessageReaction', $methods, 'прежнее поведение (только реакция) сохраняется при сработавшем ограничителе');
        $this->assertSame('escalated', $this->statusOf((int) $message['id']));

        // Story 57, дефект 2: маршрут отказа уходит через sendGuardRoute() — своё
        // аудит-действие COMMUNITY_ROUTE_REJECTED, не COMMUNITY_ANSWER_REJECTED.
        $rejected = $this->auditRows((int) $message['id'], 'COMMUNITY_ROUTE_REJECTED');
        $this->assertCount(1, $rejected, 'отказ ограничителя обязан быть виден в журнале, а не проглочен молча');
        $payload = json_decode((string) $rejected[0]['payload'], true);
        $this->assertSame('topic_rate_limit', $payload['reason'] ?? null);
    }

    /**
     * Story 55, анти-спам — кулдаун автора, тот же гейт, что у банк-ответа: если
     * автор уже получил автоматический ответ недавно, повторный отказ-с-маршрутом от
     * того же автора текстом не идёт, только реакция.
     */
    public function testGuardDenyRouteTextRespectsAuthorCooldownLeavesOnlyReaction(): void
    {
        // Story 71 (ADR-178 сняла вето с провенанса) — деним рубежом 3 (лексический
        // стоп-лист, вето сохранено), не провенансом.
        $this->insertBankAnswer([
            'question_pattern' => 'где найти теплицу для земледелия',
            'answer_text'      => 'Теплица строится на базе быстрее.',
        ]);
        $authorId = 777777;
        $prior    = $this->insertMessage(['telegram_user_id' => $authorId, 'status' => 'answered', 'is_question' => 0]);
        $this->conn->table('admin_audit_log')->insert([
            'admin_user_id' => 0,
            'action'        => 'COMMUNITY_ANSWER_SENT',
            'target_type'   => 'community_message',
            'target_id'     => $prior['id'],
            'payload'       => json_encode(['reason' => 'ok', 'telegram_user_id' => $authorId], JSON_UNESCAPED_UNICODE),
            'ip_address'    => null,
            'user_agent'    => null,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        $message = $this->insertMessage([
            'telegram_user_id' => $authorId,
            'addressed_to_bot' => 1,
            'text'             => 'Роби, где найти теплицу для земледелия?',
        ]);

        $calls   = [];
        $sender  = $this->sender($calls, ['community.autoreply.author_cooldown_seconds' => 600]);
        $handler = $this->handler($sender, $this->permissiveGuard());

        $handler->handle();

        $methods = array_column($calls, 'method');
        $this->assertNotContains('sendMessage', $methods, 'кулдаун автора обязан заблокировать текст маршрута так же, как обычный ответ');
        $this->assertContains('setMessageReaction', $methods, 'прежнее поведение (только реакция) сохраняется при сработавшем ограничителе');
        $this->assertSame('escalated', $this->statusOf((int) $message['id']));
    }

    /**
     * Story 57, дефект 3: `escalateGuardDenial()` раньше апдейтил статус безусловным
     * `whereIn('id', …)->update()`, без `WHERE status=…` — правка владельца между
     * чтением тика ({@see CommunityAutoReplyHandler::handle()}) и этим вызовом
     * терялась молча. Симулируем ровно этот зазор через `ReflectionMethod`: строка
     * уже сдвинута на `'answered'` кем-то другим ДО вызова эскалации.
     */
    public function testEscalateGuardDenialDoesNotOverwriteStatusChangedBetweenReadAndWrite(): void
    {
        // handle() читал строку как 'new' на старте тика; к моменту эскалации
        // владелец руками (или community:cleanup) уже перевёл её в 'answered'.
        $message = $this->insertMessage(['status' => 'answered']);

        $calls   = [];
        $sender  = $this->sender($calls);
        $handler = $this->handler($sender, $this->denyingGuard());

        $verdict = $this->denyingGuard()->verdict('что угодно', (string) $message['text'], null);

        $escalate = new ReflectionMethod(CommunityAutoReplyHandler::class, 'escalateGuardDenial');
        $escalate->setAccessible(true);
        $applied = $escalate->invoke($handler, [(int) $message['id']], $verdict);

        $this->assertFalse($applied, 'эскалация обязана отказаться применяться, если статус уже не new — факт видим по возврату false');
        $this->assertSame(
            'answered',
            $this->statusOf((int) $message['id']),
            'чужая правка статуса между чтением тика и эскалацией не должна затираться'
        );
        $this->assertSame(0, $this->auditCount((int) $message['id'], 'COMMUNITY_ROUTE_LOGGED'), 'неприменённая эскалация не должна оставлять аудит-след маршрута');
        $this->assertLogContains('error', 'guard denial escalation skipped', 'факт неприменения обязан быть виден в журнале приложения');
    }

    // ── receipt_only: реакция один раз, не на каждом тике ────────────────

    public function testReceiptOnlyReactsOnceNotEveryTick(): void
    {
        // Порог полосы B заведомо недостижим — гарантированный receipt_only.
        $this->insertBankAnswer(['question_pattern' => 'полностью другая формулировка не похожая вовсе']);
        $message = $this->insertMessage(['addressed_to_bot' => 0, 'text' => 'где найти теплицу для земледелия']);

        $calls   = [];
        $sender  = $this->sender($calls);
        $handler = $this->handler($sender);

        $handler->handle();
        $this->assertSame(1, $this->reactionCount((int) $message['id']));
        $this->assertCount(1, $calls);

        // Второй тик — статус остаётся 'new' (ещё открытый вопрос), но реакция не дублируется.
        $handler->handle();
        $this->assertSame(1, $this->reactionCount((int) $message['id']), 'реакция не должна ставиться повторно');
        $this->assertCount(1, $calls);
        $this->assertSame('new', $this->statusOf((int) $message['id']));
    }

    // ── answer_after_delay: до выдержки — тишина, после — ответ ──────────

    public function testDelayedAnswerWaitsForDelayThenSends(): void
    {
        $this->insertBankAnswer(['question_pattern' => 'где найти теплицу для земледелия']);
        $sentAt  = (new DateTimeImmutable())->modify('-10 seconds')->format('Y-m-d H:i:s');
        $message = $this->insertMessage(['addressed_to_bot' => 0, 'text' => 'где найти теплицу для земледелия', 'sent_at' => $sentAt]);

        $calls  = [];
        $sender = $this->sender($calls);

        // Выдержка 75с ещё не истекла (прошло 10с) — тишина.
        $earlyHandler = $this->handler($sender, null, [], new DateTimeImmutable(), ['community.autoreply.delay_seconds' => 75]);
        $earlyHandler->handle();
        $this->assertSame([], $calls);
        $this->assertSame('new', $this->statusOf((int) $message['id']));

        // Выдержка истекла (моделируем «сейчас» через 80с после sent_at).
        $laterNow    = (new DateTimeImmutable($sentAt))->modify('+80 seconds');
        $lateHandler = $this->handler($sender, null, [], $laterNow, ['community.autoreply.delay_seconds' => 75]);
        $lateHandler->handle();

        $this->assertCount(1, $calls);
        $this->assertSame('answered', $this->statusOf((int) $message['id']));
    }

    // ── человек ответил в тред за время выдержки → отмена НАВСЕГДА ───────

    public function testHumanReplyDuringDelayCancelsForeverNotJustPostpones(): void
    {
        $this->insertBankAnswer(['question_pattern' => 'где найти теплицу для земледелия']);
        $sentAt  = (new DateTimeImmutable())->modify('-100 seconds')->format('Y-m-d H:i:s');
        $message = $this->insertMessage([
            'addressed_to_bot' => 0,
            'text'             => 'где найти теплицу для земледелия',
            'sent_at'          => $sentAt,
            'message_id'       => 601,
        ]);
        // Человек (другой telegram_user_id) ответил реплаем на исходное сообщение.
        $this->insertMessage(['reply_to_message_id' => 601, 'telegram_user_id' => 999999, 'is_question' => 0]);

        $calls   = [];
        $sender  = $this->sender($calls);
        $now     = (new DateTimeImmutable($sentAt))->modify('+80 seconds'); // выдержка (75с) истекла
        $handler = $this->handler($sender, null, [], $now, ['community.autoreply.delay_seconds' => 75]);

        $handler->handle();

        $this->assertSame([], $calls, 'ответ человека отменяет отложенную публикацию — sendAnswer не вызывается');
        $this->assertSame('ignored', $this->statusOf((int) $message['id']));
    }

    // ── повторный тик не отправляет второй ответ ──────────────────────────

    public function testSecondTickDoesNotResendAlreadyAnsweredMessage(): void
    {
        $this->insertBankAnswer([
            'question_pattern' => 'где найти теплицу для земледелия',
            'answer_text'      => 'Теплица строится на базе.',
        ]);
        $message = $this->insertMessage(['addressed_to_bot' => 1, 'text' => 'Роби, где найти теплицу для земледелия?']);

        $calls   = [];
        $sender  = $this->sender($calls);
        $handler = $this->handler($sender);

        $handler->handle();
        $this->assertCount(1, $calls);
        $this->assertSame('answered', $this->statusOf((int) $message['id']));

        $handler->handle();
        $this->assertCount(1, $calls, 'ответ на уже answered строку не должен уходить второй раз');
    }

    // ── отказ Telegram → остаётся 'new', повторится на следующем тике ────

    public function testFailedSendKeepsStatusNewForRetry(): void
    {
        $this->insertBankAnswer([
            'question_pattern' => 'где найти теплицу для земледелия',
            'answer_text'      => 'Теплица строится на базе.',
        ]);
        $message = $this->insertMessage(['addressed_to_bot' => 1, 'text' => 'Роби, где найти теплицу для земледелия?']);

        $sender = new CommunityChatSender(
            new CommunityMessageModel(),
            null,
            new AdminAuditLogModel(),
            $this->conn,
            static fn (): ServerResponse => new ServerResponse(['ok' => false, 'error_code' => 400, 'description' => 'boom'], 'testbot'),
            $this->senderSettings()
        );
        $handler = $this->handler($sender);

        $handler->handle();

        $this->assertSame('new', $this->statusOf((int) $message['id']), 'отказ Telegram не должен помечать строку answered');
    }

    // ── склейка дублей: один ответ закрывает все совпавшие строки ────────

    public function testDuplicateQuestionsAreClosedByOneAnswerNotFive(): void
    {
        $this->insertBankAnswer(['question_pattern' => 'где найти теплицу для земледелия']);

        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $m     = $this->insertMessage(['addressed_to_bot' => 0, 'text' => 'где найти теплицу для земледелия']);
            $ids[] = (int) $m['id'];
        }
        $sentAt = date('Y-m-d H:i:s', strtotime('-100 seconds'));
        $this->conn->table('community_messages')->whereIn('id', $ids)->update(['sent_at' => $sentAt]);

        $calls   = [];
        $sender  = $this->sender($calls);
        $now     = (new DateTimeImmutable($sentAt))->modify('+80 seconds');
        $handler = $this->handler($sender, null, [], $now, ['community.autoreply.delay_seconds' => 75]);

        $handler->handle();

        $this->assertCount(1, $calls, 'три дублирующих вопроса должны дать ровно одну отправку');
        foreach ($ids as $id) {
            $this->assertSame('answered', $this->statusOf($id), "строка {$id} обязана закрыться общим ответом");
        }
    }

    // ── story 23: дефект 1 — строка перехватывается ДО вызова Telegram ───

    public function testStatusIsClaimedBeforeTelegramIsCalledNotAfter(): void
    {
        $this->insertBankAnswer([
            'question_pattern' => 'где найти теплицу для земледелия',
            'answer_text'      => 'Теплица строится на базе.',
        ]);
        $message = $this->insertMessage(['addressed_to_bot' => 1, 'text' => 'Роби, где найти теплицу для земледелия?']);

        $statusAtCallTime = null;
        $sender           = new CommunityChatSender(
            new CommunityMessageModel(),
            null,
            new AdminAuditLogModel(),
            $this->conn,
            function (string $method, array $data) use (&$statusAtCallTime, $message): ServerResponse {
                // Контракт story: к моменту сетевого вызова строка уже НЕ 'new'.
                $statusAtCallTime = $this->statusOf((int) $message['id']);

                return $this->okResponse();
            },
            $this->senderSettings()
        );
        $handler = $this->handler($sender);

        $handler->handle();

        $this->assertNotNull($statusAtCallTime, 'транспорт обязан быть вызван');
        $this->assertNotSame('new', $statusAtCallTime, 'условный апдейт обязан произойти до вызова Telegram');
        $this->assertSame('answered', $this->statusOf((int) $message['id']));
    }

    public function testTransportExceptionAfterActualDeliveryDoesNotResend(): void
    {
        $this->insertBankAnswer([
            'question_pattern' => 'где найти теплицу для земледелия',
            'answer_text'      => 'Теплица строится на базе.',
        ]);
        $message = $this->insertMessage(['addressed_to_bot' => 1, 'text' => 'Роби, где найти теплицу для земледелия?']);

        $calls  = [];
        $sender = new CommunityChatSender(
            new CommunityMessageModel(),
            null,
            new AdminAuditLogModel(),
            $this->conn,
            function (string $method, array $data) use (&$calls): ServerResponse {
                $calls[] = ['method' => $method, 'data' => $data];

                // Ложноотрицательный ответ транспорта: исключение уже ПОСЛЕ фактической
                // доставки (например таймаут curl на подтверждении).
                throw new RuntimeException('curl timeout after delivery');
            },
            $this->senderSettings()
        );
        $handler = $this->handler($sender);

        $handler->handle();

        $this->assertCount(1, $calls);
        $this->assertNotSame('new', $this->statusOf((int) $message['id']), 'исключение после доставки не должно вернуть строку в очередь');

        // Второй тик — статус уже не 'new', матчер строку больше не видит вовсе.
        $handler->handle();
        $this->assertCount(1, $calls, 'ложноотрицательный ответ транспорта не должен давать второе сообщение');
    }

    // ── story 23: дефект 2 — терминальные отказы гейта не ретраятся вечно ─

    public function testTextTooLongIsTerminalNotRetried(): void
    {
        $this->insertBankAnswer([
            'question_pattern' => 'где найти теплицу для земледелия',
            'answer_text'      => 'Теплица строится на базе.',
        ]);
        $message = $this->insertMessage(['addressed_to_bot' => 1, 'text' => 'Роби, где найти теплицу для земледелия?']);

        $calls   = [];
        $sender  = $this->sender($calls, ['community.autoreply.max_answer_chars' => 1]);
        $handler = $this->handler($sender);

        for ($i = 0; $i < 10; $i++) {
            $handler->handle();
        }

        $this->assertSame([], $calls, 'гейт отказывает ДО sendMessage — транспорт не звонит вовсе');
        $this->assertSame('escalated', $this->statusOf((int) $message['id']), 'text_too_long обязан быть терминальным');
        $this->assertSame(
            1,
            $this->auditCount((int) $message['id'], 'COMMUNITY_ANSWER_REJECTED'),
            'десять тиков подряд с одним и тем же перманентным отказом не должны дать десять записей в журнале'
        );
    }

    public function testTopicRateLimitIsNotTerminalRetriedNextTick(): void
    {
        $this->insertBankAnswer([
            'question_pattern' => 'где найти теплицу для земледелия',
            'answer_text'      => 'Теплица строится на базе.',
        ]);
        $message = $this->insertMessage(['addressed_to_bot' => 1, 'text' => 'Роби, где найти теплицу для земледелия?']);

        $calls   = [];
        $sender  = $this->sender($calls, ['community.autoreply.max_per_hour_per_topic' => 0]);
        $handler = $this->handler($sender);

        $handler->handle();
        $this->assertSame('new', $this->statusOf((int) $message['id']), 'topic_rate_limit не терминален — строка остаётся new');

        $handler->handle();
        $this->assertSame(
            2,
            $this->auditCount((int) $message['id'], 'COMMUNITY_ANSWER_REJECTED'),
            'на следующем тике попытка есть — гейт отказывает снова'
        );
        $this->assertSame('new', $this->statusOf((int) $message['id']));
    }

    // ── story 23: дефект 3 — маршрут отказа доходит до адресата ──────────

    public function testDenyVerdictRouteIsObservableInAuditLog(): void
    {
        // Story 71 (ADR-178 сняла вето с провенанса) — деним рубежом 3 (лексический
        // стоп-лист, вето сохранено), не провенансом.
        $this->insertBankAnswer([
            'question_pattern' => 'где найти теплицу для земледелия',
            'answer_text'      => 'Теплица строится на базе быстрее.',
        ]);
        $message = $this->insertMessage(['addressed_to_bot' => 1, 'text' => 'Роби, где найти теплицу для земледелия?']);

        $calls   = [];
        $sender  = $this->sender($calls);
        $handler = $this->handler($sender, $this->permissiveGuard());

        $handler->handle();

        $rows = $this->auditRows((int) $message['id'], 'COMMUNITY_ROUTE_LOGGED');
        $this->assertCount(1, $rows, 'маршрут отказа обязан быть сохранён и наблюдаем');

        $payload = json_decode((string) $rows[0]['payload'], true);
        $this->assertIsArray($payload);
        $this->assertContains($payload['route'] ?? null, CommunityVoice::REFUSAL_WITH_ROUTE, 'сохранённый маршрут обязан быть одной из утверждённых строк');
    }

    // ── story 46: дефект 1 — маршрут пишется на КАЖДУЮ строку склейки ────

    /**
     * Три дубликата одного вопроса, гвард денит — вся склейка обязана дать N=3
     * аудит-строки `COMMUNITY_ROUTE_LOGGED` (по одной на строку), а не одну на
     * строку-представителя. Именно этот дефект `CommunityController::guardDeniedCount()`
     * (`EXISTS` per-row) видит как «дубликаты выпали из метрики».
     */
    public function testGuardDenialLogsRouteForEveryDuplicateInGroupNotJustRepresentative(): void
    {
        // Story 71 (ADR-178 сняла вето с провенанса) — деним рубежом 3 (лексический
        // стоп-лист, вето сохранено), не провенансом.
        $this->insertBankAnswer([
            'question_pattern' => 'где найти теплицу для земледелия',
            'answer_text'      => 'Теплица строится на базе быстрее.',
        ]);

        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $m     = $this->insertMessage(['addressed_to_bot' => 1, 'text' => 'Роби, где найти теплицу для земледелия?']);
            $ids[] = (int) $m['id'];
        }

        $calls   = [];
        $sender  = $this->sender($calls);
        $handler = $this->handler($sender, $this->permissiveGuard());

        $handler->handle();

        // Story 55 — текст маршрута уходит РЕПЛАЕМ только представителю группы (та же
        // логика, что и у реакции 🤔 ниже): дубликаты получают статус и аудит-запись
        // маршрута для метрики, но не второе сообщение в чат за тот же вопрос.
        $sendCalls = array_values(array_filter($calls, static fn (array $c): bool => $c['method'] === 'sendMessage'));
        $this->assertCount(1, $sendCalls, 'ровно один реплай с текстом маршрута на всю склейку дублей, не по одному на строку');

        foreach ($ids as $id) {
            $this->assertSame('escalated', $this->statusOf($id), "строка {$id} склейки обязана стать escalated");
            $rows = $this->auditRows($id, 'COMMUNITY_ROUTE_LOGGED');
            $this->assertCount(1, $rows, "строка {$id} склейки обязана получить свою аудит-запись маршрута, не только представитель группы");
        }
    }

    // ── story 46: дефект 2 — сбой аудит-вставки не портит метрику ────────

    /**
     * Аудит-вставка `COMMUNITY_ROUTE_LOGGED` сломана (симуляция сбоя записи). Контракт
     * story: сбой необязательной аудит-записи не должен молча оставить строку `escalated`
     * без признака, по которому её опознаёт метрика — статус тоже обязан откатиться, а не
     * закоммититься сам по себе. Строка остаётся `'new'` и получает второй шанс на
     * следующем тике вместо того, чтобы навсегда потерять и статус, и признак одновременно.
     *
     * Story 55 — по той же причине текст маршрута игроку тоже НЕ уходит в этой попытке:
     * эскалация не закоммитилась, значит следующий тик денит по новой и текст ушёл бы
     * второй раз, если бы отправка не ждала успешного коммита.
     */
    public function testGuardDenialInsertFailureRollsBackStatusInsteadOfLosingMetricSignal(): void
    {
        // Story 71 (ADR-178 сняла вето с провенанса) — деним рубежом 3 (лексический
        // стоп-лист, вето сохранено), не провенансом.
        $this->insertBankAnswer([
            'question_pattern' => 'где найти теплицу для земледелия',
            'answer_text'      => 'Теплица строится на базе быстрее.',
        ]);
        $message = $this->insertMessage(['addressed_to_bot' => 1, 'text' => 'Роби, где найти теплицу для земледелия?']);

        $throwingAudit = new class () extends AdminAuditLogModel {
            public function insert($data = null, bool $returnID = true)
            {
                throw new RuntimeException('simulated audit insert failure');
            }
        };

        $calls   = [];
        $sender  = $this->sender($calls);
        $handler = new CommunityAutoReplyHandler(
            new CommunityMessageModel(),
            new CommunityAnswerModel(),
            $this->matcher(),
            $this->permissiveGuard(),
            $sender,
            $throwingAudit,
            null,
            static fn (string $key, mixed $default = null): mixed
                => array_key_exists($key, ['community.enabled' => true, 'community.autoreply.enabled' => true])
                    ? ['community.enabled' => true, 'community.autoreply.enabled' => true][$key]
                    : $default,
            null,
            static function (): void {
                // no-op — реальный Telegram-объект тестам не нужен и не безопасен на CI
                // (story 52/53, `feedback_taskhandler_telegram_init_in_tests`).
            },
        );

        $handler->handle();

        $this->assertNotContains('sendMessage', array_column($calls, 'method'));
        $this->assertSame('new', $this->statusOf((int) $message['id']), 'сбой аудит-вставки обязан откатить и статус — иначе escalated остаётся без признака для метрики');
        $this->assertSame(0, $this->auditCount((int) $message['id'], 'COMMUNITY_ROUTE_LOGGED'));
    }

    // ── story 31: дефект 1 — заявка на склейку всё-или-ничего ────────────

    public function testClaimGroupIsAllOrNothingUnderConcurrentStatusChange(): void
    {
        $m1 = $this->insertMessage(['status' => 'new']);
        $m2 = $this->insertMessage(['status' => 'new']);

        // Конкурентное изменение: владелец руками из /admin/community (или cleanup)
        // уже увёл вторую строку склейки из 'new' между чтением матчера и заявкой.
        $this->conn->table('community_messages')->where('id', $m2['id'])->update(['status' => 'ignored']);

        $calls   = [];
        $handler = $this->handler($this->sender($calls));
        $claim   = new ReflectionMethod(CommunityAutoReplyHandler::class, 'claimGroup');
        $claim->setAccessible(true);

        $result = $claim->invoke($handler, [(int) $m1['id'], (int) $m2['id']], ['status' => 'answered']);

        $this->assertFalse($result, 'частичное совпадение обязано провалить заявку целиком');
        $this->assertSame(
            'new',
            $this->statusOf((int) $m1['id']),
            'до транзакции этот UPDATE уже переписал бы строку в answered до проверки affectedRows — '
                . 'откат обязан вернуть её обратно, чтобы ни одна строка не осталась перехваченной'
        );
        $this->assertSame('ignored', $this->statusOf((int) $m2['id']), 'конкурентно изменённая строка заявкой не трогается');
    }

    // ── story 40: дефект 1 — транзакция не стартовала → перехват не выполняется вовсе ─

    /**
     * `transEnabled` — публичное свойство `BaseConnection`, штатный переключатель для
     * симуляции «транзакция не стартовала» без мока соединения (тот же приём, каким
     * фреймворк сам глушит транзакции в `TestCase::mockCache()`-подобных сценариях).
     * На нефикшенной реализации возврат `transBegin()` игнорировался — `transRollback()`
     * ниже был бы no-op на неоткрытой транзакции, и `UPDATE` остался бы закоммиченным.
     */
    public function testClaimGroupClaimsNothingWhenTransactionCannotStart(): void
    {
        $m1 = $this->insertMessage(['status' => 'new']);
        $m2 = $this->insertMessage(['status' => 'new']);

        $calls   = [];
        $handler = $this->handler($this->sender($calls));
        $claim   = new ReflectionMethod(CommunityAutoReplyHandler::class, 'claimGroup');
        $claim->setAccessible(true);

        $db                    = Database::connect();
        $originalTransEnabled  = $db->transEnabled;
        $db->transEnabled      = false;

        try {
            $result = $claim->invoke($handler, [(int) $m1['id'], (int) $m2['id']], ['status' => 'answered']);
        } finally {
            $db->transEnabled = $originalTransEnabled;
        }

        $this->assertFalse($result, 'транзакция не стартовала — заявка обязана отказать закрыто');
        $this->assertSame('new', $this->statusOf((int) $m1['id']), 'без открытой транзакции UPDATE не должен был выполниться вовсе');
        $this->assertSame('new', $this->statusOf((int) $m2['id']), 'без открытой транзакции UPDATE не должен был выполниться вовсе');
    }

    // ── story 31: дефект 2 — silent_topic терминален, не ретраится вечно ─

    public function testSilentTopicGivesOneLogEntryNotOnePerTick(): void
    {
        $this->insertBankAnswer([
            'question_pattern' => 'где найти теплицу для земледелия',
            'answer_text'      => 'Теплица строится на базе.',
        ]);
        $message = $this->insertMessage(['addressed_to_bot' => 1, 'text' => 'Роби, где найти теплицу для земледелия?']);

        $calls   = [];
        $sender  = $this->sender($calls, ['community.autoreply.silent_topics' => (string) $message['message_thread_id']]);
        $handler = $this->handler($sender);

        for ($i = 0; $i < 5; $i++) {
            $handler->handle();
        }

        $this->assertSame([], $calls, 'silent_topic отказывает ДО sendMessage — транспорт не звонит вовсе');
        // Story 40, дефект 3 — заглушённый топик не копится у владельца как требующий
        // ручного ответа: 'escalated' питает очередь `/admin/community`
        // (`whereIn('status', ['new', 'escalated'])`), 'ignored' — нет.
        $this->assertSame('ignored', $this->statusOf((int) $message['id']), 'намеренная тишина топика — терминальный отказ, но не очередь владельца');
        $this->assertSame(
            1,
            $this->auditCount((int) $message['id'], 'COMMUNITY_ANSWER_REJECTED'),
            'пять тиков подряд на постоянной конфигурации не должны дать пять записей в журнале'
        );
    }

    // ── story 31: дефект 3 — причина отказа связана с ЭТОЙ попыткой ──────

    public function testStaleAuditFromPreviousAttemptDoesNotTriggerRetryOnSwallowedInsert(): void
    {
        // claimGroup() этой попытки уже забрал статус в 'answered'.
        $message = $this->insertMessage(['status' => 'answered']);

        // Устаревшая строка ПРОШЛОЙ попытки — записана заведомо раньше текущей.
        $this->conn->table('admin_audit_log')->insert([
            'admin_user_id' => 0,
            'action'        => 'COMMUNITY_ANSWER_REJECTED',
            'target_type'   => 'community_message',
            'target_id'     => $message['id'],
            'payload'       => json_encode(['reason' => 'topic_rate_limit'], JSON_UNESCAPED_UNICODE),
            'created_at'    => date('Y-m-d H:i:s', strtotime('-1 hour')),
        ]);

        $calls   = [];
        $handler = $this->handler($this->sender($calls));
        $resolve = new ReflectionMethod(CommunityAutoReplyHandler::class, 'resolveFailure');
        $resolve->setAccessible(true);

        // Водораздел «начало текущей попытки» (story 51 — auto-increment `id`, а не
        // часы БД) снят ПОСЛЕ устаревшей строки — своя вставка аудита этой попытки
        // проглочена (Throwable в CommunityChatSender::audit()), новой строки после
        // этого `id` нет.
        $attemptWatermarkId = $this->auditWatermarkId();

        $resolve->invoke($handler, (int) $message['id'], [(int) $message['id']], 'answered', $attemptWatermarkId);

        $this->assertSame(
            'answered',
            $this->statusOf((int) $message['id']),
            'устаревшая строка прошлой попытки не должна читаться как причина ЭТОГО отказа — без строки '
                . 'после attemptWatermarkId причина не читается, ретрая не будет'
        );
    }

    // ── story 51 — водораздел не зависит от секундной гранулярности часов БД

    public function testStaleAuditInSameSecondAsAttemptWatermarkDoesNotTriggerRetry(): void
    {
        // claimGroup() этой попытки уже забрал статус в 'answered'.
        $message = $this->insertMessage(['status' => 'answered']);

        $now = (string) $this->conn->query('SELECT NOW() AS n')->getRowArray()['n'];

        // Устаревшая строка ПРОШЛОЙ попытки — `created_at` совпадает секунда-в-секунду
        // с моментом старта ЭТОЙ попытки. Именно эта гонка валила CI (дефект story 51):
        // при секундной гранулярности часов БД `created_at >= $since` не отличает такую
        // строку от строки текущей попытки. Тест форсирует коллизию через явный
        // `created_at`, не через `sleep`/скорость машины — воспроизводится детерминированно.
        $this->conn->table('admin_audit_log')->insert([
            'admin_user_id' => 0,
            'action'        => 'COMMUNITY_ANSWER_REJECTED',
            'target_type'   => 'community_message',
            'target_id'     => $message['id'],
            'payload'       => json_encode(['reason' => 'topic_rate_limit'], JSON_UNESCAPED_UNICODE),
            'created_at'    => $now,
        ]);

        $calls   = [];
        $handler = $this->handler($this->sender($calls));
        $resolve = new ReflectionMethod(CommunityAutoReplyHandler::class, 'resolveFailure');
        $resolve->setAccessible(true);

        // Водораздел снимается ПОСЛЕ вставки устаревшей строки (как в проде — прямо
        // перед сетевым вызовом): граница проходит по auto-increment `id`, той же
        // секунды `created_at` строке не хватает.
        $attemptWatermarkId = $this->auditWatermarkId();

        $resolve->invoke($handler, (int) $message['id'], [(int) $message['id']], 'answered', $attemptWatermarkId);

        $this->assertSame(
            'answered',
            $this->statusOf((int) $message['id']),
            'устаревшая строка с created_at в ту же секунду, что и водораздел попытки, не должна '
                . 'читаться как причина ЭТОГО отказа — привязка по auto-increment id, не по секундам'
        );
    }

    // ── story 31: дефект 4 — откат не затирает чужое конкурентное изменение

    public function testFailureRollbackDoesNotOverwriteStatusChangedByAnotherPass(): void
    {
        // claimGroup() этой попытки уже забрал статус в 'answered'.
        $message = $this->insertMessage(['status' => 'answered']);

        $attemptWatermarkId = $this->auditWatermarkId();

        // Между заявкой и обработкой отказа кто-то другой (владелец/cleanup) уже
        // сдвинул строку на свой статус.
        $this->conn->table('community_messages')->where('id', $message['id'])->update(['status' => 'ignored']);

        $this->conn->table('admin_audit_log')->insert([
            'admin_user_id' => 0,
            'action'        => 'COMMUNITY_ANSWER_FAILED',
            'target_type'   => 'community_message',
            'target_id'     => $message['id'],
            'payload'       => json_encode(['reason' => 'telegram_not_ok: boom'], JSON_UNESCAPED_UNICODE),
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        $calls   = [];
        $handler = $this->handler($this->sender($calls));
        $resolve = new ReflectionMethod(CommunityAutoReplyHandler::class, 'resolveFailure');
        $resolve->setAccessible(true);
        $resolve->invoke($handler, (int) $message['id'], [(int) $message['id']], 'answered', $attemptWatermarkId);

        $this->assertSame(
            'ignored',
            $this->statusOf((int) $message['id']),
            'откат в new не должен затирать статус, выставленный не этой попыткой'
        );
    }

    // ── story 31: дефект 5 — записи времени этого файла идут часами БД ───

    /**
     * Story 40, дефект 2 — «между двумя `NOW()`» само по себе не может покраснеть на
     * дефекте «PHP `date()` вместо часов БД»: на машине с совпадающими часами и таймзоной
     * PHP `date()` тоже укладывается в это же окно. Тест форсирует PHP-часы на 12 часов
     * позади реального UTC (`Etc/GMT+12`), не трогая MySQL — тот же приём, что тесты
     * story 27 (`CommunityChatSenderTest::testHourlyCeilingIsAccurateWhenAppAndDbClocksDiverge`,
     * memory `feedback_db_clock_seed_not_php_in_time_window_tests`). На реализации через
     * PHP `date()` запись уходит с меткой на ~12 часов «в прошлом» относительно MySQL
     * `NOW()`, и `$before`/`$after` (тоже из MySQL) её окно не накрывают — тест краснеет.
     */
    public function testRouteLogTimestampUsesDatabaseClockNotPhpDate(): void
    {
        // Story 71 (ADR-178 сняла вето с провенанса) — деним рубежом 3 (лексический
        // стоп-лист, вето сохранено), не провенансом.
        $this->insertBankAnswer([
            'question_pattern' => 'где найти теплицу для земледелия',
            'answer_text'      => 'Теплица строится на базе быстрее.',
        ]);
        $message = $this->insertMessage(['addressed_to_bot' => 1, 'text' => 'Роби, где найти теплицу для земледелия?']);

        $originalTz = date_default_timezone_get();
        date_default_timezone_set('Etc/GMT+12');

        try {
            $before = (string) $this->conn->query('SELECT NOW() AS n')->getRowArray()['n'];

            $calls   = [];
            $sender  = $this->sender($calls);
            $handler = $this->handler($sender, $this->permissiveGuard());
            $handler->handle();

            $after = (string) $this->conn->query('SELECT NOW() AS n')->getRowArray()['n'];

            $rows = $this->auditRows((int) $message['id'], 'COMMUNITY_ROUTE_LOGGED');
            $this->assertCount(1, $rows);
            $createdAt = (string) $rows[0]['created_at'];

            $this->assertGreaterThanOrEqual($before, $createdAt, 'created_at обязан идти часами БД (NOW()), не PHP date()');
            $this->assertLessThanOrEqual($after, $createdAt, 'created_at обязан идти часами БД (NOW()), не PHP date()');
        } finally {
            date_default_timezone_set($originalTz);
        }
    }
}
