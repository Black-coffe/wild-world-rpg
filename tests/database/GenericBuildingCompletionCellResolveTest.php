<?php

namespace Tests\Database;

use App\TaskHandlers\Built\GenericBuildingCompletionHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * Анти-орфан релокейшн-гонки (аудит 2026-06-14).
 *
 * `GenericBuildingCompletionHandler::resolveBuildCell` фиксирует `base_cell` при
 * СТАРТЕ стройки. Если база переехала/снесена, пока стройка шла, постройка раньше
 * лендила на брошенную клетку (= потерянная постройка + orphan-ряд без active claim).
 * Реальный случай: char 880 «SarCasM» — Мастерская осталась сиротой на старой
 * клетке 944902 при базе на 946902 (после серии переездов).
 *
 * Фикс: если зафиксированной базы больше нет — постройка перенаправляется на
 * актуальную активную базу игрока. Нормальный поток (база на месте) не меняется.
 *
 * @internal
 */
final class GenericBuildingCompletionCellResolveTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = ['characters', 'claimed_cells'];

    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('CREATE TABLE characters (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cell_number INT NULL,
            level INT NULL DEFAULT 1
        )');
        $db->query('CREATE TABLE claimed_cells (
            id INT AUTO_INCREMENT PRIMARY KEY,
            character_id INT NULL,
            map_cell_id INT NULL,
            status VARCHAR(16) NULL DEFAULT "active"
        )');
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        parent::tearDown();
    }

    /** Сабкласс-обёртка: открывает protected resolveBuildCell для прямого вызова без handle(). */
    private function resolver(): ExposedBuildCellHandler
    {
        return new ExposedBuildCellHandler();
    }

    /** Нормальный поток: base_cell с активным claim → возвращается без изменений (byte-identical). */
    public function testKeepsValidBaseCellUnchanged(): void
    {
        $db = Database::connect('tests');
        $db->table('characters')->insert(['id' => 5, 'cell_number' => 100, 'level' => 3]);
        $db->table('claimed_cells')->insert(['character_id' => 5, 'map_cell_id' => 100, 'status' => 'active']);

        $task = ['character_id' => 5, 'task_settings' => json_encode(['base_cell' => 100])];
        $this->assertSame(100, $this->resolver()->call($task, 100), 'Валидная база не должна перенаправляться');
    }

    /** Релокейшн-гонка: зафиксированной базы 944902 нет, активная база — 946902 → редирект на 946902. */
    public function testRedirectsToActiveBaseWhenPinnedCellGone(): void
    {
        $db = Database::connect('tests');
        // SarCasM-сценарий: стройка стартовала на 944902, база переехала на 946902.
        $db->table('characters')->insert(['id' => 880, 'cell_number' => 946902, 'level' => 19]);
        $db->table('claimed_cells')->insert(['character_id' => 880, 'map_cell_id' => 946902, 'status' => 'active']);
        // claim на 944902 НЕТ (переехала).

        $task = ['character_id' => 880, 'task_settings' => json_encode(['base_cell' => 944902])];
        $this->assertSame(
            946902,
            $this->resolver()->call($task, 946902),
            'Постройка должна догнать переехавшую базу (946902), а не осиротеть на 944902'
        );
    }

    /** Игрок стоит на активной базе, но base_cell указывает на чужую/исчезнувшую → редирект на текущую базу. */
    public function testRedirectsToCellWhereStandingIfItIsActiveBase(): void
    {
        $db = Database::connect('tests');
        $db->table('characters')->insert(['id' => 6, 'cell_number' => 777, 'level' => 5]);
        $db->table('claimed_cells')->insert(['character_id' => 6, 'map_cell_id' => 777, 'status' => 'active']);

        $task = ['character_id' => 6, 'task_settings' => json_encode(['base_cell' => 555])];
        $this->assertSame(777, $this->resolver()->call($task, 777), 'Редирект на базу, где стоит игрок (777)');
    }

    /** Нет активных баз вовсе → перенаправлять некуда, base_cell остаётся (best-effort, без краша). */
    public function testKeepsBaseCellWhenNoActiveBaseExists(): void
    {
        $db = Database::connect('tests');
        $db->table('characters')->insert(['id' => 8, 'cell_number' => 500, 'level' => 2]);
        // claimed_cells пуст для char 8.

        $task = ['character_id' => 8, 'task_settings' => json_encode(['base_cell' => 944902])];
        $this->assertSame(944902, $this->resolver()->call($task, 500), 'Без активных баз — оставляем как есть (некуда редиректить)');
    }

    /** Снесённая база (status != active) не считается активной → редирект. */
    public function testInactiveClaimDoesNotCountAsActiveBase(): void
    {
        $db = Database::connect('tests');
        $db->table('characters')->insert(['id' => 9, 'cell_number' => 222, 'level' => 4]);
        $db->table('claimed_cells')->insert(['character_id' => 9, 'map_cell_id' => 944902, 'status' => 'inactive']);
        $db->table('claimed_cells')->insert(['character_id' => 9, 'map_cell_id' => 222, 'status' => 'active']);

        $task = ['character_id' => 9, 'task_settings' => json_encode(['base_cell' => 944902])];
        $this->assertSame(222, $this->resolver()->call($task, 222), 'Неактивный claim на 944902 не спасает → редирект на активную 222');
    }
}

/**
 * Тест-обёртка: открывает protected resolveBuildCell (named-класс → phpstan видит тип).
 *
 * @internal
 */
final class ExposedBuildCellHandler extends GenericBuildingCompletionHandler
{
    /** @param array<string,mixed> $task */
    public function call(array $task, int $fallback): int
    {
        return $this->resolveBuildCell($task, $fallback);
    }
}
