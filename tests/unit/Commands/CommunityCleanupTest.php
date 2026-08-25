<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use App\Commands\CommunityCleanup;
use App\Database\Migrations\Adr176CreateCommunityMessagesTable;
use App\Models\CommunityMessageModel;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * Story community-chat-bot-22 — `community:cleanup`: TTL по `community.retention_days`
 * (по `sent_at`) + закрытие зависших `status=new` старше `community.question.max_age_hours`
 * в терминальный `ignored`. `community_answers` (банк) вне области — эта таблица здесь не
 * трогается вообще.
 *
 * Таблица создаётся прогоном реальной миграции на группу `tests` (Forge), как
 * `CommunityExportTest`.
 *
 * @internal
 */
final class CommunityCleanupTest extends CIUnitTestCase
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
        $agoHours = null;
        if (array_key_exists('sent_at_hours_ago', $overrides)) {
            $agoHours = $overrides['sent_at_hours_ago'];
            unset($overrides['sent_at_hours_ago']);
        }

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
        $id = (int) $model->getInsertID();

        // Feedback: DB-тесты с окном по NOW() сеют время DB-часами (NOW()-INTERVAL), не PHP
        // date() — иначе tz-skew PHP↔DB валит окно (feedback_db_clock_seed_not_php_in_time_window_tests).
        if ($agoHours !== null) {
            Database::connect('tests')->query(
                'UPDATE community_messages SET sent_at = NOW() - INTERVAL ? HOUR WHERE id = ?',
                [$agoHours, $id]
            );
        }

        return $id;
    }

    private function command(): CommunityCleanup
    {
        return new CommunityCleanup(service('logger'), service('commands'));
    }

    private function statusOf(int $id): ?string
    {
        $row = (new CommunityMessageModel())->find($id);

        return $row['status'] ?? null;
    }

    // -- TTL по sent_at ---------------------------------------------------------------

    public function testDeletesRowOlderThanWindow(): void
    {
        $oldId = $this->insertMessage(['sent_at_hours_ago' => 24 * 40]);

        $result = $this->command()->cleanup(30, 48);

        $this->assertSame(1, $result['ttlDeleted']);
        $this->assertNull($this->statusOf($oldId));
    }

    public function testKeepsRowInsideWindow(): void
    {
        $freshId = $this->insertMessage(['sent_at_hours_ago' => 1]);

        $result = $this->command()->cleanup(30, 48);

        $this->assertSame(0, $result['ttlDeleted']);
        $this->assertNotNull($this->statusOf($freshId));
    }

    // -- зависшие вопросы ---------------------------------------------------------------

    public function testClosesStaleNewRowPastQuestionWindow(): void
    {
        $staleId = $this->insertMessage(['status' => 'new', 'sent_at_hours_ago' => 72]);

        $result = $this->command()->cleanup(30, 48);

        $this->assertSame(1, $result['staleClosed']);
        $this->assertSame('ignored', $this->statusOf($staleId));
    }

    public function testDoesNotCloseFreshNewRow(): void
    {
        $freshId = $this->insertMessage(['status' => 'new', 'sent_at_hours_ago' => 1]);

        $result = $this->command()->cleanup(30, 48);

        $this->assertSame(0, $result['staleClosed']);
        $this->assertSame('new', $this->statusOf($freshId));
    }

    public function testDoesNotTouchAlreadyAnsweredRowEvenIfOld(): void
    {
        $answeredId = $this->insertMessage(['status' => 'answered', 'sent_at_hours_ago' => 72]);

        $result = $this->command()->cleanup(30, 48);

        $this->assertSame(0, $result['staleClosed']);
        $this->assertSame('answered', $this->statusOf($answeredId));
    }

    // -- dry-run --------------------------------------------------------------------

    public function testDryRunChangesNothing(): void
    {
        $oldId   = $this->insertMessage(['status' => 'answered', 'sent_at_hours_ago' => 24 * 40]);
        $staleId = $this->insertMessage(['status' => 'new', 'sent_at_hours_ago' => 72]);

        $result = $this->command()->cleanup(30, 48, dryRun: true);

        $this->assertSame(1, $result['ttlCandidates']);
        $this->assertSame(1, $result['staleCandidates']);
        $this->assertSame(0, $result['ttlDeleted']);
        $this->assertSame(0, $result['staleClosed']);
        $this->assertNotNull($this->statusOf($oldId));
        $this->assertSame('new', $this->statusOf($staleId));
    }

    // -- идемпотентность --------------------------------------------------------------

    public function testSecondRunSameDayIsNoop(): void
    {
        $this->insertMessage(['sent_at_hours_ago' => 24 * 40]);
        $this->insertMessage(['status' => 'new', 'sent_at_hours_ago' => 72]);

        $this->command()->cleanup(30, 48);
        $second = $this->command()->cleanup(30, 48);

        $this->assertSame(0, $second['ttlDeleted']);
        $this->assertSame(0, $second['staleClosed']);
    }
}
