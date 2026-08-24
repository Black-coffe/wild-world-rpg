<?php

namespace Tests\Unit\Services\Player;

use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\CharacterModel;
use App\Models\ClaimedCellModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\MapModel;
use App\Services\BuildingEffects\BuildingEffectsService;
use App\Services\Player\TeleportCostService;
use App\Services\Player\TeleportUse\TeleportUseValidator;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * story backpack-teleport-base-choice-01 — TeleportUseValidator перестаёт брать
 * «первую попавшуюся» строку claimed_cells: перечисляет активные базы персонажа
 * и принимает явный claimedCellId; при 1 активной базе поведение прежнее, при
 * нескольких без id — choose_base.
 *
 * Моки моделей — как в TeleportCostServiceTest (без БД, feedback
 * feedback_local_green_on_empty_test_db_proves_nothing).
 *
 * @internal
 */
final class TeleportUseValidatorBaseChoiceTest extends CIUnitTestCase
{
    /**
     * @param array<int, array<string,mixed>> $claimedCells
     * @param array<int, array<string,mixed>> $mapRows
     */
    private function buildValidator(array $claimedCells, array $mapRows): TeleportUseValidator
    {
        $claimedCellModel = new class ($claimedCells) extends ClaimedCellModel {
            /** @var array<int, array<string,mixed>> */
            private array $rows;
            /** @var array<string, mixed> */
            private array $filters = [];
            private string $orderByKey = '';
            private string $orderDir  = 'ASC';

            public function __construct(array $rows)
            {
                $this->rows = $rows;
            }

            public function where($key = null, $value = null, ?bool $escape = null): self
            {
                $this->filters[(string) $key] = $value;
                return $this;
            }

            public function orderBy(string $orderBy, string $direction = '', ?bool $escape = null): self
            {
                $this->orderByKey = $orderBy;
                $this->orderDir   = $direction !== '' ? strtoupper($direction) : 'ASC';
                return $this;
            }

            /** @return array<int, array<string,mixed>> */
            private function matches(): array
            {
                $matches = [];
                foreach ($this->rows as $row) {
                    $match = true;
                    foreach ($this->filters as $k => $v) {
                        if (($row[$k] ?? null) !== $v) {
                            $match = false;
                            break;
                        }
                    }
                    if ($match) {
                        $matches[] = $row;
                    }
                }
                if ($this->orderByKey !== '') {
                    usort($matches, function ($a, $b) {
                        return ($a[$this->orderByKey] ?? 0) <=> ($b[$this->orderByKey] ?? 0);
                    });
                    if ($this->orderDir === 'DESC') {
                        $matches = array_reverse($matches);
                    }
                }
                return $matches;
            }

            public function first()
            {
                $matches       = $this->matches();
                $this->filters = [];
                return $matches[0] ?? null;
            }

            public function findAll(?int $limit = 0, int $offset = 0)
            {
                $matches       = $this->matches();
                $this->filters = [];
                return $matches;
            }

            public function countAllResults(bool $reset = true, bool $test = false)
            {
                $count          = count($this->matches());
                $this->filters  = [];
                return $count;
            }
        };

        $mapModel = new class ($mapRows) extends MapModel {
            /** @var array<int, array<string,mixed>> */
            private array $rows;
            /** @var array<string, mixed> */
            private array $filters = [];

            public function __construct(array $rows)
            {
                $this->rows = $rows;
            }

            public function where($key = null, $value = null, ?bool $escape = null): self
            {
                $this->filters[(string) $key] = $value;
                return $this;
            }

            public function first()
            {
                foreach ($this->rows as $row) {
                    $match = true;
                    foreach ($this->filters as $k => $v) {
                        if (($row[$k] ?? null) !== $v) {
                            $match = false;
                            break;
                        }
                    }
                    if ($match) {
                        $this->filters = [];
                        return $row;
                    }
                }
                $this->filters = [];
                return null;
            }
        };

        // Персонаж с достаточным опытом/золотом, чтобы дойти до findBaseLocation() во всех 4 ветках.
        $characterModel = new class extends CharacterModel {
            public function find($id = null, ?bool $singleton = true)
            {
                return ['id' => $id, 'level' => 1, 'gold' => 999999, 'experience' => 100.0];
            }
        };

        // Крафт-предметы: рюкзак/портатив всегда «есть» с достаточными зарядами.
        $craftedItemModel = new class extends CraftedItemsModel {
            public function where($key = null, $value = null, ?bool $escape = null): self
            {
                return $this;
            }

            public function first()
            {
                return ['id' => 1, 'name_eng' => 'item'];
            }
        };
        $craftedItemLogModel = new class extends CraftedItemsLogModel {
            public function where($key = null, $value = null, ?bool $escape = null): self
            {
                return $this;
            }

            public function first()
            {
                return ['id' => 1, 'crafted_item_id' => 1, 'quantity' => 5, 'durability_count' => 5, 'custom_setting' => null];
            }
        };

        // BuildingEffectsService без TeleportationCenter → baseline-стоимость, без реальной БД
        // (та же схема, что TeleportCostServiceTest::buildService с пустыми rows).
        $buildingModel = new class ([]) extends BuildingModel {
            public function __construct(array $rows = [])
            {
            }

            public function where($key = null, $value = null, ?bool $escape = null): self
            {
                return $this;
            }

            public function first()
            {
                return null;
            }
        };
        $charBuildingModel = new class ([]) extends CharacterBuildingModel {
            public function __construct(array $rows = [])
            {
            }

            public function where($key = null, $value = null, ?bool $escape = null): self
            {
                return $this;
            }

            public function orderBy(string $orderBy, string $direction = '', ?bool $escape = null): self
            {
                return $this;
            }

            public function first()
            {
                return null;
            }
        };
        $buildingEffects     = new BuildingEffectsService($charBuildingModel, $buildingModel, static fn (string $key, mixed $default): mixed => $default);
        $teleportCostService = new TeleportCostService($buildingEffects);

        return new TeleportUseValidator(
            $craftedItemModel,
            $craftedItemLogModel,
            $characterModel,
            $claimedCellModel,
            $mapModel,
            $teleportCostService,
        );
    }

