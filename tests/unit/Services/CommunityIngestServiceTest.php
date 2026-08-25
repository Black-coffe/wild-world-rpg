<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Database\Migrations\Adr176CreateCommunityMessagesTable;
use App\Models\CommunityMessageModel;
use App\Models\GameSettingsModel;
use App\Services\Community\CommunityAnswerMatcher;
use App\Services\Community\CommunityIngestService;
use App\Services\GameSettings\GameSettingsService;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * Story community-chat-bot-05 — `CommunityIngestService`: приём сообщения из настроенной
 * супергруппы в `community_messages`, детектор вопроса (НЕ по знаку «?»), обращение к
 * боту, анти-флуд по автору.
 *
 * Таблица создаётся прогоном реальной миграции `Adr176CreateCommunityMessagesTable` на
 * группу `tests` (Forge), а не `$migrate = true` — независимость от порядка/состояния
 * тестовой БД, как в `CommunitySettingsSeedTest`. `GameSettings` подменяются
 * анонимным двойником `GameSettingsModel` (паттерн `BuildDurationServiceTest`) —
 * реальная `game_settings` таблица в этом тесте не нужна.
 *
 * Ложное срабатывание детектора в живой болталке — самый быстрый способ сделать бота
 * посмешищем, поэтому негативные кейсы (не-вопросы, чужой разговор, флуд) держат
 * не меньше веса, чем позитивные.
 *
 * @internal
 */
