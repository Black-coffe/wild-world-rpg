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
 * Схема — реальная таблица `character_resources` из уже накатанной общей тестовой БД, а не
 * ручной `CREATE TABLE` (урок `feedback_test_schema_must_come_from_migration`: расхождение с
 * продовой схемой красит зелёным поведение, которого прод не допускает). Стенд общий
 * (`wildworld_tests`, параллельно работают другие воркеры) — никаких TRUNCATE/DROP, только
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

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach ($this->insertedRowIds as $id) {
            $db->table('character_resources')->where('id', $id)->delete();
        }
        $this->insertedRowIds = [];

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
}