    public function testSingleActiveBaseValidatesAllFourWaysAsBefore(): void
    {
        $characterId = 491;
        $claimedCells = [
            ['id' => 10, 'character_id' => $characterId, 'map_cell_id' => 100, 'status' => 'active', 'camp_name' => 'Дом'],
        ];
        $mapRows = [
            ['cell_number' => 100, 'coordinate_x' => 5, 'coordinate_y' => 5],
        ];
        $validator = $this->buildValidator($claimedCells, $mapRows);
        $character = ['id' => $characterId];

        $resultBackpack   = $validator->validateBackpack($character);
        $resultGold       = $validator->validateGold($character);
        $resultPortable   = $validator->validatePortable($character);
        $resultExperience = $validator->validateExperience($character);

        foreach ([$resultBackpack, $resultGold, $resultPortable, $resultExperience] as $result) {
            $this->assertTrue($result['ok']);
            $this->assertSame(10, $result['context']['claimedCell']['id']);
        }
    }

    public function testOnlyActiveBaseIsTargetedWhenAbandonedBaseExists(): void
    {
        $characterId = 491;
        $claimedCells = [
            ['id' => 1, 'character_id' => $characterId, 'map_cell_id' => 100, 'status' => 'abandoned', 'camp_name' => 'Старая'],
            ['id' => 2, 'character_id' => $characterId, 'map_cell_id' => 200, 'status' => 'active', 'camp_name' => 'Новая'],
        ];
        $mapRows = [
            ['cell_number' => 100, 'coordinate_x' => 1, 'coordinate_y' => 1],
            ['cell_number' => 200, 'coordinate_x' => 2, 'coordinate_y' => 2],
        ];
        $validator = $this->buildValidator($claimedCells, $mapRows);

        $result = $validator->validateBackpack(['id' => $characterId]);

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['context']['claimedCell']['id']);
    }

    public function testTwoActiveBasesWithoutIdReturnsChooseBaseWithoutSpendingAnything(): void
    {
        $characterId = 491;
        $claimedCells = [
            ['id' => 3, 'character_id' => $characterId, 'map_cell_id' => 300, 'status' => 'active', 'camp_name' => 'База 1'],
            ['id' => 4, 'character_id' => $characterId, 'map_cell_id' => 400, 'status' => 'active', 'camp_name' => 'База 2'],
        ];
        $mapRows = [
            ['cell_number' => 300, 'coordinate_x' => 3, 'coordinate_y' => 3],
            ['cell_number' => 400, 'coordinate_x' => 4, 'coordinate_y' => 4],
        ];
        $validator = $this->buildValidator($claimedCells, $mapRows);
        $character = ['id' => $characterId];

        foreach (['validateBackpack', 'validateGold', 'validatePortable', 'validateExperience'] as $method) {
            $result = $validator->{$method}($character);
            $this->assertFalse($result['ok'], "{$method} should fail without a chosen base");
            $this->assertSame('choose_base', $result['reason'] ?? null, "{$method} reason");
            $this->assertCount(2, $result['bases'] ?? []);
            $this->assertSame(3, $result['bases'][0]['id']);
            $this->assertSame(4, $result['bases'][1]['id']);
            $this->assertArrayNotHasKey('context', $result);
        }
    }

    public function testWrongOrAbandonedClaimedCellIdReturnsNoBase(): void
    {
        $characterId  = 491;
        $otherCharId  = 999;
        $claimedCells = [
            ['id' => 5, 'character_id' => $characterId, 'map_cell_id' => 500, 'status' => 'abandoned', 'camp_name' => 'Забро'],
            ['id' => 6, 'character_id' => $otherCharId, 'map_cell_id' => 600, 'status' => 'active', 'camp_name' => 'Чужая'],
        ];
        $mapRows = [
            ['cell_number' => 500, 'coordinate_x' => 5, 'coordinate_y' => 5],
            ['cell_number' => 600, 'coordinate_x' => 6, 'coordinate_y' => 6],
        ];
        $validator = $this->buildValidator($claimedCells, $mapRows);
        $character = ['id' => $characterId];

        $resultAbandoned = $validator->validateGold($character, 5);
        $resultForeign   = $validator->validateGold($character, 6);

        $this->assertFalse($resultAbandoned['ok']);
        $this->assertSame('no_base', $resultAbandoned['reason'] ?? null);
        $this->assertFalse($resultForeign['ok']);
        $this->assertSame('no_base', $resultForeign['reason'] ?? null);
    }

    public function testExplicitClaimedCellIdAmongMultipleBasesTargetsChosenOne(): void
    {
        $characterId = 491;
        $claimedCells = [
            ['id' => 7, 'character_id' => $characterId, 'map_cell_id' => 700, 'status' => 'active', 'camp_name' => 'База 1'],
            ['id' => 8, 'character_id' => $characterId, 'map_cell_id' => 800, 'status' => 'active', 'camp_name' => 'База 2'],
        ];
        $mapRows = [
            ['cell_number' => 700, 'coordinate_x' => 7, 'coordinate_y' => 7],
            ['cell_number' => 800, 'coordinate_x' => 8, 'coordinate_y' => 8],
        ];
        $validator = $this->buildValidator($claimedCells, $mapRows);

        $result = $validator->validateBackpack(['id' => $characterId], 8);

        $this->assertTrue($result['ok']);
        $this->assertSame(8, $result['context']['claimedCell']['id']);
    }

    public function testListActiveBasesReturnsOnlyActiveOrderedById(): void
    {
        $characterId = 491;
        $claimedCells = [
            ['id' => 2, 'character_id' => $characterId, 'map_cell_id' => 200, 'status' => 'active', 'camp_name' => 'Вторая'],
            ['id' => 1, 'character_id' => $characterId, 'map_cell_id' => 100, 'status' => 'active', 'camp_name' => 'Первая'],
            ['id' => 3, 'character_id' => $characterId, 'map_cell_id' => 300, 'status' => 'abandoned', 'camp_name' => 'Заброшена'],
        ];
        $mapRows = [
            ['cell_number' => 100, 'coordinate_x' => 1, 'coordinate_y' => 1],
            ['cell_number' => 200, 'coordinate_x' => 2, 'coordinate_y' => 2],
            ['cell_number' => 300, 'coordinate_x' => 3, 'coordinate_y' => 3],
        ];
        $validator = $this->buildValidator($claimedCells, $mapRows);

        $bases = $validator->listActiveBases($characterId);

        $this->assertCount(2, $bases);
        $this->assertSame(1, $bases[0]['id']);
        $this->assertSame(2, $bases[1]['id']);
        // story backpack-teleport-base-choice-04 (ревью №3) — координаты приходят
        // именно из мока MapModel (`cell_number` → `coordinate_x/coordinate_y`), а не
        // из claimed_cells.
        $this->assertSame(1, $bases[0]['coordinate_x']);
        $this->assertSame(1, $bases[0]['coordinate_y']);
        $this->assertSame(2, $bases[1]['coordinate_x']);
        $this->assertSame(2, $bases[1]['coordinate_y']);
    }
}
