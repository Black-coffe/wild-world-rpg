<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use App\Commands\CommunityExport;
use App\Database\Migrations\Adr176CreateCommunityMessagesTable;
use App\Models\CommunityMessageModel;
use App\Models\GameSettingsModel;
use App\Services\GameSettings\GameSettingsService;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * Story community-chat-bot-11 — `community:export`: курсор по id (не offset), потолок
 * `--limit`, скоуп по настроенному `community.chat_id`, отбор открытых вопросов + контекст
 * их треда (ADR-176 §Формат и ограничения). stdout/stderr-чистота (JSON и только JSON)
 * гарантируется устройством `run()` — единственный `fwrite(STDOUT, …)` на весь метод,
 * диагностика идёт исключительно в stderr; это проверяется на уровне `collectRows()`
 * (данные), не прогоном реального процесса — как и `CommunityIngestServiceTest`.
 *
 * Таблица создаётся прогоном реальной миграции на группу `tests` (Forge), не `$migrate`,
 * тем же паттерном, что `CommunityIngestServiceTest`.
 *
 * @internal
 */
final class CommunityExportTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const CHAT_ID = -1009999;

    private bool $createdTable = false;

    protected function setUp(): void
    {
        parent::setUp();

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

    private function requireMigrationClass(): void
    {
        if (! class_exists(Adr176CreateCommunityMessagesTable::class, false)) {
            require_once APPPATH . 'Database/Migrations/2026-08-25-100000_Adr176CreateCommunityMessagesTable.php';
        }
    }

    private function insertMessage(array $overrides = []): int
    {
        $row = array_merge([
            'chat_id'             => self::CHAT_ID,
            'message_thread_id'   => null,
            'message_id'          => random_int(1, 1_000_000),
            'reply_to_message_id' => null,
            'telegram_user_id'    => 555,
            'username'            => 'igrok',
            'text'                => 'привет',
            'sent_at'             => date('Y-m-d H:i:s'),
            'is_question'         => 0,
            'addressed_to_bot'    => 0,
            'status'              => 'new',
            'created_at'          => date('Y-m-d H:i:s'),
        ], $overrides);

        $model = new CommunityMessageModel();
        $model->insert($row);

        return (int) $model->getInsertID();
    }

    /** @param array<string, bool|int|string> $values */
    private function exporter(array $values = []): CommunityExport
    {
        $defaults = ['community.chat_id' => (string) self::CHAT_ID];

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

                return ['setting_key' => $key, 'value_type' => 'string', 'value_string' => (string) $value];
            }
        };

        return new CommunityExport(service('logger'), service('commands'), new GameSettingsService($model));
    }

    // -- курсор и лимит -------------------------------------------------------------

    public function testSinceExcludesRowsAtOrBelowCursor(): void
    {
        $id1 = $this->insertMessage(['is_question' => 1]);
        $id2 = $this->insertMessage(['is_question' => 1]);
        $id3 = $this->insertMessage(['is_question' => 1]);

        $rows = $this->exporter()->collectRows($id1, 200);

        $ids = array_map('intval', array_column($rows, 'id'));
        $this->assertNotContains($id1, $ids, 'since=id1 не должен вернуть саму строку id1');
        $this->assertContains($id2, $ids);
        $this->assertContains($id3, $ids);
    }

    public function testDefaultSinceZeroReturnsFromStart(): void
    {
        $id1 = $this->insertMessage(['is_question' => 1]);

        $rows = $this->exporter()->collectRows(0, 200);

        $this->assertContains($id1, array_map('intval', array_column($rows, 'id')));
    }

    public function testClampLimitCapsAtHardLimit(): void
    {
        $this->assertSame(1000, CommunityExport::clampLimit(5000));
    }

    public function testClampLimitKeepsValueUnderCap(): void
    {
        $this->assertSame(50, CommunityExport::clampLimit(50));
    }

    public function testClampLimitFallsBackToDefaultWhenZeroOrNegative(): void
    {
        $this->assertSame(CommunityExport::DEFAULT_LIMIT, CommunityExport::clampLimit(0));
        $this->assertSame(CommunityExport::DEFAULT_LIMIT, CommunityExport::clampLimit(-5));
    }

    public function testLimitBoundsResultCount(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->insertMessage(['is_question' => 1]);
        }

        $rows = $this->exporter()->collectRows(0, 3);

        $this->assertCount(3, $rows);
    }

    // -- скоуп по chat_id и содержимое ------------------------------------------------

    public function testDoesNotLeakOtherChatMessages(): void
    {
        $this->insertMessage(['chat_id' => self::CHAT_ID, 'is_question' => 1]);
        $this->insertMessage(['chat_id' => -222, 'is_question' => 1]);

        $rows = $this->exporter()->collectRows(0, 200);

        foreach ($rows as $row) {
            $this->assertSame(self::CHAT_ID, (int) $row['chat_id']);
        }
    }

    public function testMissingChatIdSettingReturnsEmptyNotError(): void
    {
        $this->insertMessage(['is_question' => 1]);

        // Пустая настройка community.chat_id — fail-closed (ADR-176): пусто, не ошибка.
        $rows = $this->exporter(['community.chat_id' => ''])->collectRows(0, 200);

        $this->assertSame([], $rows);
    }

    public function testExportsOpenQuestionAndItsThreadContext(): void
    {
        $questionId = $this->insertMessage(['is_question' => 1, 'message_thread_id' => 42]);
        $contextId  = $this->insertMessage(['is_question' => 0, 'message_thread_id' => 42, 'text' => 'контекст треда']);
        $unrelatedId = $this->insertMessage(['is_question' => 0, 'message_thread_id' => 99, 'text' => 'другой тред']);

        $rows = $this->exporter()->collectRows(0, 200);
        $ids  = array_map('intval', array_column($rows, 'id'));

        $this->assertContains($questionId, $ids);
        $this->assertContains($contextId, $ids);
        $this->assertNotContains($unrelatedId, $ids);
    }

    public function testEmptyBufferReturnsEmptyArrayNotCrash(): void
    {
        $rows = $this->exporter()->collectRows(0, 200);

        $this->assertSame([], $rows);
    }
}
