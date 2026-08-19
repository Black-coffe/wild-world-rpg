<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use App\Models\BaseStorageModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * Story storage-craft-insurance-08 — `BaseStorageModel::withdraw()` до этой стори
 * не имело собственного теста, хотя это единственная точка, физически двигающая
 * ресурс со склада (`ResourcePoolService::consume()` — единственный вызывающий,
 * покрыт отдельно чистым тестовым двойником в `ResourcePoolServiceTest`).
 *
 * Списание теперь идёт per-row условным `UPDATE ... WHERE quantity >= ?`
 * (`affectedRows()` вместо read-then-write из ранее прочитанного снапшота) —
 * гарантия, что withdraw() никогда не спишет больше, чем реально было, даже
 * если строку параллельно тронул другой запрос.
 *
 * @internal
 */
final class BaseStorageWithdrawTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = ['base_storage'];

    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query(
            'CREATE TABLE base_storage (
                id INT AUTO_INCREMENT PRIMARY KEY,
                character_id INT NULL,
                resource_id INT NULL,
                quantity INT NULL,
                arrived_from_cell INT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )'
        );
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        parent::tearDown();
    }

    private function insertRow(int $characterId, int $resourceId, int $qty): int
    {
        $db = Database::connect('tests');
        $db->table('base_storage')->insert([
            'character_id' => $characterId,
            'resource_id'  => $resourceId,
            'quantity'     => $qty,
        ]);

        return (int) $db->insertID();
    }

    /** @return list<array<string,mixed>> */
    private function rows(int $characterId, int $resourceId): array
    {
        $rows = Database::connect('tests')->table('base_storage')
            ->where('character_id', $characterId)
            ->where('resource_id', $resourceId)
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        return $rows;
    }

    public function testWithdrawTakesExactlyRequestedFromSingleRow(): void
    {
        $this->insertRow(1, 5, 100);

        $withdrawn = (new BaseStorageModel())->withdraw(1, 5, 30);

        $this->assertSame(30, $withdrawn);
        $rows = $this->rows(1, 5);
        $this->assertCount(1, $rows);
        $this->assertSame(70, (int) $rows[0]['quantity']);
    }

    /**
     * Таблица не несёт уникального индекса на (character_id, resource_id) —
     * withdraw() обязан уметь брать по нескольким строкам одной пары подряд
     * (по id ASC), не полагаясь на единственность строки.
     */
    public function testWithdrawDrainsMultipleRowsForSamePairInOrder(): void
    {
        $firstId  = $this->insertRow(2, 5, 10);
        $secondId = $this->insertRow(2, 5, 20);

        $withdrawn = (new BaseStorageModel())->withdraw(2, 5, 25);

        $this->assertSame(25, $withdrawn);
        $rows = $this->rows(2, 5);
        // первая строка (id ASC) вычерпана целиком и удалена, вторая — списана частично
        $this->assertCount(1, $rows);
        $this->assertSame($secondId, (int) $rows[0]['id']);
        $this->assertSame(5, (int) $rows[0]['quantity']);
        $this->assertNotContains($firstId, array_column($rows, 'id'));
    }

    /**
     * qty больше суммы всех строк — withdraw() честно возвращает МЕНЬШЕ
     * запрошенного (докблок метода), не бросает исключение и не выдумывает
     * недостающее.
     */
    public function testWithdrawReturnsLessThanRequestedWhenNotEnough(): void
    {
        $this->insertRow(3, 5, 7);

        $withdrawn = (new BaseStorageModel())->withdraw(3, 5, 100);

        $this->assertSame(7, $withdrawn, 'списано ровно сколько было — не больше и без выдумывания недостачи');
        $this->assertSame([], $this->rows(3, 5), 'вычерпанная строка удалена');
    }

    /** Экран выдачи зовёт withdraw() c PHP_INT_MAX для «забрать всё» — не должно переполняться/падать. */
    public function testWithdrawWithPhpIntMaxTakesEverythingAvailable(): void
    {
        $this->insertRow(4, 5, 12345);

        $withdrawn = (new BaseStorageModel())->withdraw(4, 5, PHP_INT_MAX);

        $this->assertSame(12345, $withdrawn);
        $this->assertSame([], $this->rows(4, 5), 'строка вычерпана целиком и удалена');
    }

    /** Обнулившаяся строка удаляется, а не остаётся нулевым остатком. */
    public function testWithdrawDeletesRowThatHitsZero(): void
    {
        $this->insertRow(6, 5, 15);

        $withdrawn = (new BaseStorageModel())->withdraw(6, 5, 15);

        $this->assertSame(15, $withdrawn);
        $this->assertSame([], $this->rows(6, 5), 'строка ушла в ноль — удалена целиком');
    }

    /** qty < 1 — no-op, ничего не трогает. */
    public function testWithdrawWithNonPositiveQtyIsNoop(): void
    {
        $this->insertRow(7, 5, 10);

        $this->assertSame(0, (new BaseStorageModel())->withdraw(7, 5, 0));
        $this->assertSame(0, (new BaseStorageModel())->withdraw(7, 5, -5));
        $this->assertSame(10, (int) $this->rows(7, 5)[0]['quantity'], 'остаток не тронут');
    }

    /**
     * Воспроизводит расхождение story storage-craft-insurance-08: строка уже
     * уменьшена конкурентным списанием НИЖЕ того, что withdraw() собирается
     * забрать — атомарный `UPDATE ... WHERE quantity >= ?` обязан её
     * пропустить (0 affectedRows), а не списать оставшееся молча.
     */
    public function testWithdrawSkipsRowConcurrentlyDrainedBelowRequestedAmount(): void
    {
        $rowId = $this->insertRow(8, 5, 50);

        // withdraw() читает строку через findAll() (видит quantity=50), но
        // прежде чем сам сделает свой conditional UPDATE — подменённый
        // withdrawRow() сперва проводит "конкурентное" списание до 3 прямым
        // SQL, и только потом вызывает родительский атомарный шаг. Родитель
        // обязан увидеть уже уменьшённый остаток и честно отказать
        // (0 affectedRows на попытке списать 50 из 3), а не молча списать
        // остаток и соврать об успехе.
        $model = new class extends BaseStorageModel {
            public int $raceRowId = 0;

            protected function withdrawRow(int $rowId, int $take): bool
            {
                if ($rowId === $this->raceRowId) {
                    Database::connect('tests')->query(
                        'UPDATE base_storage SET quantity = 3 WHERE id = ?',
                        [$rowId]
                    );
                }

                return parent::withdrawRow($rowId, $take);
            }
        };
        $model->raceRowId = $rowId;

        $withdrawn = $model->withdraw(8, 5, 50);

        $this->assertSame(
            0,
            $withdrawn,
            'withdraw() намеревался списать 50 (снапшот findAll), но реальный остаток к моменту UPDATE — 3: ' .
            'conditional UPDATE отказывает целиком (WHERE quantity >= 50 не проходит на quantity=3), ' .
            'а не списывает частично и не выдумывает недостающее'
        );
        $this->assertSame(3, (int) $this->rows(8, 5)[0]['quantity'], 'реальный остаток (3) не тронут отказавшимся списанием');
    }
}
