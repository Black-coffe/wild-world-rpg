<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Buildings;

use App\Models\ClaimedCellModel;
use App\Services\Bases\BaseScopeResolver;
use App\Services\Coverage\CommunicationTowerCoverageService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * story angela-second-base-bugs-07 — `BaseScopeResolver` — единственное место, где
 * решается «с какой базой работает этот экран». Раньше `resolveTargetBaseCell()`
 * возвращал `null` и для «баз ≥2, игрок не на базе», и для «активных баз нет вообще»,
 * и все двери отвечали на оба состояния одним текстом — прямой ложью игроку без баз.
 *
 * Тест бьёт по ПОВЕДЕНИЮ на посеянных строках (своя схема, свой префикс таблиц),
 * а не по наличию подстроки в исходнике (`feedback_source_scan_tests_are_not_coverage`).
 * Покрыты все четыре исхода резолва: своя база / нет баз / вышка покрывает / неоднозначно —
 * плюс отдельно: заброшенная база не выбирается, даже если её `id` меньше активной.
 *
 * @internal
 */
final class BaseScopeResolverTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    // Приватная таблица теста — изолирована от общих `claimed_cells`, за которые
    // дерутся параллельно работающие агенты (паттерн `bubs_*` из
    // `BuildingUpgradeBaseScopeTest`), без FK.
    public const T_CELLS = 'bsr_claimed_cells';

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

    // ---- helpers ----

    private function claimedCellModel(): ClaimedCellModel
    {
        return new class () extends ClaimedCellModel {
            protected $table = BaseScopeResolverTest::T_CELLS;
        };
    }

    private function towerCoverage(bool $isCovered): CommunicationTowerCoverageService
    {
        return new class ($isCovered) extends CommunicationTowerCoverageService {
            public function __construct(private bool $isCovered)
            {
            }

            public function checkCoverage(int $characterId): array
            {
                return ['isCovered' => $this->isCovered];
            }

            /** multibase-picker-09: resolve() выбирает покрытую базу — покрыта первая посеянная. */
            public function coverageByBase(int $characterId, int $playerCell): array
            {
                $cells = new class () extends ClaimedCellModel {
                    protected $table = BaseScopeResolverTest::T_CELLS;
                };
                $out = [];
                foreach ($cells->findAllActiveCells($characterId) as $i => $base) {
                    $out[] = [
                        'base_id' => is_numeric($base['id'] ?? null) ? (int) $base['id'] : 0,
                        'cell' => is_numeric($base['map_cell_id'] ?? null) ? (int) $base['map_cell_id'] : 0,
                        'name' => '', 'x' => 0, 'y' => 0, 'towerLevel' => 1, 'distance' => 1, 'maxCoverage' => 100,
                        'isCovered' => $this->isCovered && $i === 0,
                    ];
                }

                return $out;
            }
        };
    }

    private function resolver(bool $towerCovers = false): BaseScopeResolver
    {
        return new BaseScopeResolver($this->claimedCellModel(), $this->towerCoverage($towerCovers));
    }

    private function seedBase(int $charId, int $cell, string $status = 'active'): int
    {
        $this->conn->table(self::T_CELLS)->insert([
            'character_id' => $charId,
            'map_cell_id'  => $cell,
            'claimed_at'   => date('Y-m-d H:i:s'),
            'status'       => $status,
        ]);

        return (int) $this->conn->insertID();
    }

    // ---- исход 1: игрок стоит на своей активной базе — она и возвращается ----

    public function testStandingOnOwnActiveBaseReturnsThatCell(): void
    {
        $charId = 2001;
        $this->seedBase($charId, 10);
        $this->seedBase($charId, 20);

        $scope = $this->resolver()->resolve($charId, 20);

        $this->assertSame(20, $scope['cell']);
        $this->assertNull($scope['reason']);
        $this->assertNull($scope['text']);
    }

    // ---- исход 2: активных баз нет вообще — честный отказ, не «баз несколько» ----

    public function testNoActiveBasesAtAllReturnsNoBasesReason(): void
    {
        $charId = 2002;

        $scope = $this->resolver()->resolve($charId, 500);

        $this->assertNull($scope['cell']);
        $this->assertSame(BaseScopeResolver::REASON_NO_BASES, $scope['reason']);
        $this->assertSame(BaseScopeResolver::TEXT_NO_BASES, $scope['text']);
        $this->assertStringNotContainsString('Встань на ту базу', $scope['text']);
    }

    // ---- исход 3: ≥2 баз, игрок не на своей, покрывает вышка — первая активная по id ----

    public function testTowerCoverageResolvesFirstActiveBaseById(): void
    {
        $charId = 2003;
        $firstId  = $this->seedBase($charId, 10);
        $secondId = $this->seedBase($charId, 20);
        $this->assertLessThan($secondId, $firstId);

        $scope = $this->resolver(towerCovers: true)->resolve($charId, 999);

        $this->assertSame(10, $scope['cell']);
        $this->assertNull($scope['reason']);
    }

    // ---- исход 4: ≥2 баз, игрок не на своей, вышка не покрывает — ambiguous, текст прежний ----

    public function testAmbiguousWithoutTowerCoverageKeepsPriorText(): void
    {
        $charId = 2004;
        $this->seedBase($charId, 10);
        $this->seedBase($charId, 20);

        $scope = $this->resolver(towerCovers: false)->resolve($charId, 999);

        $this->assertNull($scope['cell']);
        $this->assertSame(BaseScopeResolver::REASON_AMBIGUOUS, $scope['reason']);
        $this->assertSame(
            'Баз у тебя несколько. Встань на ту базу, с которой работаешь, — и открой экран снова.',
            $scope['text']
        );
    }

    // ---- заброшенная база не выбирается, даже если её id меньше активной ----

    public function testAbandonedBaseNeverChosenEvenWithLowerId(): void
    {
        $charId = 2005;
        $this->seedBase($charId, 10, 'abandoned'); // id меньше, но заброшена
        $activeId = $this->seedBase($charId, 20, 'active');
        $this->assertNotSame(0, $activeId);

        // Под вышкой — резолвер обязан выбрать активную (20), а не заброшенную (10).
        $scope = $this->resolver(towerCovers: true)->resolve($charId, 999);

        $this->assertSame(20, $scope['cell']);
    }

    // ---- одна активная база: поведение как до истории — резолв проходит даже без вышки ----

    public function testSingleActiveBaseResolvesWithoutTowerOrPresence(): void
    {
        $charId = 2006;
        $this->seedBase($charId, 30);

        $scope = $this->resolver(towerCovers: false)->resolve($charId, 777);

        $this->assertSame(30, $scope['cell']);
        $this->assertNull($scope['reason']);
    }
}
