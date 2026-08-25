<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use App\Commands\CommunityImport;
use App\Database\Migrations\Adr176CreateCommunityAnswersTable;
use App\Models\CommunityAnswerModel;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * Story community-chat-bot-11 — `community:import`: единственный push-канал
 * (ADR-176 §Модель угроз). Проверяет ровно инварианты, которые защищают этот канал:
 *
 *  - создаёт строки только со `status='draft'` (входной `status` игнорируется);
 *  - строку `approved`/`rejected` импорт не трогает;
 *  - идемпотентность по `client_key` (повторный импорт обновляет, не плодит);
 *  - потолки 256 КБ / 100 черновиков отклоняют пачку целиком;
 *  - черновик длиннее 3500 символов отклоняется поштучно, остальная пачка доходит;
 *  - пустой вход не падает.
 *
 * `parseInput()` — чистая функция над строкой, тестируется без реального STDIN (читать
 * настоящий STDIN в раннере теста заблокировало бы процесс). `applyBatch()` — ядро с
 * побочными эффектами в БД, тестируется на реальной тестовой таблице тем же паттерном,
 * что `CommunityIngestServiceTest`/`CommunitySettingsSeedTest`.
 *
 * @internal
 */
final class CommunityImportTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private bool $createdTable = false;

    protected function setUp(): void
    {
        parent::setUp();

        $db = Database::connect('tests');
        if (! $db->tableExists('community_answers')) {
            $this->requireMigrationClass();
            $forge = Database::forge('tests');
            (new Adr176CreateCommunityAnswersTable($forge instanceof Forge ? $forge : null))->up();
            $this->createdTable = true;
        }
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        $db->table('community_answers')->truncate();

        if ($this->createdTable) {
            $this->requireMigrationClass();
            $forge = Database::forge('tests');
            (new Adr176CreateCommunityAnswersTable($forge instanceof Forge ? $forge : null))->down();
        }

        parent::tearDown();
    }

    private function requireMigrationClass(): void
    {
        if (! class_exists(Adr176CreateCommunityAnswersTable::class, false)) {
            require_once APPPATH . 'Database/Migrations/2026-08-25-100100_Adr176CreateCommunityAnswersTable.php';
        }
    }

    private static function makeImportCommand(): CommunityImport
    {
        return new CommunityImport(service('logger'), service('commands'));
    }

    /** @return array<string, mixed> */
    private function draft(array $overrides = []): array
    {
        return array_merge([
            'client_key'       => bin2hex(random_bytes(8)),
            'question_pattern' => 'как получить {ресурс}',
            'answer_text'      => 'В /guide есть раздел про добычу.',
            'requires_setting' => null,
            'source_ref'       => 'guide:gather',
        ], $overrides);
    }

    // -- защита push-канала: только draft --------------------------------------------

    public function testCreatesDraftEvenWhenStatusFieldSupplied(): void
    {
        $result = self::makeImportCommand()->applyBatch([$this->draft(['client_key' => 'k1', 'status' => 'approved'])]);

        $this->assertSame(1, $result['created']);
        $row = (new CommunityAnswerModel())->where('client_key', 'k1')->first();
        $this->assertNotNull($row);
        $this->assertSame('draft', $row['status']);
    }

    public function testDoesNotTouchApprovedRow(): void
    {
        $model = new CommunityAnswerModel();
        $model->insert(array_merge($this->draft(['client_key' => 'k2']), [
            'status'      => 'approved',
            'approved_at' => date('Y-m-d H:i:s'),
            'approved_by' => 'owner',
        ]));
        $before = (new CommunityAnswerModel())->where('client_key', 'k2')->first();

        $result = self::makeImportCommand()->applyBatch([$this->draft(['client_key' => 'k2', 'answer_text' => 'ДРУГОЙ ТЕКСТ'])]);

        $after = (new CommunityAnswerModel())->where('client_key', 'k2')->first();
        $this->assertSame(0, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, $result['ignored']);
        $this->assertNotEmpty($result['messages']);
        $this->assertSame($before['answer_text'], $after['answer_text']);
        $this->assertSame('approved', $after['status']);
    }

    public function testDoesNotTouchRejectedRow(): void
    {
        $model = new CommunityAnswerModel();
        $model->insert(array_merge($this->draft(['client_key' => 'k3']), ['status' => 'rejected']));

        $result = self::makeImportCommand()->applyBatch([$this->draft(['client_key' => 'k3', 'answer_text' => 'ДРУГОЙ ТЕКСТ'])]);

        $after = (new CommunityAnswerModel())->where('client_key', 'k3')->first();
        $this->assertSame(1, $result['ignored']);
        $this->assertNotSame('ДРУГОЙ ТЕКСТ', $after['answer_text']);
        $this->assertSame('rejected', $after['status']);
    }

    // -- идемпотентность по client_key -------------------------------------------------

    public function testRepeatedImportOfSameBatchDoesNotDuplicate(): void
    {
        $batch = [$this->draft(['client_key' => 'k4'])];

        $first  = self::makeImportCommand()->applyBatch($batch);
        $second = self::makeImportCommand()->applyBatch($batch);

        $this->assertSame(1, $first['created']);
        $this->assertSame(0, $second['created']);
        $this->assertSame(1, $second['updated']);
        $this->assertSame(1, (new CommunityAnswerModel())->where('client_key', 'k4')->countAllResults());
    }

    public function testUpdateOnDraftChangesFields(): void
    {
        self::makeImportCommand()->applyBatch([$this->draft(['client_key' => 'k5', 'answer_text' => 'старый текст'])]);

        self::makeImportCommand()->applyBatch([$this->draft(['client_key' => 'k5', 'answer_text' => 'новый текст'])]);

        $row = (new CommunityAnswerModel())->where('client_key', 'k5')->first();
        $this->assertSame('новый текст', $row['answer_text']);
    }

    // -- поштучная валидация черновика -------------------------------------------------

    public function testRejectsSingleDraftLongerThan3500CharsButKeepsRestOfBatch(): void
    {
        $tooLong = str_repeat('а', 3501);

        $result = self::makeImportCommand()->applyBatch([
            $this->draft(['client_key' => 'k6-long', 'answer_text' => $tooLong]),
            $this->draft(['client_key' => 'k6-ok']),
        ]);

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['ignored']);
        $this->assertNull((new CommunityAnswerModel())->where('client_key', 'k6-long')->first());
        $this->assertNotNull((new CommunityAnswerModel())->where('client_key', 'k6-ok')->first());
    }

    public function testAllows3500CharAnswer(): void
    {
        $result = self::makeImportCommand()->applyBatch([
            $this->draft(['client_key' => 'k7', 'answer_text' => str_repeat('а', 3500)]),
        ]);

        $this->assertSame(1, $result['created']);
    }

    public function testMissingRequiredFieldIsIgnored(): void
    {
        $result = self::makeImportCommand()->applyBatch([
            $this->draft(['client_key' => 'k8', 'source_ref' => '']),
        ]);

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['ignored']);
    }

    public function testEmptyBatchDoesNotCrash(): void
    {
        $result = self::makeImportCommand()->applyBatch([]);

        $this->assertSame(0, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(0, $result['ignored']);
    }

    // -- parseInput() — потолки и пустой вход, без реального STDIN --------------------

    public function testParseInputRejectsOversizedPayloadWhole(): void
    {
        $huge = json_encode([array_fill(0, 1, str_repeat('x', CommunityImport::MAX_BYTES + 10))]);
        $this->assertIsString($huge);

        $parsed = CommunityImport::parseInput($huge);

        $this->assertFalse($parsed['ok']);
        $this->assertSame([], $parsed['drafts']);
    }

    public function testParseInputRejectsMoreThan100DraftsWhole(): void
    {
        $drafts = array_fill(0, CommunityImport::MAX_DRAFTS + 1, $this->draft());
        $json   = json_encode($drafts);
        $this->assertIsString($json);

        $parsed = CommunityImport::parseInput($json);

        $this->assertFalse($parsed['ok']);
        $this->assertSame([], $parsed['drafts']);
    }

    public function testParseInputAcceptsExactly100Drafts(): void
    {
        $drafts = array_fill(0, CommunityImport::MAX_DRAFTS, $this->draft());
        $json   = json_encode($drafts);
        $this->assertIsString($json);

        $parsed = CommunityImport::parseInput($json);

        $this->assertTrue($parsed['ok']);
        $this->assertCount(CommunityImport::MAX_DRAFTS, $parsed['drafts']);
    }

    public function testParseInputEmptyStringDoesNotCrash(): void
    {
        $parsed = CommunityImport::parseInput('');

        $this->assertTrue($parsed['ok']);
        $this->assertSame([], $parsed['drafts']);
    }

    public function testParseInputRejectsInvalidJson(): void
    {
        $parsed = CommunityImport::parseInput('{not json');

        $this->assertFalse($parsed['ok']);
    }

    public function testParseInputAcceptsDraftsWrapperObject(): void
    {
        $json = json_encode(['drafts' => [$this->draft(['client_key' => 'wrapped'])]]);
        $this->assertIsString($json);

        $parsed = CommunityImport::parseInput($json);

        $this->assertTrue($parsed['ok']);
        $this->assertCount(1, $parsed['drafts']);
    }
}