final class CommunityIngestServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const CHAT_ID       = -1009999;
    private const BOT_USERNAME  = 'testbot';
    private const MAX_PER_HOUR  = 5;

    private bool $createdTable = false;

    protected function setUp(): void
    {
        parent::setUp();
        service('cache')->clean();

        $db = Database::connect('tests');
        if (! $db->tableExists('community_messages')) {
            $this->requireMigrationClass();
            $forge = Database::forge('tests');
            (new Adr176CreateCommunityMessagesTable($forge instanceof Forge ? $forge : null))->up();
            $this->createdTable = true;
        }
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        $db->table('community_messages')->truncate();

        if ($this->createdTable) {
            $this->requireMigrationClass();
            $forge = Database::forge('tests');
            (new Adr176CreateCommunityMessagesTable($forge instanceof Forge ? $forge : null))->down();
        }

        parent::tearDown();
    }

    /**
     * Файл миграции назван с датой-префиксом (конвенция CI4 MigrationLocator, а не
     * PSR-4), composer-автозагрузчик его не видит — требуется вручную, как в
     * `CommunitySettingsSeedTest`.
     */
    private function requireMigrationClass(): void
    {
        if (! class_exists(Adr176CreateCommunityMessagesTable::class, false)) {
            require_once APPPATH . 'Database/Migrations/2026-08-25-100000_Adr176CreateCommunityMessagesTable.php';
        }
    }

    /**
     * @param array<string, bool|int|string> $values
     */
    private function service(array $values, ?string $botUsername = self::BOT_USERNAME): CommunityIngestService
    {
        $defaults = [
            'community.enabled'                                    => true,
            'community.chat_id'                                    => (string) self::CHAT_ID,
            'community.ingest.max_questions_per_author_per_hour'   => self::MAX_PER_HOUR,
        ];

        $model = new class ($values + $defaults) extends GameSettingsModel {
            /** @param array<string, bool|int|string> $values */
            public function __construct(private array $values)
            {
            }

            public function findByKey(string $key): ?array
            {
                if (! array_key_exists($key, $this->values)) {
                    return null;
                }

                $value = $this->values[$key];
                if (is_bool($value)) {
                    return ['setting_key' => $key, 'value_type' => 'bool', 'value_bool' => $value ? 1 : 0];
                }
                if (is_int($value)) {
                    return ['setting_key' => $key, 'value_type' => 'int', 'value_int' => $value];
                }

                return ['setting_key' => $key, 'value_type' => 'string', 'value_string' => (string) $value];
            }
        };

        return new CommunityIngestService(new GameSettingsService($model), $botUsername);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function message(array $overrides = []): array
    {
        return array_merge([
            'message_id' => 100,
            'date'       => 1_700_000_000,
            'chat'       => ['id' => self::CHAT_ID, 'type' => 'supergroup'],
            'from'       => ['id' => 555, 'username' => 'igrok'],
            'text'       => 'привет чат',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function row(int $messageId = 100): ?array
    {
        return (new CommunityMessageModel())->where('chat_id', self::CHAT_ID)->where('message_id', $messageId)->first();
    }

    // -- запись + идемпотентность -------------------------------------------------

    public function testWritesFieldsFromSupergroupMessage(): void
    {
        $update = ['message' => $this->message([
            'message_thread_id'   => 42,
            'reply_to_message'    => ['message_id' => 88, 'from' => ['id' => 1, 'is_bot' => false]],
        ])];

        $this->service([])->handle($update);

        $row = $this->row();
        $this->assertNotNull($row);
        $this->assertSame(42, (int) $row['message_thread_id']);
        $this->assertSame(88, (int) $row['reply_to_message_id']);
        $this->assertSame(555, (int) $row['telegram_user_id']);
        $this->assertSame('igrok', $row['username']);
        $this->assertSame(date('Y-m-d H:i:s', 1_700_000_000), $row['sent_at']);
    }

    public function testRepeatedUpdateDoesNotCreateSecondRow(): void
    {
        $update = ['message' => $this->message()];
        $service = $this->service([]);

        $service->handle($update);
        $service->handle($update);

        $count = (new CommunityMessageModel())->where('chat_id', self::CHAT_ID)->where('message_id', 100)->countAllResults();
        $this->assertSame(1, $count);
    }

    // -- детектор вопроса -----------------------------------------------------------

    public function testQuestionWordWithoutQuestionMarkIsDetected(): void
    {
        $this->service([])->handle(['message' => $this->message(['text' => 'а как ваще крафтить'])]);

        $this->assertSame(1, (int) $this->row()['is_question']);
    }

    public function testInterjectionsWithQuestionMarkAreNotQuestions(): void
    {
        $this->service([])->handle(['message' => $this->message(['message_id' => 1, 'text' => 'серьёзно?'])]);
        $this->service([])->handle(['message' => $this->message(['message_id' => 2, 'text' => 'да ну?'])]);

        $this->assertSame(0, (int) $this->row(1)['is_question']);
        $this->assertSame(0, (int) $this->row(2)['is_question']);
    }

    public function testAddressToSpecificPersonIsNotAQuestion(): void
    {
        $this->service([])->handle(['message' => $this->message(['text' => 'Вась, а как ты качался?'])]);

        $this->assertSame(0, (int) $this->row()['is_question']);
    }

    /**
     * Ремонт (2026-08-25): «Роби, …» — обращение к боту, не к постороннему человеку.
     * До этого `addressesSpecificPerson()` отсекало его так же, как «Вась, …», и такие
     * вопросы не доходили до хендлера никогда (полоса A была мёртвой).
     */
    public function testAddressToBotByNameIsAQuestion(): void
    {
        $this->service([])->handle(['message' => $this->message(['text' => 'Роби, а где взять доски?'])]);

        $this->assertSame(1, (int) $this->row()['is_question']);
    }

    public function testCollectiveAddressToChatIsAQuestion(): void
    {
        $this->service([])->handle(['message' => $this->message(['message_id' => 1, 'text' => 'Народ, а где вода?'])]);
        $this->service([])->handle(['message' => $this->message(['message_id' => 2, 'text' => 'Ребят, как крафтить?'])]);

        $this->assertSame(1, (int) $this->row(1)['is_question']);
        $this->assertSame(1, (int) $this->row(2)['is_question']);
    }

    // -- обращение к боту -------------------------------------------------------------

    public function testMentionMarksAddressedToBot(): void
    {
        $text = '@testbot где вода';
        $update = ['message' => $this->message([
            'text'     => $text,
            'entities' => [['type' => 'mention', 'offset' => 0, 'length' => mb_strlen('@testbot', 'UTF-8')]],
        ])];

        $this->service([])->handle($update);

        $this->assertSame(1, (int) $this->row()['addressed_to_bot']);
    }

    /**
     * Ремонт (2026-08-25): Telegram считает `entities[].offset/length` в UTF-16 code
     * units, а эмодзи вне BMP (🔥) занимает 2 такие единицы против 1 символа PHP-строки —
     * срез по `mb_substr($text, $offset, $length)` уезжает и упоминание перестаёт
     * распознаваться. Детектор больше не смотрит на `offset`/`length` вообще, поэтому
     * этот кейс должен пройти независимо от того, что лежит (или не лежит) в `entities`.
     */
    public function testMentionAfterEmojiOutsideBmpStillMarksAddressedToBot(): void
    {
        $update = ['message' => $this->message([
            'text' => '🔥 @testbot где вода',
            // entities намеренно отсутствуют/пусты — распознавание не должно от них зависеть.
        ])];

        $this->service([])->handle($update);

        $this->assertSame(1, (int) $this->row()['addressed_to_bot']);
    }

    public function testMentionOfDifferentUsernameWithBotNameAsPrefixIsNotAddressed(): void
    {
        $update = ['message' => $this->message(['text' => '@testbot_fanclub привет всем'])];

        $this->service([])->handle($update);

        $this->assertSame(0, (int) $this->row()['addressed_to_bot']);
    }

    /**
     * Обращением к Роби считается реплай именно на его сообщение — сверяется
     * `reply_to_message.from.username` с настроенным `botUsername`.
     */
    public function testReplyToBotMarksAddressedToBot(): void
    {
        $update = ['message' => $this->message([
            'text'             => 'а это где смотреть',
            'reply_to_message' => ['message_id' => 5, 'from' => ['id' => 9, 'is_bot' => true, 'username' => self::BOT_USERNAME]],
        ])];

        $this->service([])->handle($update);

        $this->assertSame(1, (int) $this->row()['addressed_to_bot']);
    }

    /**
     * Story 45: реплай на сообщение СТОРОННЕГО бота (не Роби) — не обращение к боту.
     * До фикса `repliesToBot()` смотрел только на `is_bot === true` любого автора.
     */
    public function testReplyToOtherBotDoesNotMarkAddressedToBot(): void
    {
        $update = ['message' => $this->message([
            'text'             => 'а как получить X?',
            'reply_to_message' => ['message_id' => 5, 'from' => ['id' => 9, 'is_bot' => true, 'username' => 'moderator_bot']],
        ])];

        $this->service([])->handle($update);

        $this->assertSame(0, (int) $this->row()['addressed_to_bot']);
    }

    /**
     * Дефект ревью 2026-08-25 №1, сторона приёма — прямое обращение к боту без «?» и
     * без вопросительного слова сохраняется с `addressed_to_bot=1`. `is_question`
     * остаётся честной эвристикой (0) — эту колонку читает не только тик, но и очередь
     * `/admin/community` со счётчиками, смешивать в неё «обращено к боту» нельзя (иначе
     * «Роби, спасибо, всё понял» стало бы вопросом в очереди). Доведение такого
     * обращения до тика — выборка `is_question=1 OR addressed_to_bot=1` в story 57,
     * эта story её не трогает.
     */
    public function testDirectAddressWithoutQuestionMarkOrWordIsStoredButNotAQuestion(): void
    {
        $this->service([])->handle(['message' => $this->message(['message_id' => 1, 'text' => 'Роби, подскажи'])]);
        $this->service([])->handle(['message' => $this->message(['message_id' => 2, 'text' => '@testbot помоги'])]);

        foreach ([1, 2] as $id) {
            $row = $this->row($id);
            $this->assertSame(1, (int) $row['addressed_to_bot'], "message_id=$id должен быть addressed_to_bot");
            $this->assertSame(0, (int) $row['is_question'], "message_id=$id — не вопрос по эвристике");
        }
    }

    public function testRobiPrefixMarksAddressedToBot(): void
    {
        $this->service([])->handle(['message' => $this->message(['text' => 'Роби, а где взять доски'])]);

        $row = $this->row();
        $this->assertSame(1, (int) $row['addressed_to_bot']);
        $this->assertSame(1, (int) $row['is_question']);
    }

    public function testOrdinaryMessageIsNotAddressedToBot(): void
    {
        $this->service([])->handle(['message' => $this->message(['text' => 'го на разведку'])]);

        $this->assertSame(0, (int) $this->row()['addressed_to_bot']);
    }

    // -- анти-флуд --------------------------------------------------------------------

    public function testSixthQuestionFromSameAuthorInHourIsForcedToZero(): void
    {
        $service = $this->service([]);

        for ($i = 1; $i <= self::MAX_PER_HOUR; $i++) {
            $service->handle(['message' => $this->message([
                'message_id' => $i,
                'date'       => 1_700_000_000 + $i,
                'text'       => 'как крафтить предмет номер ' . $i,
            ])]);
        }
        $service->handle(['message' => $this->message([
            'message_id' => self::MAX_PER_HOUR + 1,
            'date'       => 1_700_000_000 + self::MAX_PER_HOUR + 1,
            'text'       => 'как крафтить ещё один предмет',
        ])]);

        for ($i = 1; $i <= self::MAX_PER_HOUR; $i++) {
            $this->assertSame(1, (int) $this->row($i)['is_question'], "вопрос #$i должен пройти как вопрос");
        }
        $this->assertSame(0, (int) $this->row(self::MAX_PER_HOUR + 1)['is_question'], 'шестой вопрос за час — мимо квоты');
    }

    /**
     * Дефект ревью 2026-08-25 №1 — квота гасит подслушанное, но не прямое обращение
     * к боту: шестой ЗА ЧАС вопрос того же автора, адресованный боту, обязан пройти,
     * а такой же по счёту, но не адресованный — по-прежнему гасится (регрессия
     * `testSixthQuestionFromSameAuthorInHourIsForcedToZero` не должна перестать падать).
     */
    public function testAuthorQuotaDoesNotZeroAddressedToBotQuestion(): void
    {
        $service = $this->service([]);

        for ($i = 1; $i <= self::MAX_PER_HOUR; $i++) {
            $service->handle(['message' => $this->message([
                'message_id' => $i,
                'date'       => 1_700_000_000 + $i,
                'text'       => 'как крафтить предмет номер ' . $i,
            ])]);
        }

        $service->handle(['message' => $this->message([
            'message_id' => self::MAX_PER_HOUR + 1,
            'date'       => 1_700_000_000 + self::MAX_PER_HOUR + 1,
            'text'       => 'Роби, как крафтить ещё один предмет',
        ])]);
        $service->handle(['message' => $this->message([
            'message_id' => self::MAX_PER_HOUR + 2,
            'date'       => 1_700_000_000 + self::MAX_PER_HOUR + 2,
            'text'       => 'как крафтить ещё ещё один предмет',
        ])]);

        $this->assertSame(
            1,
            (int) $this->row(self::MAX_PER_HOUR + 1)['is_question'],
            'обращение к боту не гасится квотой',
        );
        $this->assertSame(
            0,
            (int) $this->row(self::MAX_PER_HOUR + 2)['is_question'],
            'подслушанное сверх квоты по-прежнему гасится',
        );
    }

    // -- гейты --------------------------------------------------------------------

    public function testNothingWrittenWhenCommunityDisabled(): void
    {
        $this->service(['community.enabled' => false])->handle(['message' => $this->message()]);

        $this->assertNull($this->row());
    }

    public function testMessageFromUnconfiguredChatIsIgnored(): void
    {
        $update = ['message' => $this->message(['chat' => ['id' => -1, 'type' => 'supergroup']])];

        $this->service([])->handle($update);

        $count = (new CommunityMessageModel())->countAll();
        $this->assertSame(0, $count);
    }

    // -- story 41: чужой бот не человек ---------------------------------------------

    /**
     * Реплай стороннего бота (`from.is_bot === true`) не пишется в `community_messages`
     * вовсе — иначе он ловился бы `CommunityAnswerMatcher::isCancelledByHumanReply()`
     * как «человек уже помог» и молча отменял выдержку (та же ошибка, что story 35
     * чинила для автора вопроса — здесь для постороннего бота).
     */
    public function testReplyFromOtherBotIsNotStored(): void
    {
        $update = ['message' => $this->message([
            'message_id'       => 200,
            'from'             => ['id' => 777, 'username' => 'other_bot', 'is_bot' => true],
            'text'             => 'вот тут написано в вики',
            'reply_to_message' => ['message_id' => 100, 'from' => ['id' => 555, 'is_bot' => false]],
        ])];

        $this->service([])->handle($update);

        $this->assertNull($this->row(200), 'сообщение от бота не должно попадать в community_messages');
    }

    /** Регрессия story 29/35: реплай человека по-прежнему пишется как обычно. */
    public function testReplyFromHumanIsStillStored(): void
    {
        $update = ['message' => $this->message([
            'message_id'       => 201,
            'from'             => ['id' => 777, 'username' => 'helper', 'is_bot' => false],
            'text'             => 'вот тут написано в вики',
            'reply_to_message' => ['message_id' => 100, 'from' => ['id' => 555, 'is_bot' => false]],
        ])];

        $this->service([])->handle($update);

        $row = $this->row(201);
        $this->assertNotNull($row, 'реплай человека обязан по-прежнему попадать в community_messages');
        $this->assertSame(100, (int) $row['reply_to_message_id']);
    }

    // -- story 45: анонимный админ группы остаётся человеком -------------------------

    /** `from.id = 1087968824` (`GroupAnonymousBot`) приходит с `is_bot: true`, но пишется. */
    private const GROUP_ANONYMOUS_BOT_ID = 1087968824;

    public function testGroupAnonymousBotMessageIsStored(): void
    {
        $update = ['message' => $this->message([
            'message_id' => 202,
            'from'       => ['id' => self::GROUP_ANONYMOUS_BOT_ID, 'is_bot' => true, 'username' => 'GroupAnonymousBot'],
            'text'       => 'привет от админа',
        ])];

        $this->service([])->handle($update);

        $row = $this->row(202);
        $this->assertNotNull($row, 'сообщение анонимного админа обязано попадать в community_messages');
        $this->assertSame(self::GROUP_ANONYMOUS_BOT_ID, (int) $row['telegram_user_id']);
    }

    /**
     * Сквозной путь story 45 — ПРИЁМ, а не прямая вставка строки в таблицу: апдейт от
     * анонимного админа проходит через `CommunityIngestService::handle()`, и только
     * получившуюся строку читает `CommunityAnswerMatcher::isCancelledByHumanReply()`.
     * Ловит именно ту регрессию story 41, из-за которой строка анонимного админа
     * вовсе не попадала в таблицу и ветка story 35 была недостижима.
     */
    public function testAnonymousAdminReplyReachesMatcherAsHumanReplyThroughIngest(): void
    {
        // Вопрос игрока — уже есть в фикстуре `message()` как message_id=100, автор 555.
        $this->service([])->handle(['message' => $this->message()]);

        $reply = ['message' => $this->message([
            'message_id'       => 203,
            'from'             => ['id' => self::GROUP_ANONYMOUS_BOT_ID, 'is_bot' => true, 'username' => 'GroupAnonymousBot'],
            'text'             => 'ответил игроку в личке чата',
            'reply_to_message' => ['message_id' => 100, 'from' => ['id' => 555, 'is_bot' => false]],
        ])];
        $this->service([])->handle($reply);

        $this->assertNotNull($this->row(203), 'реплай анонимного админа обязан попасть в community_messages');

        $question = $this->row(100);
        $this->assertNotNull($question);

        $matcher = new CommunityAnswerMatcher();
        $this->assertTrue(
            $matcher->isCancelledByHumanReply($question),
            'реплай анонимного админа обязан отменять выдержку — story 35 через приём story 45',
        );
    }

    // -- дефект ревью 2026-08-25 №3: выборочный список коллективных обращений --------

    public function testMoreCollectiveAddressWordsAreQuestions(): void
    {
        $this->service([])->handle(['message' => $this->message(['message_id' => 1, 'text' => 'Люди, где вода?'])]);
        $this->service([])->handle(['message' => $this->message(['message_id' => 2, 'text' => 'Мужики, как крафтить?'])]);
        $this->service([])->handle(['message' => $this->message(['message_id' => 3, 'text' => 'Друзья, кто-нибудь дома?'])]);

        $this->assertSame(1, (int) $this->row(1)['is_question'], '«Люди, …» — коллективное обращение');
        $this->assertSame(1, (int) $this->row(2)['is_question'], '«Мужики, …» — коллективное обращение');
        $this->assertSame(1, (int) $this->row(3)['is_question'], '«Друзья, …» — коллективное обращение');
    }

    // -- дефект ревью 2026-08-25 №2: правка сообщения не доходит до приёма -----------

    public function testEditedMessageUpdatesTextAndRecomputesFlags(): void
    {
        $this->service([])->handle(['message' => $this->message(['text' => 'привет'])]);
        $this->assertSame(0, (int) $this->row()['is_question'], 'исходное сообщение — не вопрос');

        $edited = ['edited_message' => $this->message(['text' => 'Роби, где вода?'])];
        $this->service([])->handle($edited);

        $row = $this->row();
        $this->assertSame('Роби, где вода?', $row['text']);
        $this->assertSame(1, (int) $row['is_question'], 'после правки признак вопроса пересчитан по новому тексту');
        $this->assertSame(1, (int) $row['addressed_to_bot'], 'после правки признак обращения пересчитан по новому тексту');

        $count = (new CommunityMessageModel())->where('chat_id', self::CHAT_ID)->where('message_id', 100)->countAllResults();
        $this->assertSame(1, $count, 'правка обновляет существующую строку, а не создаёт вторую');
    }

    /** Обратный случай: правка убирает признаки, если новый текст уже не вопрос. */
    public function testEditedMessageCanClearQuestionFlag(): void
    {
        $this->service([])->handle(['message' => $this->message(['text' => 'Роби, где вода?'])]);
        $this->assertSame(1, (int) $this->row()['is_question']);

        $edited = ['edited_message' => $this->message(['text' => 'а, само нашлось'])];
        $this->service([])->handle($edited);

        $row = $this->row();
        $this->assertSame(0, (int) $row['is_question']);
        $this->assertSame(0, (int) $row['addressed_to_bot']);
    }

    /**
     * Правка сообщения, которого в таблице нет (TTL-чистка удалила строку, либо
     * сообщение никогда не проходило приём — не тот чат/автор-бот) — no-op: без
     * дубля и без падения.
     */
    public function testEditOfMissingRowIsNoopAndDoesNotThrow(): void
    {
        $edited = ['edited_message' => $this->message(['message_id' => 999, 'text' => 'Роби, где вода?'])];

        $this->service([])->handle($edited);

        $this->assertNull($this->row(999));
        $this->assertSame(0, (new CommunityMessageModel())->countAll());
    }
}
