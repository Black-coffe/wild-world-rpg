<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\CharacterResourceModel;
use App\Services\Db\WriteOutcome;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * exploit-fix-06 (ADR-181 §3) — форма записи `CharacterResourceModel::decrementIfAtLeast()`,
 * заменившего удалённый `decreaseResources()`. Тот читал строку через `first()`, вычитал в PHP
 * и писал — при `$amount > quantity` не отказывал, а удалял строку и рапортовал успех. Здесь
 * решение принимает один условный `UPDATE` с проверкой `affectedRows()`.
 *
 * Схема — та же форма, что и у реальной таблицы `character_resources` на этом стенде (не
 * ручное изобретение: сверена точечно по `DESCRIBE`, включая легаси-колонку
 * `id_telegram_users`, которой нет ни в одной миграции репозитория — тот же класс разрыва
 * истории миграций, что уже задокументирован в `GapAuditTest`/`CancelQueuedCraftConditionalDeleteTest`
 * для `character_resources`, урок `feedback_test_schema_must_come_from_migration`: расхождение
 * с продовой схемой красит зелёным поведение, которого прод не допускает — значит схема обязана
 * совпадать с фактической, а не с историей миграций, где она разошлась). Таблица создаётся,
 * только если её на стенде нет (другие тесты репозитория дропают общие таблицы и не
 * восстанавливают их — story exploit-fix-13), и дропается в tearDown только если создал её
 * именно этот тест — персистентную общую таблицу дропать нельзя. Стенд общий (`wildworld_tests`,
 * параллельно работают другие воркеры) — на персистентной таблице никаких TRUNCATE/DROP, только
 * удаление своих строк по `id` в tearDown, и id персонажа/ресурса — случайные на каждый тест,
 * чтобы `WHERE id_characters = ? AND id_resources = ?` внутри `decrementIfAtLeast()` не мог
 * зацепить чужую строку той же пары (урок `feedback_first_row_is_not_the_right_row`).
 *
 * @internal
 */
final class CharacterResourceConditionalWriteTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    /** @var list<int> id вставленных строк character_resources — удаляются в tearDown поштучно. */
    private array $insertedRowIds = [];

    /** Создал ли этот тест таблицу `character_resources` сам (и поэтому вправе её дропнуть). */
    private bool $createdTable = false;

    protected function setUp(): void
    {
        parent::setUp();

        $db = Database::connect('tests');
        // Кэш списка таблиц живёт на соединении между тестами класса — без сброса тест видит
        // устаревшее «таблица есть», хотя другой тест репозитория её уже дропнул (тот же класс
        // дефекта, что story exploit-fix-11 чинила в GapAuditTest).
        $db->resetDataCache();

        if (! $db->tableExists('character_resources')) {
            $db->query('
                CREATE TABLE character_resources (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    id_characters INT NULL,
                    id_resources INT NULL,
                    id_telegram_users INT NULL,
                    quantity INT NULL DEFAULT 0,
                    custom_data TEXT NULL,
                    created_at DATETIME NULL,
                    updated_at DATETIME NULL
                )
            ');
            $this->createdTable = true;
        }
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach ($this->insertedRowIds as $id) {
            $db->table('character_resources')->where('id', $id)->delete();
        }
        $this->insertedRowIds = [];

        if ($this->createdTable) {
            $db->query('DROP TABLE IF EXISTS character_resources');
            $this->createdTable = false;
        }

        parent::tearDown();
    }

    private function insertRow(int $characterId, int $resourceId, int $qty): int
    {
        $db = Database::connect('tests');
        $db->table('character_resources')->insert([
            'id_characters'     => $characterId,
            'id_resources'      => $resourceId,
            'id_telegram_users' => 1,
            'quantity'          => $qty,
            'created_at'        => date('Y-m-d H:i:s'),
            'updated_at'        => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();

        $this->insertedRowIds[] = $id;

        return $id;
    }

    /** @return array<string,mixed>|null */
    private function row(int $id): ?array
    {
        return Database::connect('tests')->table('character_resources')->where('id', $id)->get()->getRowArray();
    }

    /** Свежая случайная пара — общий стенд, чужие строки той же пары исключены. */
    private function uniqueId(): int
    {
        return random_int(600000000, 999999999);
    }

    public function testRefusesAndLeavesRowUntouchedWhenAmountExceedsQuantity(): void
    {
        $characterId = $this->uniqueId();
        $resourceId  = $this->uniqueId();
        $id          = $this->insertRow($characterId, $resourceId, 5);

        $outcome = (new CharacterResourceModel())->decrementIfAtLeast($characterId, $resourceId, 10);

        $this->assertSame(WriteOutcome::Refused, $outcome);
        $row = $this->row($id);
        $this->assertNotNull($row, 'отказ не должен удалять строку');
        $this->assertSame(5, (int) $row['quantity'], 'недостача не выдумывается — строка не тронута');
    }

    public function testAppliesAndDeletesRowWhenExactAmountDrainsToZero(): void
    {
        $characterId = $this->uniqueId();
        $resourceId  = $this->uniqueId();
        $id          = $this->insertRow($characterId, $resourceId, 5);

        $outcome = (new CharacterResourceModel())->decrementIfAtLeast($characterId, $resourceId, 5);

        $this->assertSame(WriteOutcome::Applied, $outcome);
        $this->assertNull($this->row($id), 'обнуление удаляет строку — прежний инвариант decreaseResources()');
    }

    public function testAppliesAndLeavesRemainderWhenPartialAmountRequested(): void
    {
        $characterId = $this->uniqueId();
        $resourceId  = $this->uniqueId();
        $id          = $this->insertRow($characterId, $resourceId, 5);

        $outcome = (new CharacterResourceModel())->decrementIfAtLeast($characterId, $resourceId, 3);

        $this->assertSame(WriteOutcome::Applied, $outcome);
        $row = $this->row($id);
        $this->assertNotNull($row);
        $this->assertSame(2, (int) $row['quantity']);
    }

    public function testReturnsMissingWhenNoRowForCharacterAndResource(): void
    {
        $outcome = (new CharacterResourceModel())->decrementIfAtLeast($this->uniqueId(), $this->uniqueId(), 1);

        $this->assertSame(WriteOutcome::Missing, $outcome);
    }

    /**
     * Acceptance 🔴 (exploit-fix-24): `(id_characters, id_resources)` не несёт UNIQUE —
     * если пара продублирована (реальный разрыв на этом стенде — таблица не завела
     * ограничения ни в одной миграции), `first()` без `orderBy` возвращал бы
     * произвольную из двух строк. С `orderBy('id', 'ASC')` выбор детерминирован:
     * всегда первая (наименьший id, самая старая) строка пары. Доказывается тем, что
     * `decrementIfAtLeast()` списывает ИМЕННО первую вставленную строку, а не вторую,
     * даже когда только вторая формально достаточна для более крупного списания.
     */
    public function testDecrementIfAtLeastPicksTheLowestIdRowWhenPairIsDuplicated(): void
    {
        $characterId = $this->uniqueId();
        $resourceId  = $this->uniqueId();
        $firstId     = $this->insertRow($characterId, $resourceId, 4);
        $secondId    = $this->insertRow($characterId, $resourceId, 6);

        $outcome = (new CharacterResourceModel())->decrementIfAtLeast($characterId, $resourceId, 4);

        $this->assertSame(WriteOutcome::Applied, $outcome);
        $this->assertNull($this->row($firstId), 'первая (наименьший id) строка дубля-пары обязана быть выбрана и опустошена');
        $secondRow = $this->row($secondId);
        $this->assertNotNull($secondRow, 'вторая строка дубля-пары не должна быть тронута списанием первой');
        $this->assertSame(6, (int) $secondRow['quantity']);
    }
}
