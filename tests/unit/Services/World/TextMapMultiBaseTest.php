<?php

declare(strict_types=1);

namespace Tests\Unit\Services\World;

use App\Models\ClaimedCellModel;
use App\Models\ExploredCellsModel;
use App\Models\MapModel;
use App\Models\NpcSpawnModel;
use App\Services\World\TextMapService;
use CodeIgniter\Test\CIUnitTestCase;
use ReflectionClass;

/**
 * story angela-second-base-bugs-01 — `TextMapService` перестаёт знать ровно одну базу
 * персонажа: 🏕 получает КАЖДАЯ активная база в видимом окне, строка расстояния считает
 * до БЛИЖАЙШЕЙ.
 *
 * Без БД (feedback_local_green_on_empty_test_db_proves_nothing): все модели, которые
 * сервис создаёт сам в конструкторе, подменены анонимными подклассами через reflection —
 * тот же паттерн, что TeleportUseValidatorBaseChoiceTest.
 *
 * @internal
 */
final class TextMapMultiBaseTest extends CIUnitTestCase
{
    /**
     * @param array<int, array<string,mixed>>            $activeBases claimed_cells-строки (map_cell_id, character_id, status='active')
     * @param array<int, array{cell_number:int,coordinate_x:int,coordinate_y:int}> $mapRows
     */
    private function buildService(array $activeBases, array $mapRows): TextMapService
    {
        $claimedCellModel = new class ($activeBases) extends ClaimedCellModel {
            /** @var array<int, array<string,mixed>> */
            private array $activeBases;

            public function __construct(array $activeBases)
            {
                $this->activeBases = $activeBases;
            }

            public function findAllActiveCells(int $characterId): array
            {
                return $this->activeBases;
            }

            // "чужие базы в регионе" — для этой истории всегда пусто, ветка не в скоупе.
            public function select($select = '*', ?bool $escape = null): self
            {
                return $this;
            }

            public function join(string $table, string $cond, string $type = '', ?bool $escape = null): self
            {
                return $this;
            }

            public function where($key = null, $value = null, ?bool $escape = null): self
            {
                return $this;
            }

            public function findAll(?int $limit = 0, int $offset = 0)
            {
                return [];
            }
        };

        $mapModel = new class ($mapRows) extends MapModel {
            /** @var array<int, array{cell_number:int,coordinate_x:int,coordinate_y:int}> */
            private array $rows;
            private ?int $cellFilter = null;

            public function __construct(array $rows)
            {
                $this->rows = $rows;
            }

            public function select($select = '*', ?bool $escape = null): self
            {
                return $this;
            }

            public function where($key = null, $value = null, ?bool $escape = null): self
            {
                if ($key === 'cell_number') {
                    $this->cellFilter = (int) $value;
                }
                return $this;
            }

            public function find($id = null)
            {
                foreach ($this->rows as $row) {
                    if ((int) $row['cell_number'] === (int) $id) {
                        return $row;
                    }
                }
                return null;
            }

            public function first()
            {
                $filter           = $this->cellFilter;
                $this->cellFilter = null;
                if ($filter === null) {
                    return null;
                }
                return $this->find($filter);
            }

            public function findAll(?int $limit = 0, int $offset = 0)
            {
                // mapData-запрос диапазона — своих rows достаточно, биом/разведка не
                // участвуют в проверке маркера базы.
                return $this->rows;
            }
        };

        $exploredCellsModel = new class () extends ExploredCellsModel {
            public function __construct()
            {
            }

            public function select($select = '*', ?bool $escape = null): self
            {
                return $this;
            }

            public function where($key = null, $value = null, ?bool $escape = null): self
            {
                return $this;
            }

            public function whereIn(string $key, $values, ?bool $escape = null): self
            {
                return $this;
            }

            public function findAll(?int $limit = 0, int $offset = 0)
            {
                return [];
            }
        };

        $npcSpawnModel = new class () extends NpcSpawnModel {
            public function __construct()
            {
            }

            public function where($key = null, $value = null, ?bool $escape = null): self
            {
                return $this;
            }

            public function findAll(?int $limit = 0, int $offset = 0)
            {
                return [];
            }
        };

        $ref     = new ReflectionClass(TextMapService::class);
        $service = $ref->newInstanceWithoutConstructor();

        foreach ([
            'claimedCellModel'   => $claimedCellModel,
            'mapModel'           => $mapModel,
            'exploredCellsModel' => $exploredCellsModel,
            'npcSpawnModel'      => $npcSpawnModel,
        ] as $prop => $mock) {
            $p = $ref->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($service, $mock);
        }

        return $service;
    }

    /**
     * Раскладывает текст карты на построчные массивы «клеток» (по 12 на строку).
     * Все клетки в этих тестах, кроме игрока/базы, рендерятся как «⬛️» (2 кодпоинта:
     * блок + variation selector) — свёрнуто в один символ-плейсхолдер до mb_str_split,
     * иначе позиционная индексация по колонкам съезжает.
     */
    private function grid(string $mapText): array
    {
        $normalized = str_replace(['⬛️', '🙎‍♂️'], ['.', 'P'], $mapText);
        $lines      = array_values(array_filter(explode("\n", $normalized), static fn ($l) => $l !== ''));
        return array_map(
            static fn (string $line) => mb_str_split($line),
            $lines,
        );
    }

