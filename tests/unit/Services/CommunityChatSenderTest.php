<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Database\Migrations\Adr176CreateCommunityMessagesTable;
use App\Database\Migrations\CreateAdminAuditLogTable;
use App\Models\AdminAuditLogModel;
use App\Models\CommunityMessageModel;
use App\Services\Community\CommunityChatSender;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Longman\TelegramBot\Entities\ServerResponse;

/**
 * community-chat-bot-06 — `CommunityChatSender`, единственная точка отправки бота
 * в групповой чат.
 *
 * Схема строится прогоном реальных миграций на группу `tests` (Forge) — паттерн
 * `CommunityCleanupTest`/`CommunityExportTest` (story community-chat-bot-36): изолированная
 * ручная `CREATE TABLE` разошлась с продовой миграцией и давала зелёный тест на схеме,
 * которой на проде нет. `Request::send()` в реальный Bot API здесь не звонит ни разу:
 * каждый тест инжектирует свой `$transport` (сеам сервиса), поэтому `PHPUNIT_TESTSUITE`
 * фейк-режим Longman тут не задействован вовсе.
 *
 * @internal
 */
final class CommunityChatSenderTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = ['community_messages', 'admin_audit_log'];

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

    /**
     * story community-chat-bot-36: схема из миграции несёт UNIQUE(chat_id, message_id)
     * (идемпотентность повторной доставки Telegram-апдейта, продовый инвариант — в
     * ручной изолированной схеме этого теста его не было, и несколько сообщений одного
     * чата с одним и тем же дефолтным `message_id` молча проходили). Реальный Telegram
     * никогда не шлёт два разных апдейта с одним `message_id` в одном чате — дефолт
     * обязан быть уникальным на каждый вызов, как в остальных тестах подсистемы
     * (`CommunityAutoReplyHandlerTest::insertMessage()`).
     *
     * @param array<string, mixed> $overrides
     */
    private function insertMessage(array $overrides = []): int
    {
        static $seq = 0;
        ++$seq;

        $row = array_merge([
            'chat_id'              => -1001111111111,
            'message_thread_id'    => 55,
            'message_id'           => 900 + $seq,
            'reply_to_message_id'  => null,
            'telegram_user_id'     => 4242,
            'username'             => 'tester',
            'text'                 => 'как ваще крафтить',
            'sent_at'              => date('Y-m-d H:i:s'),
            'is_question'          => 1,
            'addressed_to_bot'     => 1,
            'status'               => 'new',
            'created_at'           => date('Y-m-d H:i:s'),
        ], $overrides);

        $this->conn->table('community_messages')->insert($row);
        return (int) $this->conn->insertID();
    }

    /**
     * Прошлая успешная отправка — для потолка/кулдауна. Время сеется часами БД
     * (`NOW() - INTERVAL ? SECOND`), не PHP `date()`: гейт читает окно через
     * MySQL `NOW()`, и семя обязано идти тем же источником времени — иначе тест
     * зависит от совпадения таймзон окружения запуска и БД (память
     * `feedback_db_clock_seed_not_php_in_time_window_tests`, story -27).
     */
    private function seedPastSend(int $targetMessageRowId, string $action = 'COMMUNITY_ANSWER_SENT', int $secondsAgo = 0): void
    {
        $this->conn->query(
            'INSERT INTO admin_audit_log (admin_user_id, action, target_type, target_id, payload, created_at)
             VALUES (0, ?, \'community_message\', ?, NULL, NOW() - INTERVAL ? SECOND)',
            [$action, $targetMessageRowId, $secondsAgo]
        );
    }

    private function lastAuditReason(int $targetMessageRowId): ?string
    {
        $row = $this->conn->table('admin_audit_log')
            ->where('target_id', $targetMessageRowId)
            ->orderBy('id', 'DESC')
            ->get(1)
            ->getRowArray();
        if ($row === null || ! isset($row['payload']) || ! is_string($row['payload'])) {
            return null;
        }
        $decoded = json_decode($row['payload'], true);
        return is_array($decoded) && isset($decoded['reason']) && is_string($decoded['reason']) ? $decoded['reason'] : null;
    }

    private function lastAuditAction(int $targetMessageRowId): ?string
    {
        $row = $this->conn->table('admin_audit_log')
            ->where('target_id', $targetMessageRowId)
            ->orderBy('id', 'DESC')
            ->get(1)
            ->getRowArray();
        return $row !== null && isset($row['action']) && is_string($row['action']) ? $row['action'] : null;
    }

    /**
     * `GameSettingsService` объявлен `final` — подменить его подклассом нельзя (как и
     * саму `CommunityChatSender.php`, docblock конструктора). Поэтому тестовые сценарии
     * настроек идут через инжектируемый `$settingsGetter`-callable, не через сервис.
     *
     * @param array<string, mixed> $overrides
     * @return callable(string, mixed): mixed
     */
    private function settingsWith(array $overrides): callable
    {
        return static fn (string $key, mixed $default = null): mixed
            => array_key_exists($key, $overrides) ? $overrides[$key] : $default;
    }

    /** Гейты открыты по умолчанию — переопределяй в тесте только то, что проверяешь. */
    private function openGates(): array
    {
        return [
            'community.enabled'                                  => true,
            'community.autoreply.enabled'                        => true,
            'community.autoreply.silent_topics'                  => '',
            'community.autoreply.max_per_hour_per_topic'          => 5,
            'community.autoreply.author_cooldown_seconds'         => 0,
            'community.autoreply.max_answer_chars'                => 600,
        ];
    }

    private function sender(array $settingsOverrides, callable $transport): CommunityChatSender
    {
        return new CommunityChatSender(
            new CommunityMessageModel(),
            null,
            new AdminAuditLogModel(),
            $this->conn,
            $transport,
            $this->settingsWith(array_merge($this->openGates(), $settingsOverrides))
        );
    }

    private function okResponse(): ServerResponse
    {
        return new ServerResponse(['ok' => true, 'result' => ['message_id' => 1]], 'testbot');
    }

    private function notOkResponse(string $description = 'Bad Request: test failure'): ServerResponse
    {
        return new ServerResponse(['ok' => false, 'error_code' => 400, 'description' => $description], 'testbot');
    }

    // ── sendAnswer: happy path ──────────────────────────────────────────

    public function testAnswerGoesToSameTopicAsReply(): void
    {
        $rowId = $this->insertMessage(['chat_id' => -100777, 'message_thread_id' => 12, 'message_id' => 501]);

        $calls  = [];
        $sender = $this->sender([], function (string $method, array $data) use (&$calls): ServerResponse {
            $calls[] = ['method' => $method, 'data' => $data];
            return $this->okResponse();
        });

        $result = $sender->sendAnswer($rowId, 'В теплице на базе.');

        $this->assertTrue($result);
        $this->assertCount(1, $calls);
        $this->assertSame('sendMessage', $calls[0]['method']);
        $this->assertSame(-100777, $calls[0]['data']['chat_id']);
        $this->assertSame(12, $calls[0]['data']['message_thread_id']);
        $this->assertSame(501, $calls[0]['data']['reply_to_message_id']);
        $this->assertSame('COMMUNITY_ANSWER_SENT', $this->lastAuditAction($rowId));
    }

    // ── autoreply.enabled=false ──────────────────────────────────────────

    public function testNothingSentWhenAutoreplyDisabled(): void
    {
        $rowId = $this->insertMessage();

        $called = false;
        $sender = $this->sender(
            ['community.autoreply.enabled' => false],
            function () use (&$called): ServerResponse {
                $called = true;
                return $this->okResponse();
            }
        );

        $result = $sender->sendAnswer($rowId, 'Ответ.');

        $this->assertFalse($result);
        $this->assertFalse($called);
        $this->assertSame('autoreply_disabled', $this->lastAuditReason($rowId));
    }

    // ── silent_topics ────────────────────────────────────────────────────

    public function testSilentTopicBlocksRegardlessOfOtherConditions(): void
    {
        $rowId = $this->insertMessage(['message_thread_id' => 77]);

        $called = false;
        $sender = $this->sender(
            ['community.autoreply.silent_topics' => '10,77,99'],
            function () use (&$called): ServerResponse {
                $called = true;
                return $this->okResponse();
            }
        );

        $result = $sender->sendAnswer($rowId, 'Ответ.');

        $this->assertFalse($result);
        $this->assertFalse($called);
        $this->assertSame('silent_topic', $this->lastAuditReason($rowId));
    }

    // ── потолок: 5/час — 6, 7, 8 не уходят, все три ────────────────────

    public function testHourlyCeilingSilencesCompletelyNotEveryOther(): void
    {
        $chatId   = -100999;
        $threadId = 30;

        // Пять прошлых успешных ответов в этом топике за последний час.
        for ($i = 0; $i < 5; $i++) {
            $priorRowId = $this->insertMessage([
                'chat_id' => $chatId, 'message_thread_id' => $threadId, 'telegram_user_id' => 1000 + $i,
            ]);
            $this->seedPastSend($priorRowId);
        }

        $sender = $this->sender([], function (): ServerResponse {
            $this->fail('шестой/седьмой/восьмой ответ не должен доходить до транспорта');
        });

        // Шестой, седьмой, восьмой — все три должны молчать, не через раз.
        foreach ([6, 7, 8] as $n) {
            $rowId = $this->insertMessage([
                'chat_id' => $chatId, 'message_thread_id' => $threadId, 'telegram_user_id' => 5000 + $n,
            ]);
            $result = $sender->sendAnswer($rowId, "Ответ номер {$n}.");
            $this->assertFalse($result, "ответ #{$n} за час обязан молчать при потолке 5");
            $this->assertSame('topic_rate_limit', $this->lastAuditReason($rowId));
        }
    }

    // ── кулдаун автора ───────────────────────────────────────────────────

    public function testSecondAnswerToSameAuthorWithinCooldownBlocked(): void
    {
        $authorId = 8181;

        $firstRowId = $this->insertMessage(['telegram_user_id' => $authorId]);
        $this->seedPastSend($firstRowId);

        $called = false;
        $sender = $this->sender(
            ['community.autoreply.author_cooldown_seconds' => 600],
            function () use (&$called): ServerResponse {
                $called = true;
                return $this->okResponse();
            }
        );

        $secondRowId = $this->insertMessage(['telegram_user_id' => $authorId]);
        $result       = $sender->sendAnswer($secondRowId, 'Ответ.');

        $this->assertFalse($result);
        $this->assertFalse($called);
        $this->assertSame('author_cooldown', $this->lastAuditReason($secondRowId));
    }

    // ── расхождение часов приложения и БД (story community-chat-bot-27) ────

    /**
     * Отметка времени записи и чтение окна обязаны идти из одного источника (память
     * `feedback_db_clock_seed_not_php_in_time_window_tests`). Тест форсирует PHP-часы
     * на 12 часов позади реального UTC (`Etc/GMT+12`), не трогая MySQL, — так
     * воспроизводится расхождение таймзон приложения и БД без остановки времени.
     * На старой реализации (`date('Y-m-d H:i:s')` в `audit()`) запись уходит с меткой
     * на много часов "в прошлом" относительно MySQL `NOW()`, окно
     * `NOW() - INTERVAL 1 HOUR` её не видит, и потолок не срабатывает — тест краснеет.
     * После фикса запись идёт часами MySQL, окно видит её, потолок срабатывает как
     * обычно.
     */
    public function testHourlyCeilingIsAccurateWhenAppAndDbClocksDiverge(): void
    {
        $chatId   = -100321;
        $threadId = 33;

        $originalTz = date_default_timezone_get();
        date_default_timezone_set('Etc/GMT+12');

        try {
            $sender = $this->sender([], fn (): ServerResponse => $this->okResponse());

            // Пять успешных ответов "прямо сейчас" (по PHP-часам это на 12 часов
            // раньше, чем видит MySQL) — счётчик обязан их увидеть в окне часа.
            for ($i = 0; $i < 5; $i++) {
                $rowId = $this->insertMessage([
                    'chat_id' => $chatId, 'message_thread_id' => $threadId, 'telegram_user_id' => 7000 + $i,
                ]);
                $this->assertTrue($sender->sendAnswer($rowId, "Ответ {$i}."));
            }

            $blockedSender = $this->sender([], function (): ServerResponse {
                $this->fail('шестой ответ обязан молчать даже при расхождении часов приложения и БД');
            });

            $sixthRowId = $this->insertMessage([
                'chat_id' => $chatId, 'message_thread_id' => $threadId, 'telegram_user_id' => 7999,
            ]);
            $result = $blockedSender->sendAnswer($sixthRowId, 'Шестой ответ.');

            $this->assertFalse($result, 'потолок обязан сработать по единому источнику времени, а не PHP-часам');
            $this->assertSame('topic_rate_limit', $this->lastAuditReason($sixthRowId));
        } finally {
            date_default_timezone_set($originalTz);
        }
    }

    /** Тот же сценарий, что и потолок в час, но для кулдауна автора. */
    public function testAuthorCooldownIsAccurateWhenAppAndDbClocksDiverge(): void
    {
        $authorId = 8383;

        $originalTz = date_default_timezone_get();
        date_default_timezone_set('Etc/GMT+12');

        try {
            $sender = $this->sender(
                ['community.autoreply.author_cooldown_seconds' => 600],
                fn (): ServerResponse => $this->okResponse()
            );

            $firstRowId = $this->insertMessage(['telegram_user_id' => $authorId]);
            $this->assertTrue($sender->sendAnswer($firstRowId, 'Первый ответ.'));

            $blockedSender = $this->sender(
                ['community.autoreply.author_cooldown_seconds' => 600],
                function (): ServerResponse {
                    $this->fail('второй ответ автору в кулдауне обязан молчать даже при расхождении часов');
                }
            );

            $secondRowId = $this->insertMessage(['telegram_user_id' => $authorId]);
            $result       = $blockedSender->sendAnswer($secondRowId, 'Второй ответ.');

            $this->assertFalse($result, 'кулдаун обязан сработать по единому источнику времени, а не PHP-часам');
            $this->assertSame('author_cooldown', $this->lastAuditReason($secondRowId));
        } finally {
            date_default_timezone_set($originalTz);
        }
    }

    // ── длина текста ─────────────────────────────────────────────────────

    public function testTooLongTextIsRejectedNotTruncated(): void
    {
        $rowId = $this->insertMessage();

        $called = false;
        $sender = $this->sender(
            ['community.autoreply.max_answer_chars' => 10],
            function () use (&$called): ServerResponse {
                $called = true;
                return $this->okResponse();
            }
        );

        $result = $sender->sendAnswer($rowId, str_repeat('а', 20));

        $this->assertFalse($result);
        $this->assertFalse($called);
        $this->assertSame('text_too_long', $this->lastAuditReason($rowId));
    }

    // ── непарный `*` ─────────────────────────────────────────────────────

    public function testUnbalancedAsteriskRejectedBeforeApiCall(): void
    {
        $rowId = $this->insertMessage();

        $called = false;
        $sender = $this->sender([], function () use (&$called): ServerResponse {
            $called = true;
            return $this->okResponse();
        });

        $result = $sender->sendAnswer($rowId, 'Тут *непарная звёздочка без пары.');

        $this->assertFalse($result);
        $this->assertFalse($called);
        $this->assertSame('unbalanced_markdown', $this->lastAuditReason($rowId));
    }

    // ── story 58: полная парность легаси-Markdown, не только `*` ────────

    public function testUnbalancedUnderscoreRejectedBeforeApiCall(): void
    {
        $rowId = $this->insertMessage();

        $called = false;
        $sender = $this->sender([], function () use (&$called): ServerResponse {
            $called = true;
            return $this->okResponse();
        });

        $result = $sender->sendAnswer($rowId, 'слаг с непарным подчёркиванием слаг_вот');

        $this->assertFalse($result);
        $this->assertFalse($called);
        $this->assertSame('unbalanced_markdown', $this->lastAuditReason($rowId));
    }

    public function testUnbalancedBacktickRejectedBeforeApiCall(): void
    {
        $rowId = $this->insertMessage();

        $called = false;
        $sender = $this->sender([], function () use (&$called): ServerResponse {
            $called = true;
            return $this->okResponse();
        });

        $result = $sender->sendAnswer($rowId, 'Тут `непарный апостроф без пары.');

        $this->assertFalse($result);
        $this->assertFalse($called);
        $this->assertSame('unbalanced_markdown', $this->lastAuditReason($rowId));
    }

    public function testUnbalancedBracketRejectedBeforeApiCall(): void
    {
        $rowId = $this->insertMessage();

        $called = false;
        $sender = $this->sender([], function () use (&$called): ServerResponse {
            $called = true;
            return $this->okResponse();
        });

        $result = $sender->sendAnswer($rowId, 'Тут [непарная скобка без пары.');

        $this->assertFalse($result);
        $this->assertFalse($called);
        $this->assertSame('unbalanced_markdown', $this->lastAuditReason($rowId));
    }

    // ── story 58, дефект 1: ok=false по содержимому — терминален, не ретраится ──

    /**
     * Ключевой тест на «вечный ретрай» (acceptance criterion 5): без фикса Telegram
     * `can't parse entities` писался бы как обычный `_FAILED`/`telegram_not_ok`, и
     * этот тест краснел бы на обоих утверждениях ниже — вызывающий (`CommunityAutoReplyHandler`)
     * ретраил бы такую склейку каждую минуту вечно.
     */
    public function testContentParseErrorFromTelegramIsRejectedNotFailed(): void
    {
        $rowId = $this->insertMessage();

        $sender = $this->sender([], function (): ServerResponse {
            return $this->notOkResponse("Bad Request: can't parse entities: Character '_' is reserved and must be escaped");
        });

        $result = $sender->sendAnswer($rowId, 'Текст, который наш парный чек не поймал.');

        $this->assertFalse($result);
        $this->assertSame('COMMUNITY_ANSWER_REJECTED', $this->lastAuditAction($rowId));
        $this->assertSame('unbalanced_markdown', $this->lastAuditReason($rowId));
    }

    /** Транзиентный сетевой отказ (не про содержимое) — по-прежнему `_FAILED`, ретраится. */
    public function testNetworkFailureFromTelegramStaysFailedNotRejected(): void
    {
        $rowId = $this->insertMessage();

        $sender = $this->sender([], function (): ServerResponse {
            return $this->notOkResponse('Bad Request: message thread not found');
        });

        $result = $sender->sendAnswer($rowId, 'Ответ.');

        $this->assertFalse($result);
        $this->assertSame('COMMUNITY_ANSWER_FAILED', $this->lastAuditAction($rowId));
        $this->assertStringContainsString('telegram_not_ok', (string) $this->lastAuditReason($rowId));
    }

    // ── sendGuardRoute(): контракт для story 57/59 ───────────────────────

    public function testSendGuardRouteWritesRouteActionNotAnswer(): void
    {
        $rowId = $this->insertMessage(['chat_id' => -100444, 'message_thread_id' => 15, 'message_id' => 511]);

        $calls  = [];
        $sender = $this->sender([], function (string $method, array $data) use (&$calls): ServerResponse {
            $calls[] = ['method' => $method, 'data' => $data];
            return $this->okResponse();
        });

        $result = $sender->sendGuardRoute($rowId, 'Спроси иначе, пожалуйста.');

        $this->assertTrue($result);
        $this->assertCount(1, $calls);
        $this->assertSame('sendMessage', $calls[0]['method']);
        $this->assertSame(-100444, $calls[0]['data']['chat_id']);
        $this->assertSame(15, $calls[0]['data']['message_thread_id']);
        $this->assertSame(511, $calls[0]['data']['reply_to_message_id']);
        $this->assertSame('COMMUNITY_ROUTE_SENT', $this->lastAuditAction($rowId));
    }

    public function testSendGuardRouteRespectsHourlyCeilingLikeAnswer(): void
    {
        $chatId   = -100666;
        $threadId = 66;

        for ($i = 0; $i < 5; $i++) {
            $priorRowId = $this->insertMessage([
                'chat_id' => $chatId, 'message_thread_id' => $threadId, 'telegram_user_id' => 3000 + $i,
            ]);
            $this->seedPastSend($priorRowId);
        }

        $sender = $this->sender([], function (): ServerResponse {
            $this->fail('маршрут гварда обязан молчать при исчерпанном потолке топика, как и обычный ответ');
        });

        $rowId  = $this->insertMessage([
            'chat_id' => $chatId, 'message_thread_id' => $threadId, 'telegram_user_id' => 6666,
        ]);
        $result = $sender->sendGuardRoute($rowId, 'Спроси иначе.');

        $this->assertFalse($result);
        $this->assertSame('topic_rate_limit', $this->lastAuditReason($rowId));
    }

    public function testSendGuardRouteRejectedTextNotCountedAsAnswer(): void
    {
        $rowId = $this->insertMessage();

        $called = false;
        $sender = $this->sender([], function () use (&$called): ServerResponse {
            $called = true;
            return $this->okResponse();
        });

        $result = $sender->sendGuardRoute($rowId, 'Тут *непарная звёздочка без пары.');

        $this->assertFalse($result);
        $this->assertFalse($called);
        $this->assertSame('COMMUNITY_ROUTE_REJECTED', $this->lastAuditAction($rowId));
        $this->assertSame('unbalanced_markdown', $this->lastAuditReason($rowId));
    }

    /**
     * Ремонт по замечанию лида (2026-08-26): маршрут ОБЯЗАН и уважать потолок топика,
     * и пополнять его — иначе тот, кто целенаправленно выуживает читы, получает
     * гарантированный текстовый ответ на каждую пробу, потому что счётчик от его
     * собственных отказов никогда не растёт (вектор спама, запрещённый `## Non-goals`
     * story). Пять прошлых `COMMUNITY_ROUTE_SENT` (не `COMMUNITY_ANSWER_SENT`) обязаны
     * сами исчерпать потолок для следующего маршрута.
     */
    public function testGuardRouteSentCountsTowardHourlyCeilingForNextRoute(): void
    {
        $chatId   = -100777222;
        $threadId = 71;

        for ($i = 0; $i < 5; $i++) {
            $priorRowId = $this->insertMessage([
                'chat_id' => $chatId, 'message_thread_id' => $threadId, 'telegram_user_id' => 4000 + $i,
            ]);
            $this->seedPastSend($priorRowId, 'COMMUNITY_ROUTE_SENT');
        }

        $sender = $this->sender([], function (): ServerResponse {
            $this->fail('шестой маршрут за час обязан молчать — потолок пополняется маршрутами, не только ответами');
        });

        $rowId  = $this->insertMessage([
            'chat_id' => $chatId, 'message_thread_id' => $threadId, 'telegram_user_id' => 7771,
        ]);
        $result = $sender->sendGuardRoute($rowId, 'Спроси иначе.');

        $this->assertFalse($result);
        $this->assertSame('topic_rate_limit', $this->lastAuditReason($rowId));
    }

    /** Та же логика, что и потолок, но для кулдауна автора — тот же выуживающий автор. */
    public function testGuardRouteSentCountsTowardAuthorCooldown(): void
    {
        $authorId = 9191;

        $firstRowId = $this->insertMessage(['telegram_user_id' => $authorId]);
        $this->seedPastSend($firstRowId, 'COMMUNITY_ROUTE_SENT');

        $sender = $this->sender(
            ['community.autoreply.author_cooldown_seconds' => 600],
            function (): ServerResponse {
                $this->fail('маршрут в кулдауне автора обязан молчать — прошлый маршрут заводит кулдаун, как и ответ');
            }
        );

        $secondRowId = $this->insertMessage(['telegram_user_id' => $authorId]);
        $result       = $sender->sendGuardRoute($secondRowId, 'Спроси иначе.');

        $this->assertFalse($result);
        $this->assertSame('author_cooldown', $this->lastAuditReason($secondRowId));
    }

    // ── канон имени — «Робби» запрещён даже тут ─────────────────────────

    public function testTextContainingWrongSpellingOfNameRejected(): void
    {
        $rowId = $this->insertMessage();

        $called = false;
        $sender = $this->sender([], function () use (&$called): ServerResponse {
            $called = true;
            return $this->okResponse();
        });

        $result = $sender->sendAnswer($rowId, 'Я Робби, отвечаю за студию.');

        $this->assertFalse($result);
        $this->assertFalse($called);
        $this->assertSame('canon_name_violation', $this->lastAuditReason($rowId));
    }

    // ── react() ──────────────────────────────────────────────────────────

    public function testReactCallsReactionMethodNotSendMessage(): void
    {
        $rowId = $this->insertMessage(['chat_id' => -100333, 'message_id' => 71]);

        $calls  = [];
        $sender = $this->sender([], function (string $method, array $data) use (&$calls): ServerResponse {
            $calls[] = ['method' => $method, 'data' => $data];
            return $this->okResponse();
        });

        $result = $sender->react($rowId, '👀');

        $this->assertTrue($result);
        $this->assertCount(1, $calls);
        $this->assertNotSame('sendMessage', $calls[0]['method']);
        $this->assertSame(-100333, $calls[0]['data']['chat_id']);
        $this->assertSame(71, $calls[0]['data']['message_id']);
        $this->assertSame('COMMUNITY_REACTION_SENT', $this->lastAuditAction($rowId));
    }

    // ── реакции не расходуют и не блокируются гейтами ответов (ремонтный круг 1) ──

    public function testReactStillWorksAfterHourlyAnswerCeilingExhausted(): void
    {
        $chatId   = -100888;
        $threadId = 40;

        for ($i = 0; $i < 5; $i++) {
            $priorRowId = $this->insertMessage([
                'chat_id' => $chatId, 'message_thread_id' => $threadId, 'telegram_user_id' => 2000 + $i,
            ]);
            $this->seedPastSend($priorRowId, 'COMMUNITY_ANSWER_SENT');
        }

        $rowId = $this->insertMessage([
            'chat_id' => $chatId, 'message_thread_id' => $threadId, 'telegram_user_id' => 9001, 'message_id' => 71,
        ]);

        $calls  = [];
        $sender = $this->sender([], function (string $method, array $data) use (&$calls): ServerResponse {
            $calls[] = ['method' => $method, 'data' => $data];
            return $this->okResponse();
        });

        $result = $sender->react($rowId, '👀');

        $this->assertTrue($result, 'реакция обязана уходить, даже когда потолок ответов в топике исчерпан');
        $this->assertCount(1, $calls);
        $this->assertSame('COMMUNITY_REACTION_SENT', $this->lastAuditAction($rowId));
    }

    public function testReactStillWorksInsideAuthorCooldown(): void
    {
        $authorId = 8282;

        $firstRowId = $this->insertMessage(['telegram_user_id' => $authorId]);
        $this->seedPastSend($firstRowId, 'COMMUNITY_ANSWER_SENT');

        $secondRowId = $this->insertMessage(['telegram_user_id' => $authorId, 'message_id' => 72]);

        $calls  = [];
        $sender = $this->sender(
            ['community.autoreply.author_cooldown_seconds' => 600],
            function (string $method, array $data) use (&$calls): ServerResponse {
                $calls[] = ['method' => $method, 'data' => $data];
                return $this->okResponse();
            }
        );

        $result = $sender->react($secondRowId, '🤔');

        $this->assertTrue($result, 'реакция обязана уходить внутри кулдауна автора на ответы');
        $this->assertCount(1, $calls);
        $this->assertSame('COMMUNITY_REACTION_SENT', $this->lastAuditAction($secondRowId));
    }

    // ── Telegram ok=false не роняет вызывающего ─────────────────────────

    public function testTelegramNotOkDoesNotThrowAndIsAudited(): void
    {
        $rowId = $this->insertMessage();

        $sender = $this->sender([], function (): ServerResponse {
            return $this->notOkResponse('Bad Request: message thread not found');
        });

        $result = $sender->sendAnswer($rowId, 'Ответ.');

        $this->assertFalse($result);
        $this->assertSame('COMMUNITY_ANSWER_FAILED', $this->lastAuditAction($rowId));
        $this->assertStringContainsString('telegram_not_ok', (string) $this->lastAuditReason($rowId));
    }

    // ── sendManualAnswer(): владелец не немой при выключенном автоответе (story 18) ──

    public function testManualAnswerGoesThroughWhenAutoreplyDisabled(): void
    {
        $rowId = $this->insertMessage(['chat_id' => -100777, 'message_thread_id' => 12, 'message_id' => 501]);

        $calls  = [];
        $sender = $this->sender(
            ['community.autoreply.enabled' => false],
            function (string $method, array $data) use (&$calls): ServerResponse {
                $calls[] = ['method' => $method, 'data' => $data];
                return $this->okResponse();
            }
        );

        $result = $sender->sendManualAnswer($rowId, 'В теплице на базе.');

        $this->assertTrue($result, 'ручная отправка обязана проходить даже при выключенном автоответе — аварийный выход не должен отказывать в аварии');
        $this->assertCount(1, $calls);
        $this->assertSame('sendMessage', $calls[0]['method']);
        $this->assertSame('COMMUNITY_MANUAL_ANSWER_SENT', $this->lastAuditAction($rowId));
    }

    public function testAutomaticAnswerStillBlockedWhenAutoreplyDisabled(): void
    {
        $rowId = $this->insertMessage();

        $called = false;
        $sender = $this->sender(
            ['community.autoreply.enabled' => false],
            function () use (&$called): ServerResponse {
                $called = true;
                return $this->okResponse();
            }
        );

        $result = $sender->sendAnswer($rowId, 'Ответ.');

        $this->assertFalse($result, 'автоматический путь не должен получить ручную льготу');
        $this->assertFalse($called);
        $this->assertSame('autoreply_disabled', $this->lastAuditReason($rowId));
    }

    public function testManualAnswerStillBlockedWhenCommunityDisabled(): void
    {
        $rowId = $this->insertMessage();

        $called = false;
        $sender = $this->sender(
            ['community.enabled' => false],
            function () use (&$called): ServerResponse {
                $called = true;
                return $this->okResponse();
            }
        );

        $result = $sender->sendManualAnswer($rowId, 'Ответ.');

        $this->assertFalse($result, 'community.enabled=false обязан гасить и ручную отправку');
        $this->assertFalse($called);
        $this->assertSame('community_disabled', $this->lastAuditReason($rowId));
    }

    public function testManualAnswerBlockedBySilentTopic(): void
    {
        $rowId = $this->insertMessage(['message_thread_id' => 77]);

        $called = false;
        $sender = $this->sender(
            ['community.autoreply.silent_topics' => '10,77,99'],
            function () use (&$called): ServerResponse {
                $called = true;
                return $this->okResponse();
            }
        );

        $result = $sender->sendManualAnswer($rowId, 'Ответ.');

        $this->assertFalse($result, 'silent_topics обязан блокировать и ручную отправку');
        $this->assertFalse($called);
        $this->assertSame('silent_topic', $this->lastAuditReason($rowId));
    }

    public function testManualAnswerIgnoresHourlyCeiling(): void
    {
        $chatId   = -100999;
        $threadId = 30;

        for ($i = 0; $i < 5; $i++) {
            $priorRowId = $this->insertMessage([
                'chat_id' => $chatId, 'message_thread_id' => $threadId, 'telegram_user_id' => 1000 + $i,
            ]);
            $this->seedPastSend($priorRowId);
        }

        $calls  = [];
        $sender = $this->sender([], function (string $method, array $data) use (&$calls): ServerResponse {
            $calls[] = ['method' => $method, 'data' => $data];
            return $this->okResponse();
        });

        $rowId  = $this->insertMessage([
            'chat_id' => $chatId, 'message_thread_id' => $threadId, 'telegram_user_id' => 6001,
        ]);
        $result = $sender->sendManualAnswer($rowId, 'Ручной ответ поверх исчерпанного потолка.');

        $this->assertTrue($result, 'исчерпанный потолок в час не должен мешать живому владельцу');
        $this->assertCount(1, $calls);
    }

    public function testManualAnswerIgnoresAuthorCooldown(): void
    {
        $authorId = 8181;

        $firstRowId = $this->insertMessage(['telegram_user_id' => $authorId]);
        $this->seedPastSend($firstRowId);

        $calls  = [];
        $sender = $this->sender(
            ['community.autoreply.author_cooldown_seconds' => 600],
            function (string $method, array $data) use (&$calls): ServerResponse {
                $calls[] = ['method' => $method, 'data' => $data];
                return $this->okResponse();
            }
        );

        $secondRowId = $this->insertMessage(['telegram_user_id' => $authorId]);
        $result       = $sender->sendManualAnswer($secondRowId, 'Ответ.');

        $this->assertTrue($result, 'кулдаун автора не должен мешать живому владельцу');
        $this->assertCount(1, $calls);
    }

    public function testManualAnswerRejectsTooLongText(): void
    {
        $rowId = $this->insertMessage();

        $called = false;
        $sender = $this->sender(
            ['community.autoreply.max_answer_chars' => 10],
            function () use (&$called): ServerResponse {
                $called = true;
                return $this->okResponse();
            }
        );

        $result = $sender->sendManualAnswer($rowId, str_repeat('а', 20));

        $this->assertFalse($result);
        $this->assertFalse($called);
        $this->assertSame('text_too_long', $this->lastAuditReason($rowId));
    }

    public function testManualAnswerRejectsUnbalancedAsterisk(): void
    {
        $rowId = $this->insertMessage();

        $called = false;
        $sender = $this->sender([], function () use (&$called): ServerResponse {
            $called = true;
            return $this->okResponse();
        });

        $result = $sender->sendManualAnswer($rowId, 'Тут *непарная звёздочка без пары.');

        $this->assertFalse($result);
        $this->assertFalse($called);
        $this->assertSame('unbalanced_markdown', $this->lastAuditReason($rowId));
    }

    public function testManualAnswerRejectsWrongSpellingOfName(): void
    {
        $rowId = $this->insertMessage();

        $called = false;
        $sender = $this->sender([], function () use (&$called): ServerResponse {
            $called = true;
            return $this->okResponse();
        });

        $result = $sender->sendManualAnswer($rowId, 'Я Робби, отвечаю за студию.');

        $this->assertFalse($result);
        $this->assertFalse($called);
        $this->assertSame('canon_name_violation', $this->lastAuditReason($rowId));
    }

    public function testManualAndAutomaticAuditActionsAreDistinguishable(): void
    {
        $autoRowId = $this->insertMessage(['chat_id' => -100555, 'message_thread_id' => 21, 'message_id' => 601]);
        $sender    = $this->sender([], function (): ServerResponse {
            return $this->okResponse();
        });
        $this->assertTrue($sender->sendAnswer($autoRowId, 'Автоматический ответ.'));
        $this->assertSame('COMMUNITY_ANSWER_SENT', $this->lastAuditAction($autoRowId));

        $manualRowId = $this->insertMessage(['chat_id' => -100555, 'message_thread_id' => 21, 'message_id' => 602]);
        $this->assertTrue($sender->sendManualAnswer($manualRowId, 'Ручной ответ владельца.'));
        $this->assertSame(
            'COMMUNITY_MANUAL_ANSWER_SENT',
            $this->lastAuditAction($manualRowId),
            'метрика "бот против живых" не должна путать ручную отправку с автоматической'
        );
    }
}
