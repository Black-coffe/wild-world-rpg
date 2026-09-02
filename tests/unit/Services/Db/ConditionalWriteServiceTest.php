<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Db;

use App\Database\Migrations\Adr176CreateCommunityMessagesTable;
use App\Database\Migrations\W3aCreateBaseStorage;
use App\Services\Db\ConditionalWriteService;
use App\Services\Db\WriteOutcome;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * exploit-fix-01 (tracer story, ADR-181 §3) — `ConditionalWriteService`: три метода,
 * общий контракт `WriteOutcome`. Схема таблиц — прогон реальных миграций на группу
 * `tests` (`base_storage` для числового `quantity`, `community_messages` для
 * строкового `status`), а не ручной `CREATE TABLE`
 * (урок `feedback_test_schema_must_come_from_migration`): расхождение с продовой
 * схемой красит зелёным поведение, которого прод не допускает.
 *
 * @internal
 */
final class ConditionalWriteServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private bool $createdBaseStorage      = false;
    private bool $createdCommunityMessage = false;

    protected function setUp(): void
    {
        parent::setUp();

        $db = Database::connect('tests');

        if (! $db->tableExists('base_storage')) {
            $this->requireBaseStorageMigrationClass();
            $forge = Database::forge('tests');
            (new W3aCreateBaseStorage($forge instanceof Forge ? $forge : null))->up();
            $this->createdBaseStorage = true;
        }

        if (! $db->tableExists('community_messages')) {
            $this->requireCommunityMessagesMigrationClass();
            $forge = Database::forge('tests');
            (new Adr176CreateCommunityMessagesTable($forge instanceof Forge ? $forge : null))->up();
            $this->createdCommunityMessage = true;
        }
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');

        if ($this->createdBaseStorage) {
            $this->requireBaseStorageMigrationClass();
            $forge = Database::forge('tests');
            (new W3aCreateBaseStorage($forge instanceof Forge ? $forge : null))->down();
        } else {
            $db->table('base_storage')->truncate();
        }

        if ($this->createdCommunityMessage) {
            $this->requireCommunityMessagesMigrationClass();
            $forge = Database::forge('tests');
            (new Adr176CreateCommunityMessagesTable($forge instanceof Forge ? $forge : null))->down();
        } else {
            $db->table('community_messages')->truncate();
        }

        parent::tearDown();
    }

    private function requireBaseStorageMigrationClass(): void
    {
        if (! class_exists(W3aCreateBaseStorage::class, false)) {
            require_once APPPATH . 'Database/Migrations/2026-05-29-500000_W3aCreateBaseStorage.php';
        }
    }

    private function requireCommunityMessagesMigrationClass(): void
    {
        if (! class_exists(Adr176CreateCommunityMessagesTable::class, false)) {
            require_once APPPATH . 'Database/Migrations/2026-08-25-100000_Adr176CreateCommunityMessagesTable.php';
        }
    }

    private function insertStorageRow(int $characterId, int $resourceId, int $qty): int
    {
        $db = Database::connect('tests');
        $db->table('base_storage')->insert([
            'character_id' => $characterId,
            'resource_id'  => $resourceId,
            'quantity'     => $qty,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        return (int) $db->insertID();
    }

    /** @return array<string,mixed>|null */
    private function storageRow(int $id): ?array
    {
        $row = Database::connect('tests')->table('base_storage')->where('id', $id)->get()->getRowArray();

        return $row;
    }

    private function insertMessageRow(int $messageId, string $status): int
    {
        $db = Database::connect('tests');
        $db->table('community_messages')->insert([
            'chat_id'          => -1001,
            'message_id'       => $messageId,
            'telegram_user_id' => 555,
            'sent_at'          => date('Y-m-d H:i:s'),
            'status'           => $status,
        ]);

        return (int) $db->insertID();
    }

    /** @return array<string,mixed>|null */
    private function messageRow(int $id): ?array
    {
        $row = Database::connect('tests')->table('community_messages')->where('id', $id)->get()->getRowArray();

        return $row;
    }

    // ── decrementIfAtLeast: три исхода, три теста (Acceptance) ──

    public function testDecrementIfAtLeastAppliesAndDecreasesRowOnSuccess(): void
    {
        $id = $this->insertStorageRow(1, 5, 10);

        $outcome = (new ConditionalWriteService(Database::connect('tests')))
            ->decrementIfAtLeast('base_storage', $id, 'quantity', 4);

        $this->assertSame(WriteOutcome::Applied, $outcome);
        $this->assertSame(6, (int) $this->storageRow($id)['quantity']);
    }

    public function testDecrementIfAtLeastRefusesAndLeavesRowUntouchedWhenNotEnough(): void
    {
        $id = $this->insertStorageRow(2, 5, 3);

        $outcome = (new ConditionalWriteService(Database::connect('tests')))
            ->decrementIfAtLeast('base_storage', $id, 'quantity', 10);

        $this->assertSame(WriteOutcome::Refused, $outcome);
        $this->assertSame(3, (int) $this->storageRow($id)['quantity'], 'недостача не выдумывается — строка не тронута');
    }

    public function testDecrementIfAtLeastReturnsMissingWhenRowDoesNotExist(): void
    {
        $outcome = (new ConditionalWriteService(Database::connect('tests')))
            ->decrementIfAtLeast('base_storage', 999999, 'quantity', 1);

        $this->assertSame(WriteOutcome::Missing, $outcome);
    }

    public function testDecrementIfAtLeastDeletesRowThatReachesZeroWhenFlagIsSet(): void
    {
        $id = $this->insertStorageRow(3, 5, 7);

        $outcome = (new ConditionalWriteService(Database::connect('tests')))
            ->decrementIfAtLeast('base_storage', $id, 'quantity', 7, deleteWhenEmpty: true);

        $this->assertSame(WriteOutcome::Applied, $outcome);
        $this->assertNull($this->storageRow($id), 'нулевой остаток — не второе представление отсутствия строки');
    }

    public function testDecrementIfAtLeastKeepsZeroRowWhenFlagIsNotSet(): void
    {
        $id = $this->insertStorageRow(4, 5, 7);

        $outcome = (new ConditionalWriteService(Database::connect('tests')))
            ->decrementIfAtLeast('base_storage', $id, 'quantity', 7);

        $this->assertSame(WriteOutcome::Applied, $outcome);
        $this->assertSame(0, (int) $this->storageRow($id)['quantity']);
    }

    // ── transitionIfCurrent ──

    public function testTransitionIfCurrentAppliesWhenStatusMatches(): void
    {
        $id = $this->insertMessageRow(101, 'new');

        $outcome = (new ConditionalWriteService(Database::connect('tests')))
            ->transitionIfCurrent('community_messages', $id, 'status', 'new', 'answered');

        $this->assertSame(WriteOutcome::Applied, $outcome);
        $this->assertSame('answered', $this->messageRow($id)['status']);
    }

    public function testTransitionIfCurrentRefusesWhenStatusAlreadyMoved(): void
    {
        $id = $this->insertMessageRow(102, 'answered');

        $outcome = (new ConditionalWriteService(Database::connect('tests')))
            ->transitionIfCurrent('community_messages', $id, 'status', 'new', 'ignored');

        $this->assertSame(WriteOutcome::Refused, $outcome);
        $this->assertSame('answered', $this->messageRow($id)['status'], 'чужой переход не должен затирать статус');
    }

    public function testTransitionIfCurrentReturnsMissingWhenRowDoesNotExist(): void
    {
        $outcome = (new ConditionalWriteService(Database::connect('tests')))
            ->transitionIfCurrent('community_messages', 999999, 'status', 'new', 'answered');

        $this->assertSame(WriteOutcome::Missing, $outcome);
    }

    // ── increment ──

    public function testIncrementAppliesRelativeDeltaOnMatchingRow(): void
    {
        $id = $this->insertStorageRow(6, 9, 5);

        $outcome = (new ConditionalWriteService(Database::connect('tests')))
            ->increment('base_storage', ['character_id' => 6, 'resource_id' => 9], 'quantity', 3);

        $this->assertSame(WriteOutcome::Applied, $outcome);
        $this->assertSame(8, (int) $this->storageRow($id)['quantity']);
    }

    public function testIncrementReturnsMissingWhenWhereMatchesNoRow(): void
    {
        $outcome = (new ConditionalWriteService(Database::connect('tests')))
            ->increment('base_storage', ['character_id' => 777, 'resource_id' => 888], 'quantity', 1);

        $this->assertSame(WriteOutcome::Missing, $outcome);
    }
}
