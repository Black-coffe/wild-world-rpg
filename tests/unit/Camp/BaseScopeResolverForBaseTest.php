<?php

declare(strict_types=1);

namespace Tests\Unit\Camp;

use App\Models\ClaimedCellModel;
use App\Services\Bases\BaseScopeResolver;
use App\Services\Coverage\CommunicationTowerCoverageService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * story multibase-picker-01 — `BaseScopeResolver::resolveForBase()`: база из суффикса
 * callback'а проверяется заново — своя, активная, игрок на ней (`on_base`) или под
 * сигналом ЕЁ Вышки (`tower`), иначе `unavailable` с единым текстом.
 *
 * Своя таблица `mbpr_claimed_cells` (паттерн `BaseScopeResolverTest`); покрытие Вышки —
 * двойник `coverageByBase()` (сам расчёт покрытия — `CommunicationTowerCoverageByBaseTest`).
 *
 * @internal
 */
final class BaseScopeResolverForBaseTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    public const T_CELLS = 'mbpr_claimed_cells';

    private const TEXT = 'Эта база сейчас недоступна: встань на неё или подойди под сигнал её Вышки связи — и открой «🏠 База» снова.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->db()->query('DROP TABLE IF EXISTS ' . self::T_CELLS);
        $this->db()->query('CREATE TABLE ' . self::T_CELLS . ' ('
            . 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, '
            . 'character_id INT UNSIGNED NOT NULL, '
            . 'map_cell_id INT UNSIGNED NOT NULL, '
            . 'claimed_at DATETIME NOT NULL, '
            . "status ENUM('active','abandoned') NOT NULL DEFAULT 'active'"
            . ') ENGINE=InnoDB');
    }

    protected function tearDown(): void
    {
        $this->db()->query('DROP TABLE IF EXISTS ' . self::T_CELLS);
        parent::tearDown();
    }

    private function db(): BaseConnection
    {
        return Database::connect('tests');
    }

    private function seedBase(int $charId, int $cell, string $status = 'active'): int
    {
        $this->db()->table(self::T_CELLS)->insert([
            'character_id' => $charId, 'map_cell_id' => $cell,
            'claimed_at' => date('Y-m-d H:i:s'), 'status' => $status,
        ]);

        return (int) $this->db()->insertID();
    }

    /**
     * @param list<int> $coveredBaseIds базы, чья Вышка покрывает игрока
     */
    private function resolver(array $coveredBaseIds = []): BaseScopeResolver
    {
        $cells = new class () extends ClaimedCellModel {
            protected $table = BaseScopeResolverForBaseTest::T_CELLS;
        };
        $tower = new class ($coveredBaseIds) extends CommunicationTowerCoverageService {
            /** @param list<int> $covered */
            public function __construct(private array $covered)
            {
            }

            public function coverageByBase(int $characterId, int $playerCell): array
            {
                $out = [];
                foreach ($this->covered as $id) {
                    $out[] = ['base_id' => $id, 'cell' => 0, 'name' => '', 'x' => 0, 'y' => 0, 'towerLevel' => 1, 'distance' => 1, 'maxCoverage' => 100, 'isCovered' => true];
                }

                return $out;
            }
        };

        return new BaseScopeResolver($cells, $tower);
    }

    public function testPlayerOnSelectedBaseIsOnBase(): void
    {
        $this->seedBase(1, 100);
        $second = $this->seedBase(1, 200);

        $this->assertSame(
            ['cell' => 200, 'base_id' => $second, 'reason' => 'on_base', 'text' => ''],
            $this->resolver()->resolveForBase(1, 200, $second)
        );
    }

    public function testPlayerUnderSelectedBaseTowerIsTower(): void
    {
        $this->seedBase(1, 100);
        $second = $this->seedBase(1, 200);

        $this->assertSame(
            ['cell' => 200, 'base_id' => $second, 'reason' => 'tower', 'text' => ''],
            $this->resolver([$second])->resolveForBase(1, 555, $second)
        );
    }

    public function testCoverageOfAnotherBaseDoesNotUnlockSelectedOne(): void
    {
        $first  = $this->seedBase(1, 100);
        $second = $this->seedBase(1, 200);

        $result = $this->resolver([$first])->resolveForBase(1, 555, $second);

        $this->assertSame('unavailable', $result['reason']);
        $this->assertNull($result['cell']);
    }

    public function testForeignInactiveAndOutOfSignalAreUnavailableWithExactText(): void
    {
        $foreign   = $this->seedBase(2, 300);
        $abandoned = $this->seedBase(1, 400, 'abandoned');
        $own       = $this->seedBase(1, 500);

        $expected = ['cell' => null, 'base_id' => null, 'reason' => 'unavailable', 'text' => self::TEXT];

        $this->assertSame($expected, $this->resolver([$foreign])->resolveForBase(1, 300, $foreign), 'чужая база, даже стоя на ней');
        $this->assertSame($expected, $this->resolver([$abandoned])->resolveForBase(1, 400, $abandoned), 'заброшенная база');
        $this->assertSame($expected, $this->resolver()->resolveForBase(1, 999, $own), 'своя, но вне сигнала');
        $this->assertSame($expected, $this->resolver()->resolveForBase(1, 500, 987654), 'несуществующая база');
        $this->assertSame(self::TEXT, BaseScopeResolver::TEXT_UNAVAILABLE);
    }
}
