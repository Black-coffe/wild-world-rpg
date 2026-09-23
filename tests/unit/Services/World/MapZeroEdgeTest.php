<?php

declare(strict_types=1);

namespace Tests\Unit\Services\World;

use App\Models\ClaimedCellModel;
use App\Models\ExploredCellsModel;
use App\Models\MapModel;
use App\Models\NpcSpawnModel;
use App\Services\World\BiomePalette;
use App\Services\World\ExploredMapService;
use App\Services\World\TextMapService;
use CodeIgniter\Test\CIUnitTestCase;
use ReflectionClass;

/**
 * story bugs-info-0923-01 — «Ордината 0 не отображается».
 *
 * Мир — 0..999 по обеим осям. Картинка исследованного (`ExploredMapService`) зажимала окно
 * в 1..1000, поэтому ряд и столбец 0 не рисовались никогда. Текстовая сетка
 * (`TextMapService`) ряд 0 уже держит внутри мира — здесь это закреплено.
 *
 * Без БД: в `wildworld_tests` нет таблицы `map`. Картинка проверяется через вынесенные
 * `window()` / `drawImage()` сервиса, текстовая сетка — через подмену моделей reflection'ом
 * (паттерн TextMapMultiBaseTest).
 *
 * @internal
 */
final class MapZeroEdgeTest extends CIUnitTestCase
{
    private const BIOME = 1;

    private function biomeColor(): int
    {
        [$r, $g, $b] = BiomePalette::for(self::BIOME);

        return ($r << 16) | ($g << 8) | $b;
    }

    public function testWindowOverWholeWorldIsZeroTo999(): void
    {
        $service = new ExploredMapService();
        // Изученные клетки {(0,0), (999,999)} → bbox 0..999 по обеим осям.
        $window = $service->window(0, 999, 0, 999);

        $this->assertSame(['min_x' => 0, 'max_x' => 999, 'min_y' => 0, 'max_y' => 999], $window);
    }

    public function testCellZeroZeroIsPaintedInExploredColor(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD недоступен.');
        }

        $service = new ExploredMapService();
        $window  = $service->window(0, 999, 0, 999);
        $im      = $service->drawImage($window, [
            ['x' => 0, 'y' => 0, 'biome_id' => self::BIOME],
            ['x' => 999, 'y' => 999, 'biome_id' => self::BIOME],
        ]);

        $this->assertInstanceOf(\GdImage::class, $im);
        // 1000 клеток на сторону → 1 px на клетку, холст ровно 1000×1000 (не 1001, не 999).
        $this->assertSame(1000, imagesx($im));
        $this->assertSame(1000, imagesy($im));
        $this->assertSame($this->biomeColor(), imagecolorat($im, 0, 0), 'клетка (0,0) обязана быть закрашена');
        $this->assertSame($this->biomeColor(), imagecolorat($im, 999, 999), 'клетка (999,999) обязана быть закрашена');
        imagedestroy($im);
    }

    public function testWindowAtLeftEdgeKeepsColumnZeroAndNeverGoesNegative(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD недоступен.');
        }

        $service = new ExploredMapService();
        $window  = $service->window(0, 0, 500, 500);

        $this->assertSame(0, $window['min_x'], 'столбец x=0 потерян или окно ушло в минус');
        $this->assertSame(2, $window['max_x']);
        $this->assertSame(498, $window['min_y']);
        $this->assertSame(502, $window['max_y']);

        $im = $service->drawImage($window, [['x' => 0, 'y' => 500, 'biome_id' => self::BIOME]]);
        $this->assertInstanceOf(\GdImage::class, $im);

        // 5 клеток по большей стороне → 12 px на клетку; (0,500) → пиксель (0, 2*12).
        $this->assertSame($this->biomeColor(), imagecolorat($im, 0, 24));
        imagedestroy($im);
    }

    public function testTextGridDrawsRowYZeroAsWorldCellsForPlayerOnY1(): void
    {
        $characterId = 900;
        $playerCell  = 1500;
        // Игрок (500,1) → окно x:494..505, y:-5..6; ряд Y=0 — локальная строка 5.
        $mapRows = [['cell_number' => $playerCell, 'coordinate_x' => 500, 'coordinate_y' => 1, 'biome_id' => self::BIOME]];
        $explored = [];
        for ($x = 494; $x <= 505; $x++) {
            $cell       = 100000 + $x;
            $mapRows[]  = ['cell_number' => $cell, 'coordinate_x' => $x, 'coordinate_y' => 0, 'biome_id' => self::BIOME];
            $explored[] = ['map_cell_id' => $cell];
        }

        $service = $this->buildTextMap($mapRows, $explored);
        $mapText = $service->buildMapOnly(['id' => $characterId, 'cell_number' => $playerCell]);

        $lines = array_values(array_filter(
            explode("\n", str_replace(['⬛️', '🙎‍♂️'], ['.', 'P'], $mapText)),
            static fn (string $l): bool => $l !== ''
        ));
        $grid = array_map(static fn (string $l): array => mb_str_split($l), $lines);

        $this->assertCount(12, $grid);
        for ($row = 0; $row < 5; $row++) {
            $this->assertSame(array_fill(0, 12, '⬜'), $grid[$row], "ряд Y=" . ($row - 5) . ' — за краем мира');
        }
        $this->assertSame(array_fill(0, 12, '🌲'), $grid[5], 'ряд Y=0 обязан рисоваться клетками мира, а не «⬜»');
        $this->assertNotContains('⬜', $grid[6], 'ряд Y=1 (игрок) — внутри мира');
    }

    /**
     * @param list<array{cell_number:int,coordinate_x:int,coordinate_y:int,biome_id:int}> $mapRows
     * @param list<array{map_cell_id:int}>                                                 $explored
     */
    private function buildTextMap(array $mapRows, array $explored): TextMapService
    {
        $claimedCellModel = new class () extends ClaimedCellModel {
            public function __construct()
            {
            }

            public function findAllActiveCells(int $characterId): array
            {
                return [];
            }

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

            public function first()
            {
                $filter           = $this->cellFilter;
                $this->cellFilter = null;
                foreach ($this->rows as $row) {
                    if ($filter !== null && (int) $row['cell_number'] === $filter) {
                        return $row;
                    }
                }

                return null;
            }

            public function findAll(?int $limit = 0, int $offset = 0)
            {
                return $this->rows;
            }
        };

        $exploredCellsModel = new class ($explored) extends ExploredCellsModel {
            private array $rows;

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
                return $this;
            }

            public function whereIn(string $key, $values, ?bool $escape = null): self
            {
                return $this;
            }

            public function findAll(?int $limit = 0, int $offset = 0)
            {
                return $this->rows;
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
}
