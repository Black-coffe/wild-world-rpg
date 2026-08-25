<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Database\Migrations\Adr176CreateCommunityMessagesTable;
use App\Models\CommunityMessageModel;
use App\Models\GameSettingsModel;
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

    public function testReplyToBotMarksAddressedToBot(): void
    {
        $update = ['message' => $this->message([
            'text'             => 'а это где смотреть',
            'reply_to_message' => ['message_id' => 5, 'from' => ['id' => 9, 'is_bot' => true]],
        ])];

        $this->service([])->handle($update);

        $this->assertSame(1, (int) $this->row()['addressed_to_bot']);
    }

    public function testRobiPrefixMarksAddressedToBot(): void
    {
        $this->service([])->handle(['message' => $this->message(['text' => 'Роби, а где взять доски'])]);

        $this->assertSame(1, (int) $this->row()['addressed_to_bot']);
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
}
