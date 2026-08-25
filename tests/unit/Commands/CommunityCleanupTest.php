<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use App\Commands\CommunityCleanup;
use App\Database\Migrations\Adr176CreateCommunityAnswersTable;
use App\Database\Migrations\Adr176CreateCommunityMessagesTable;
use App\Database\Migrations\CreateAdminAuditLogTable;
use App\Models\CommunityAnswerModel;
use App\Models\CommunityMessageModel;
use App\Models\GameSettingsModel;
use App\Services\GameSettings\GameSettingsService;
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
 * Обе таблицы создаются прогоном реальных миграций на группу `tests` (Forge), как
 * `CommunityExportTest`/`CommunityChatSenderTest` (story community-chat-bot-42: до этого
 * `admin_audit_log` здесь была ручной изолированной `CREATE TABLE`, разошедшейся с
 * `CreateAdminAuditLogTable`).
 *
 * @internal
 */
final class CommunityCleanupTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const CHAT_ID = -1009999;

    private bool $createdTable      = false;
    private bool $createdAuditTable = false;

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

        // Story 32 — CommunityCleanup пишет в admin_audit_log (COMMUNITY_QUESTION_
        // AUTO_CLOSED, acceptance «не исчезает молча»). Реальная схема `tests`
        // отстаёт на непрогнанные миграции (см. CommunityChatSenderTest) — прогоняем
        // настоящую миграцию `CreateAdminAuditLogTable`, не ручную схему.
        if (! $db->tableExists('admin_audit_log')) {
            $this->requireAuditMigrationClass();
            $forge = Database::forge('tests');
            (new CreateAdminAuditLogTable($forge instanceof Forge ? $forge : null))->up();
            $this->createdAuditTable = true;
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

        if ($this->createdAuditTable) {
            $this->requireAuditMigrationClass();
            $forge = Database::forge('tests');
            (new CreateAdminAuditLogTable($forge instanceof Forge ? $forge : null))->down();
        } else {
            $db->table('admin_audit_log')->truncate();
        }

        parent::tearDown();
    }

    private function requireMigrationClass(): void
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

    private function requireAnswersMigrationClass(): void
    {
        if (! class_exists(Adr176CreateCommunityAnswersTable::class, false)) {
            require_once APPPATH . 'Database/Migrations/2026-08-25-100100_Adr176CreateCommunityAnswersTable.php';
        }
    }

    private function createAnswersTable(): void
    {
        $this->requireAnswersMigrationClass();
        $forge = Database::forge('tests');
        (new Adr176CreateCommunityAnswersTable($forge instanceof Forge ? $forge : null))->up();
    }

    private function dropAnswersTable(): void
    {
        $this->requireAnswersMigrationClass();
        $forge = Database::forge('tests');
        (new Adr176CreateCommunityAnswersTable($forge instanceof Forge ? $forge : null))->down();
    }

    private function insertAnswer(array $overrides = []): int
    {
        $row = array_merge([
            'client_key'       => 'cleanup-test-' . random_int(1, 1_000_000),
            'question_pattern' => 'где найти теплицу',
            'answer_text'      => 'Теплица строится на базе.',
            'requires_setting' => null,
            'source_ref'       => 'guide:building',
            'status'           => 'draft',
        ], $overrides);

        $model = new CommunityAnswerModel();
        $model->insert($row);

        return (int) $model->getInsertID();
    }

    /**
     * Двойник GameSettingsService — паттерн `CommunityExportTest::exporter()`, без
     * реальной таблицы `game_settings`. `community.enabled` намеренно передаётся
     * как значение НАСТРОЙКИ (даже хотя `cleanup()` его никогда не читает — story 32
     * acceptance «работает при выключенном килсвитче» проверяется вызовом через
     * `run()`, который единственный consultирует `$settings`).
     *
     * @param array<string, bool|int|string> $values
     */
    private function fakeSettings(array $values): GameSettingsService
    {
        $model = new class ($values) extends GameSettingsModel {
            /** @param array<string, bool|int|string> $values */
            public function __construct(private array $values)
            {
            }

            public function findByKey(string $key): ?array
            {
                if (! array_key_exists($key, $this->values)) {
                    return null;
                }

                return ['setting_key' => $key, 'value_type' => 'string', 'value_string' => (string) $this->values[$key]];
            }
        };

        return new GameSettingsService($model);
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

    // -- story 32, acceptance «не исчезает молча» -------------------------------------

    public function testClosingStaleRowWritesAuditTrail(): void
    {
        $staleId = $this->insertMessage(['status' => 'new', 'sent_at_hours_ago' => 72]);

        $this->command()->cleanup(30, 48);

        $log = Database::connect('tests')->table('admin_audit_log')
            ->where('action', 'COMMUNITY_QUESTION_AUTO_CLOSED')
            ->where('target_id', $staleId)
            ->get(1)->getRowArray();

        $this->assertNotNull($log, 'закрытие зависшего вопроса обязано оставить аудит-след — иначе он молча выпадает из очереди владельца');
    }

    // -- story 32, acceptance: community_answers вне области -------------------------

    public function testCleanupNeverTouchesCommunityAnswers(): void
    {
        $this->createAnswersTable();

        try {
            $answerId = $this->insertAnswer();
            $this->insertMessage(['sent_at_hours_ago' => 24 * 40]);
            $this->insertMessage(['status' => 'new', 'sent_at_hours_ago' => 72]);

            $result = $this->command()->cleanup(30, 48);

            $this->assertGreaterThan(0, $result['ttlDeleted'] + $result['staleClosed'], 'фикстура обязана реально что-то менять в community_messages');

            $row = (new CommunityAnswerModel())->find($answerId);
            $this->assertIsArray($row);
            $this->assertSame('draft', $row['status'], 'community_answers — отдельная KEEP-таблица, чистка её не трогает вовсе');
        } finally {
            $this->dropAnswersTable();
        }
    }

    // -- story 32, acceptance: killswitch community.enabled не гейтит чистку ---------

    /**
     * `community:cleanup` — обещание про удаление из закрепа, оно не должно
     * зависеть от того, включён ли автоответ. `run()` — единственный путь, который
     * реально consultирует `GameSettingsService`; двойник настроек явно несёт
     * `community.enabled=false`, чтобы проверить это утверждение, а не просто
     * повторить факт «cleanup() параметр не принимает» (тот же вывод дают все
     * тесты выше).
     */
    public function testRunIgnoresCommunityEnabledKillswitch(): void
    {
        $staleId = $this->insertMessage(['status' => 'new', 'sent_at_hours_ago' => 72]);
        $oldId   = $this->insertMessage(['status' => 'answered', 'sent_at_hours_ago' => 24 * 40]);

        $settings = $this->fakeSettings([
            'community.enabled'                  => false,
            'community.retention_days'           => 30,
            'community.question.max_age_hours'   => 48,
        ]);
        $command = new CommunityCleanup(service('logger'), service('commands'), $settings);

        $command->run([]);

        $this->assertSame('ignored', $this->statusOf($staleId), 'зависший вопрос обязан закрыться при выключенном community.enabled');
        $this->assertNull($this->statusOf($oldId), 'TTL-строка обязана удалиться при выключенном community.enabled');
    }
}
