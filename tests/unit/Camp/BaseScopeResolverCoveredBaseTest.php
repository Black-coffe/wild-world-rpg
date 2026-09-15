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
 * multibase-picker-09 (совет, раунд 1, ask 5 RED): ветка tower в `resolve()` берёт
 * первую по `id` базу, которую покрывает ЕЁ СОБСТВЕННАЯ Вышка, а не первую активную.
 * Остальные ветки и тексты — прежние байт в байт.
 *
 * Своя таблица `mbpc_claimed_cells`; покрытие — двойник `coverageByBase()`/`checkCoverage()`.
 *
 * @internal
 */
final class BaseScopeResolverCoveredBaseTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    public const T_CELLS = 'mbpc_claimed_cells';

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

    private function seedBase(int $charId, int $cell): int
    {
        $this->db()->table(self::T_CELLS)->insert([
            'character_id' => $charId, 'map_cell_id' => $cell,
            'claimed_at' => date('Y-m-d H:i:s'), 'status' => 'active',
        ]);

        return (int) $this->db()->insertID();
    }

    /**
     * @param array<int,int> $covered base_id => cell баз, чья Вышка покрывает игрока
     * @param array<int,int> $all     base_id => cell всех активных баз (по id)
     */
    private function resolver(array $all, array $covered): BaseScopeResolver
    {
        $cells = new class () extends ClaimedCellModel {
            protected $table = BaseScopeResolverCoveredBaseTest::T_CELLS;
        };
        $tower = new class ($all, $covered) extends CommunicationTowerCoverageService {
            /**
             * @param array<int,int> $all
             * @param array<int,int> $covered
             */
            public function __construct(private array $all, private array $covered)
            {
            }

            public function checkCoverage(int $characterId): array
            {
                return ['isCovered' => $this->covered !== []];
            }

            public function coverageByBase(int $characterId, int $playerCell): array
            {
                $out = [];
                foreach ($this->all as $id => $cell) {
                    $out[] = ['base_id' => $id, 'cell' => $cell, 'name' => '', 'x' => 0, 'y' => 0, 'towerLevel' => 1, 'distance' => 1, 'maxCoverage' => 100, 'isCovered' => isset($this->covered[$id])];
                }

                return $out;
            }
        };

        return new BaseScopeResolver($cells, $tower);
    }

    public function testOnlySecondBaseTowerCoversReturnsSecondBase(): void
    {
        $first  = $this->seedBase(1, 100);
        $second = $this->seedBase(1, 200);
        $all    = [$first => 100, $second => 200];

        $this->assertSame(
            ['cell' => 200, 'reason' => null, 'text' => null],
            $this->resolver($all, [$second => 200])->resolve(1, 555)
        );
    }

    public function testBothTowersCoverReturnsFirstBaseById(): void
    {
        $first  = $this->seedBase(1, 100);
        $second = $this->seedBase(1, 200);
        $all    = [$first => 100, $second => 200];

        $this->assertSame(
            ['cell' => 100, 'reason' => null, 'text' => null],
            $this->resolver($all, $all)->resolve(1, 555)
        );
    }

    public function testNoTowerCoversKeepsPriorAmbiguousAnswer(): void
    {
        $first  = $this->seedBase(1, 100);
        $second = $this->seedBase(1, 200);

        $this->assertSame(
            ['cell' => null, 'reason' => 'ambiguous', 'text' => 'Баз у тебя несколько. Встань на ту базу, с которой работаешь, — и открой экран снова.'],
            $this->resolver([$first => 100, $second => 200], [])->resolve(1, 555)
        );
    }

    public function testOtherBranchesAndTextsUnchanged(): void
    {
        $first  = $this->seedBase(1, 100);
        $second = $this->seedBase(1, 200);
        $all    = [$first => 100, $second => 200];

        $this->assertSame(['cell' => 200, 'reason' => null, 'text' => null], $this->resolver($all, [$first => 100])->resolve(1, 200), 'стоит на базе-2 — она, даже если покрывает база-1');
        $this->assertSame(
            ['cell' => null, 'reason' => 'no_bases', 'text' => 'Базы у тебя сейчас нет. Разбей лагерь — и постройки появятся на этом экране.'],
            $this->resolver([], [])->resolve(2, 555)
        );
        $this->assertSame('Базы у тебя сейчас нет. Разбей лагерь — и постройки появятся на этом экране.', BaseScopeResolver::TEXT_NO_BASES);
        $this->assertSame('Баз у тебя несколько. Встань на ту базу, с которой работаешь, — и открой экран снова.', BaseScopeResolver::TEXT_AMBIGUOUS);
    }
}
