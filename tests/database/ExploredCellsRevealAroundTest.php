<?php

namespace Tests\Database;

use App\Models\ExploredCellsModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * ADR-019 Step 1 — integration-тесты на туман войны `ExploredCellsModel::revealAround()`.
 *
 * Покрывает:
 *   - раскрытие 3×3-окна (центр + 8 соседей по Чебышёву) → 9 новых клеток;
 *   - идемпотентность (повторный вызов → 0 новых, дублей нет);
 *   - обрезка окна на углах карты (центр у (0,0) / (9,9) → 4 клетки; мир 0..999, не 1..1000 —
 *     регресс 03.09.2026: ряд Y=0 никогда не раскрывался);
 *   - частичное перекрытие (часть окна уже раскрыта → только новые);
 *   - изоляция по character_id (чужие раскрытые клетки не считаются);
 *   - перенос biome_id из map в explored_cells;
 *   - центр вне сетки → 0.
 *
 * @internal
 */
final class ExploredCellsRevealAroundTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    /** Не запускаем full migrations — создаём минимальную схему. */
    protected $migrate = false;

    private ExploredCellsModel $model;

    protected function setUp(): void
    {
        parent::setUp();

        $db = Database::connect('tests');
        $db->query('DROP TABLE IF EXISTS explored_cells');
        $db->query('DROP TABLE IF EXISTS map');

        $db->query('
            CREATE TABLE map (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cell_number INT NOT NULL,
                coordinate_x INT NOT NULL,
                coordinate_y INT NOT NULL,
                biome_id INT NOT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        $db->query('
            CREATE TABLE explored_cells (
                id INT AUTO_INCREMENT PRIMARY KEY,
                character_id INT NOT NULL,
                telegram_user_id INT NOT NULL,
                map_cell_id INT NOT NULL,
                biome_id INT NOT NULL,
                character_level INT NULL,
                cell_status VARCHAR(255) NULL,
                notes TEXT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');

        // Сетка 10×10 (coords 0..9 × 0..9 — как реальный мир начинается с 0), cell_number = y*10 + x + 1.
        // biome_id = 1 везде, кроме клетки (3,3) — biome_id = 7 (проверяем перенос).
        for ($y = 0; $y <= 9; $y++) {
            for ($x = 0; $x <= 9; $x++) {
                $cn     = $y * 10 + $x + 1;
                $biome  = ($x === 3 && $y === 3) ? 7 : 1;
                $db->query(
                    'INSERT INTO map (cell_number, coordinate_x, coordinate_y, biome_id) VALUES (?, ?, ?, ?)',
                    [$cn, $x, $y, $biome]
                );
            }
        }

        $this->model = new ExploredCellsModel();
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        $db->query('DROP TABLE IF EXISTS explored_cells');
        $db->query('DROP TABLE IF EXISTS map');
        parent::tearDown();
    }

    private function countFor(int $characterId): int
    {
        return Database::connect('tests')
            ->query('SELECT COUNT(*) AS c FROM explored_cells WHERE character_id = ?', [$characterId])
            ->getRowArray()['c'] ?? 0;
    }

    public function testRevealsFullThreeByThreeWindow(): void
    {
        $new = $this->model->revealAround(1, 1, 5, 5, 3);

        $this->assertSame(9, $new);
        $this->assertSame(9, (int) $this->countFor(1));

        // Раскрыты cell_number'ы (4..6)×(4..6): y*10+x+1.
        $expected = [];
        for ($y = 4; $y <= 6; $y++) {
            for ($x = 4; $x <= 6; $x++) {
                $expected[] = $y * 10 + $x + 1;
            }
        }
        sort($expected);

        $rows = Database::connect('tests')
            ->query('SELECT map_cell_id, biome_id, character_level FROM explored_cells WHERE character_id = 1 ORDER BY map_cell_id')
            ->getResultArray();
        $got = array_map(static fn ($r): int => (int) $r['map_cell_id'], $rows);
        $this->assertSame($expected, $got);

        foreach ($rows as $r) {
            $this->assertSame(1, (int) $r['biome_id']);
            $this->assertSame(3, (int) $r['character_level']);
        }
    }

    public function testIdempotentOnRepeat(): void
    {
        $this->assertSame(9, $this->model->revealAround(1, 1, 5, 5, 3));
        $this->assertSame(0, $this->model->revealAround(1, 1, 5, 5, 3));
        $this->assertSame(9, (int) $this->countFor(1));
    }

    public function testClampsAtTopLeftCorner(): void
    {
        // Центр (0,0) — настоящий угол мира, radius 1 → окно (0..1)×(0..1) = 4 клетки.
        // Ряд Y=0 и столбец X=0 обязаны раскрываться (баг «ордината 0 не отображается»).
        $new = $this->model->revealAround(1, 1, 0, 0, 5);

        $this->assertSame(4, $new);
        $this->assertSame(4, (int) $this->countFor(1));

        $expected = [1, 2, 11, 12]; // (0,0)=1 (1,0)=2 (0,1)=11 (1,1)=12
        $rows = Database::connect('tests')
            ->query('SELECT map_cell_id FROM explored_cells WHERE character_id = 1 ORDER BY map_cell_id')
            ->getResultArray();
        $this->assertSame($expected, array_map(static fn ($r): int => (int) $r['map_cell_id'], $rows));
    }

    public function testClampsAtBottomRightCorner(): void
    {
        // Центр (9,9) на сетке 0..9 → окно (8..9)×(8..9) = 4 клетки.
        $new = $this->model->revealAround(1, 1, 9, 9, 5);

        $this->assertSame(4, $new);
        $this->assertSame(4, (int) $this->countFor(1));
    }

    public function testPartialOverlapRevealsOnlyNew(): void
    {
        // Сначала угол (0,0) → 4 клетки: 1,2,11,12.
        $this->assertSame(4, $this->model->revealAround(1, 1, 0, 0, 1));
        // Затем центр (2,2) → окно (1..3)×(1..3) = 9 клеток, из них (1,1)=12 уже раскрыта.
        $new = $this->model->revealAround(1, 1, 2, 2, 1);
        $this->assertSame(8, $new);
        $this->assertSame(12, (int) $this->countFor(1));

        // Клетка (3,3) (cell_number = 34) должна иметь biome_id = 7.
        $row = Database::connect('tests')
            ->query('SELECT biome_id FROM explored_cells WHERE character_id = 1 AND map_cell_id = 34')
            ->getRowArray();
        $this->assertNotNull($row);
        $this->assertSame(7, (int) $row['biome_id']);
    }

    public function testIsolatedPerCharacter(): void
    {
        $this->assertSame(9, $this->model->revealAround(1, 1, 5, 5, 3));
        // Другой персонаж — те же клетки ещё не раскрыты для него.
        $this->assertSame(9, $this->model->revealAround(2, 2, 5, 5, 4));
        $this->assertSame(9, (int) $this->countFor(1));
        $this->assertSame(9, (int) $this->countFor(2));
    }

    public function testCenterOutsideGridRevealsNothing(): void
    {
        $this->assertSame(0, $this->model->revealAround(1, 1, 50, 50, 3));
        $this->assertSame(0, (int) $this->countFor(1));
    }

    public function testNullCharacterLevelAllowed(): void
    {
        $new = $this->model->revealAround(1, 1, 5, 5, null);
        $this->assertSame(9, $new);

        $row = Database::connect('tests')
            ->query('SELECT character_level FROM explored_cells WHERE character_id = 1 LIMIT 1')
            ->getRowArray();
        $this->assertNull($row['character_level']);
    }
}
