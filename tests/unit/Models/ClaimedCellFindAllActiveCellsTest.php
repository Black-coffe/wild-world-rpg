<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\ClaimedCellModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * angela-second-base-bugs-06 — единственный новый метод модели этой спеки,
 * `ClaimedCellModel::findAllActiveCells()`, был непокрыт: в
 * `TextMapMultiBaseTest` модель подменена анонимным подклассом целиком, то
 * есть исполнялось поведение теста, а не метода. Здесь метод исполняется
 * по-настоящему, на реальной таблице.
 *
 * Схема таблицы — 1:1 с `2024-05-23-061031_CreateClaimedCellsTable.php`
 * (без FK — они ссылались бы на `characters`/`map`, которых в этом
 * изолированном наборе нет; тот же паттерн, что у
 * `tests/unit/Player/BuildingUpgradeBaseScopeTest.php`), под приватным
 * префиксом `facc_`, чтобы не драться за общие имена с параллельными
 * агентами волны.
 *
 * @internal
 */
final class ClaimedCellFindAllActiveCellsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    public const T_CELLS = 'facc_claimed_cells';

    private \CodeIgniter\Database\BaseConnection $conn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->conn = Database::connect('tests');
        $this->conn->query('DROP TABLE IF EXISTS ' . self::T_CELLS);

        $this->conn->query('CREATE TABLE ' . self::T_CELLS . ' ('
            . 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, '
            . 'character_id INT UNSIGNED NOT NULL, '
            . 'map_cell_id INT UNSIGNED NOT NULL, '
            . 'claimed_at DATETIME NOT NULL, '
            . "status ENUM('active','abandoned') NOT NULL DEFAULT 'active'"
            . ') ENGINE=InnoDB');
    }

    protected function tearDown(): void
    {
        $this->conn->query('DROP TABLE IF EXISTS ' . self::T_CELLS);
        parent::tearDown();
    }

    private function model(): ClaimedCellModel
    {
        return new class () extends ClaimedCellModel {
            protected $table = ClaimedCellFindAllActiveCellsTest::T_CELLS;
        };
    }

    /** @return int вставленный id */
    private function seed(int $charId, int $cell, string $status = 'active'): int
    {
        $this->conn->table(self::T_CELLS)->insert([
            'character_id' => $charId,
            'map_cell_id'  => $cell,
            'claimed_at'   => date('Y-m-d H:i:s'),
            'status'       => $status,
        ]);
        return (int) $this->conn->insertID();
    }

    // ---- AC: обе активные базы одного персонажа возвращаются ----

    public function testReturnsBothActiveBasesOfCharacter(): void
    {
        $charId = 2001;
        $idFirst  = $this->seed($charId, 10);
        $idSecond = $this->seed($charId, 20);

        $rows = $this->model()->findAllActiveCells($charId);

        $this->assertCount(2, $rows);
        $ids = array_map(static fn (array $r) => (int) $r['id'], $rows);
        $this->assertSame([$idFirst, $idSecond], $ids);
    }

    // ---- AC: заброшенная база не возвращается, даже единственная и первая по id ----

    public function testAbandonedBaseIsExcludedEvenWhenAloneAndFirstById(): void
    {
        $charId = 2002;
        $this->seed($charId, 10, 'abandoned');

        $rows = $this->model()->findAllActiveCells($charId);

        $this->assertSame([], $rows);
    }

    public function testAbandonedBaseIsExcludedWhenMixedWithActiveOnes(): void
    {
        $charId = 2003;
        $this->seed($charId, 10, 'abandoned'); // первая по id, но не активна
        $idActive = $this->seed($charId, 20, 'active');

        $rows = $this->model()->findAllActiveCells($charId);

        $this->assertCount(1, $rows);
        $this->assertSame($idActive, (int) $rows[0]['id']);
        $this->assertSame('active', $rows[0]['status']);
    }

    // ---- AC: строки чужого персонажа не возвращаются ----

    public function testDoesNotReturnRowsOfAnotherCharacter(): void
    {
        $charId  = 2004;
        $otherId = 2005;
        $this->seed($otherId, 10, 'active');
        $mine = $this->seed($charId, 20, 'active');

        $rows = $this->model()->findAllActiveCells($charId);

        $this->assertCount(1, $rows);
        $this->assertSame($mine, (int) $rows[0]['id']);
    }

    // ---- AC: порядок детерминирован и возрастает по id, а не по порядку вставки ----

    public function testOrderIsAscendingById(): void
    {
        $charId = 2006;
        // Вставляем так, что порядок по map_cell_id (30 -> 20 -> 10) обратен
        // порядку id (1 -> 2 -> 3), чтобы проверка не могла случайно
        // совпасть с порядком вставки.
        $idA = $this->seed($charId, 30);
        $idB = $this->seed($charId, 20);
        $idC = $this->seed($charId, 10);
        $this->assertTrue($idA < $idB && $idB < $idC);

        $rows = $this->model()->findAllActiveCells($charId);

        $ids = array_map(static fn (array $r) => (int) $r['id'], $rows);
        $this->assertSame([$idA, $idB, $idC], $ids, 'порядок обязан идти по возрастанию id');
    }

    // ---- AC: персонаж без активных баз получает пустой массив, не null и не исключение ----

    public function testCharacterWithNoActiveBasesGetsEmptyArray(): void
    {
        $rows = $this->model()->findAllActiveCells(999999);

        $this->assertIsArray($rows);
        $this->assertSame([], $rows);
    }
}