    public function testBothActiveBasesInViewportAreMarked(): void
    {
        $characterId = 700;
        // Игрок (500,500) → окно x:494..505, y:494..505.
        $playerRow = ['cell_number' => 505000, 'coordinate_x' => 500, 'coordinate_y' => 500, 'biome_id' => 1];
        $baseARow  = ['cell_number' => 700001, 'coordinate_x' => 498, 'coordinate_y' => 498, 'biome_id' => 1];
        $baseBRow  = ['cell_number' => 700002, 'coordinate_x' => 503, 'coordinate_y' => 503, 'biome_id' => 1];

        $activeBases = [
            ['id' => 1, 'character_id' => $characterId, 'map_cell_id' => 700001, 'status' => 'active'],
            ['id' => 2, 'character_id' => $characterId, 'map_cell_id' => 700002, 'status' => 'active'],
        ];
        $service = $this->buildService($activeBases, [$playerRow, $baseARow, $baseBRow]);

        $character = ['id' => $characterId, 'cell_number' => 505000];
        $mapText   = $service->buildMapOnly($character);
        $grid      = $this->grid($mapText);

        // (498,498) → local (4,4); (503,503) → local (9,9).
        $this->assertSame('🏕', $grid[4][4], 'база A должна быть отмечена');
        $this->assertSame('🏕', $grid[9][9], 'база B должна быть отмечена');
        $this->assertSame(2, substr_count($mapText, '🏕'));
    }

    public function testBaseOutsideViewportAddsNoMarkerAndDoesNotBreakRendering(): void
    {
        $characterId = 701;
        $playerRow   = ['cell_number' => 505001, 'coordinate_x' => 500, 'coordinate_y' => 500, 'biome_id' => 1];
        // (600,600) далеко за пределами окна x:494..505, y:494..505.
        $farBaseRow = ['cell_number' => 701001, 'coordinate_x' => 600, 'coordinate_y' => 600, 'biome_id' => 1];

        $activeBases = [
            ['id' => 3, 'character_id' => $characterId, 'map_cell_id' => 701001, 'status' => 'active'],
        ];
        $service = $this->buildService($activeBases, [$playerRow, $farBaseRow]);

        $character = ['id' => $characterId, 'cell_number' => 505001];
        $mapText   = $service->buildMapOnly($character);

        $this->assertSame(0, substr_count($mapText, '🏕'));
        $this->assertNotSame('', $mapText);
    }

    public function testCharacterWithoutBasesHasNoMarkerOnMap(): void
    {
        $characterId = 702;
        $playerRow   = ['cell_number' => 505002, 'coordinate_x' => 500, 'coordinate_y' => 500, 'biome_id' => 1];
        $service     = $this->buildService([], [$playerRow]);

        $character = ['id' => $characterId, 'cell_number' => 505002];
        $mapText   = $service->buildMapOnly($character);

        $this->assertSame(0, substr_count($mapText, '🏕'));
    }

    public function testDistanceLineUsesNearestOfSeveralBases(): void
    {
        $characterId = 703;
        $playerRow   = ['cell_number' => 505003, 'coordinate_x' => 500, 'coordinate_y' => 500];
        // Ближняя база: dx=-2,dy=-2 → 2 хода. Дальняя: dx=3,dy=3 → 3 хода.
        $nearBaseRow = ['cell_number' => 703001, 'coordinate_x' => 498, 'coordinate_y' => 498];
        $farBaseRow  = ['cell_number' => 703002, 'coordinate_x' => 503, 'coordinate_y' => 503];

        $activeBases = [
            ['id' => 4, 'character_id' => $characterId, 'map_cell_id' => 703001, 'status' => 'active'],
            ['id' => 5, 'character_id' => $characterId, 'map_cell_id' => 703002, 'status' => 'active'],
        ];
        $service = $this->buildService($activeBases, [$playerRow, $nearBaseRow, $farBaseRow]);

        $character = ['id' => $characterId, 'cell_number' => 505003];
        $line      = $service->getDistanceLine($character);

        $this->assertSame("От 🙎‍♂️ до 🏕 = 2 ходов ↖️\n", $line);
    }

    public function testDistanceLineWithOneBaseMatchesPriorByteForByteFormat(): void
    {
        $characterId = 704;
        $playerRow   = ['cell_number' => 505004, 'coordinate_x' => 500, 'coordinate_y' => 500];
        $baseRow     = ['cell_number' => 704001, 'coordinate_x' => 498, 'coordinate_y' => 498];

        $activeBases = [
            ['id' => 6, 'character_id' => $characterId, 'map_cell_id' => 704001, 'status' => 'active'],
        ];
        $service = $this->buildService($activeBases, [$playerRow, $baseRow]);

        $character = ['id' => $characterId, 'cell_number' => 505004];
        $line      = $service->getDistanceLine($character);

        $this->assertSame("От 🙎‍♂️ до 🏕 = 2 ходов ↖️\n", $line);
    }

    public function testDistanceLineWithoutBasesReturnsEmptyString(): void
    {
        $characterId = 705;
        $playerRow   = ['cell_number' => 505005, 'coordinate_x' => 500, 'coordinate_y' => 500];
        $service     = $this->buildService([], [$playerRow]);

        $character = ['id' => $characterId, 'cell_number' => 505005];
        $line      = $service->getDistanceLine($character);

        $this->assertSame('', $line);
    }
}
