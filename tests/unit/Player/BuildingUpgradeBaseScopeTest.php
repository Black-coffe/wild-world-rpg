<?php

declare(strict_types=1);

namespace Tests\Unit\Player;

use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\ClaimedCellModel;
use App\Services\Bases\BaseScopeResolver;
use App\Services\Coverage\CommunicationTowerCoverageService;
use App\Services\Player\BuildingUpgrade\BuildingUpgradeApplier;
use App\Services\Player\BuildingUpgrade\BuildingUpgradeValidator;
use App\Services\Player\CharacterStatsService;
use App\Services\Player\PlayerStateService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use ReflectionProperty;

/**
 * angela-second-base-bugs-03 — ADR-102: апгрейд здания находил `character_buildings`
 * ПО ТИПУ (character_id + building_id), без `map_cell_id` и без `orderBy`. На второй
 * базе с тем же типом здания это либо ложно отказывало «уже 10 уровень» (первая
 * попавшаяся строка — чужая, максимальная), либо — хуже — `BuildingUpgradeApplier`
 * повышал уровень и списывал ресурсы у ЧУЖОЙ строки (первой базы), пока игрок стоял
 * на второй. Резолв базы теперь идёт через `ClaimedCellModel::resolveTargetBaseCell`
 * (тот же контракт, что у Demolish/Delete/Relocate).
 *
 * Приватные `bubs_`-таблицы (claimed_cells, character_buildings) — схема 1:1 с
 * `2024-05-23-061031_CreateClaimedCellsTable.php` / `2024-05-27-105534_CreateCharacterBuildingsTable.php`
 * (+ `..._AddLastTaxCollectedToCharacterBuildings.php`, + ENUM `building_type`
 * расширен `defensive`/`NOT NULL` вслед за `2026-05-20-600000_S26AddDefensiveStructures.php`),
 * без FK (см. паттерн `GenericBuildingCompletionBonusTest`) — изолированы от общих
 * таблиц, за которые дерутся параллельно работающие агенты этой волны.
 *
 * `PlayerStateService` (шаг 1 валидатора — «игрок на базе») и `CharacterStatsService`
 * (золото в Applier) — тестовые двойники: эта story не трогает ни один из этих двух
 * шагов (Non-goals), и im double изолирует тест от реальных таблиц `characters`.
 *
 * @internal
 */
final class BuildingUpgradeBaseScopeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    // public — читается анонимными подклассами моделей ниже (другая область видимости).
    public const T_CELLS     = 'bubs_claimed_cells';
    public const T_BUILDINGS = 'bubs_character_buildings';

    private \CodeIgniter\Database\BaseConnection $conn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->conn = Database::connect('tests');
        foreach ([self::T_BUILDINGS, self::T_CELLS] as $t) {
            $this->conn->query("DROP TABLE IF EXISTS {$t}");
        }

        // Схема — 1:1 с CreateClaimedCellsTable, без FK (character_id/map_cell_id ссылались
        // бы на характерс/map, которых в этом изолированном наборе нет).
        $this->conn->query('CREATE TABLE ' . self::T_CELLS . ' ('
            . 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, '
            . 'character_id INT UNSIGNED NOT NULL, '
            . 'map_cell_id INT UNSIGNED NOT NULL, '
            . 'claimed_at DATETIME NOT NULL, '
            . "status ENUM('active','abandoned') NOT NULL DEFAULT 'active'"
            . ') ENGINE=InnoDB');

        // Схема — 1:1 с CreateCharacterBuildingsTable + AddLastTaxCollectedToCharacterBuildings,
        // без FK.
        $this->conn->query('CREATE TABLE ' . self::T_BUILDINGS . ' ('
            . 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, '
            . 'character_id INT UNSIGNED NOT NULL, '
            . 'building_id INT UNSIGNED NOT NULL, '
            . 'faction_id INT UNSIGNED NULL, '
            . 'map_cell_id INT UNSIGNED NOT NULL, '
            . 'amount INT DEFAULT 1, '
            . 'character_level_during_construction INT NOT NULL, '
            . 'hp INT NOT NULL, '
            . 'level INT DEFAULT 1, '
            . 'built_at DATETIME NOT NULL, '
            . "building_type ENUM('military','residential','farming','resource','engineering','defensive') NOT NULL, "
            . 'tax INT NOT NULL, '
            . "`usage` ENUM('personal','collective','all') NULL, "
            . 'created_at DATETIME NULL, '
            . 'updated_at DATETIME NULL, '
            . 'last_tax_collected DATETIME NULL, '
            . "tax_collection_status ENUM('SUCCESS','FAILURE') DEFAULT 'SUCCESS', "
            . 'disappearance_date DATETIME NULL, '
            . 'usage_count INT NULL'
            . ') ENGINE=InnoDB');
    }

    protected function tearDown(): void
    {
        foreach ([self::T_BUILDINGS, self::T_CELLS] as $t) {
            $this->conn->query("DROP TABLE IF EXISTS {$t}");
        }
        parent::tearDown();
    }

    // ---- helpers ----

    private function claimedCellModel(): ClaimedCellModel
    {
        return new class () extends ClaimedCellModel {
            protected $table = BuildingUpgradeBaseScopeTest::T_CELLS;
        };
    }

    private function characterBuildingModel(): CharacterBuildingModel
    {
        return new class () extends CharacterBuildingModel {
            protected $table = BuildingUpgradeBaseScopeTest::T_BUILDINGS;
        };
    }

    private function playerStateServiceDouble(): PlayerStateService
    {
        // Шаг 1 («игрок на базе») — вне области этой story (Non-goals), поэтому всегда
        // проходит: тест бьёт по шагу 2 (резолв базы + поиск строки), не по шагу 1.
        return new class () extends PlayerStateService {
            public function __construct()
            {
            }

            public function isCharacterOnBase(int $characterId): bool
            {
                return true;
            }
        };
    }

    private function buildingModelDouble(): BuildingModel
    {
        return new class () extends BuildingModel {
            public function __construct()
            {
            }

            public function find($id = null)
            {
                return ['id' => $id, 'name_ru' => 'Мастерская', 'name_en' => '', 'hp' => 100];
            }
        };
    }

    private function statsServiceDouble(): CharacterStatsService
    {
        // Золото в этой story не трогаем (Non-goals) — double изолирует apply() от
        // реальной таблицы `characters`, которой в этом наборе нет.
        return new class () extends CharacterStatsService {
            public function adjust(int $characterId, array $deltas, array $boundsOverride = []): ?array
            {
                return null;
            }
        };
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

    private function seedBuilding(int $charId, int $buildingId, int $cell, int $level): int
    {
        $this->conn->table(self::T_BUILDINGS)->insert([
            'character_id'                         => $charId,
            'building_id'                           => $buildingId,
            'map_cell_id'                           => $cell,
            'amount'                                => 1,
            'character_level_during_construction'   => 1,
            'hp'                                     => 100,
            'level'                                  => $level,
            'built_at'                               => date('Y-m-d H:i:s'),
            'building_type'                          => 'residential',
            'tax'                                    => 10,
            'usage'                                  => 'personal',
        ]);
        return (int) $this->conn->insertID();
    }

    /**
     * `CommunicationTowerCoverageService` — вне правки этой story (Non-goals в
     * angela-second-base-bugs-07), и его собственный конструктор всегда заводит
     * РЕАЛЬНЫЙ `ClaimedCellModel` на непрефиксованную `claimed_cells` — таблицу,
     * которую в общей тест-БД держат/дропают десятки других тестов без изоляции
     * (`feedback_shared_table_schema_leaks_between_tests`). `BuildingUpgradeValidator`
     * доходит до него только веткой «≥2 активных баз, игрок не на своей» —
     * именно тем сценарием, который проверяет
     * `testAmbiguousBaseReturnsAgreedMessageAndDoesNotApply()`. Двойник исключает
     * этот сервис из теста так же, как остальные приватные `bubs_`-таблицы этого
     * файла изолируют его от общих.
     */
    private function coverageServiceDouble(): CommunicationTowerCoverageService
    {
        return new class () extends CommunicationTowerCoverageService {
            public function __construct()
            {
            }

            public function checkCoverage(int $characterId): array
            {
                return ['isCovered' => false];
            }
        };
    }

    private function validator(): BuildingUpgradeValidator
    {
        $validator = new BuildingUpgradeValidator(
            $this->characterBuildingModel(),
            $this->buildingModelDouble(),
            null, // resourceModel — реальный дефолт безвреден, requirements ниже без ресурсов
            $this->playerStateServiceDouble(),
            null, // resourcePool — реальный дефолт безвреден (ресурсов нет — не вызывается)
            $this->claimedCellModel(),
        );

        // `BuildingUpgradeValidator` строит `BaseScopeResolver` лениво и без
        // публичного сеттера (конструктор валидатора — контракт чужого теста,
        // менять нельзя, см. story 07 Implementation notes). Подменяем приватное
        // поле напрямую, той же префиксованной `claimedCellModel()`, что и сам
        // валидатор, плюс двойник вышки — без единой правки app/.
        $resolver = new BaseScopeResolver($this->claimedCellModel(), $this->coverageServiceDouble());
        $prop     = new ReflectionProperty(BuildingUpgradeValidator::class, 'baseScopeResolver');
        $prop->setAccessible(true);
        $prop->setValue($validator, $resolver);

        return $validator;
    }

    private function applier(): BuildingUpgradeApplier
    {
        return new BuildingUpgradeApplier(
            null,
            $this->characterBuildingModel(),
            null,
            null,
            null,
            $this->statsServiceDouble(),
        );
    }

    /** @return array<int,array<string,mixed>> nextLevel => requirements без стоимости */
    private function freeRequirements(): array
    {
        $req = [];
        for ($lvl = 2; $lvl <= 10; $lvl++) {
            $req[$lvl] = ['level' => 1, 'gold' => 0, 'resources' => []];
        }
        return $req;
    }

    private function rowLevel(int $rowId): int
    {
        $row = $this->conn->table(self::T_BUILDINGS)->where('id', $rowId)->get()->getRowArray();
        return (int) $row['level'];
    }

    // ---- AC: апгрейд на второй базе проходит, хотя на первой то же здание L10 ----

    public function testValidateOnSecondBaseIgnoresMaxedFirstBase(): void
    {
        $charId = 1001;
        $this->seedBase($charId, 10);
        $this->seedBase($charId, 20);
        $rowMaxed  = $this->seedBuilding($charId, 5, 10, 10);
        $rowSecond = $this->seedBuilding($charId, 5, 20, 1);

        $character = ['id' => $charId, 'level' => 10, 'gold' => 1000, 'cell_number' => 20];
        $result    = $this->validator()->validate($character, 5, $this->freeRequirements());

        $this->assertTrue($result['ok'], 'apгрейд на второй базе должен пройти валидацию');
        $this->assertSame($rowSecond, (int) $result['context']['charBuilding']['id']);
        $this->assertSame(1, $result['context']['currentLevel']);
        $this->assertSame(2, $result['context']['nextLevel']);
        // Строка первой (максимальной) базы к результату не притянута.
        $this->assertNotSame($rowMaxed, (int) $result['context']['charBuilding']['id']);
    }

    // ---- AC: после апгрейда меняется строка ТЕКУЩЕЙ базы, чужая — нет ----

    public function testApplyUpdatesOnlyCurrentBaseRowLeavingOtherBaseUntouched(): void
    {
        $charId = 1002;
        $this->seedBase($charId, 10);
        $this->seedBase($charId, 20);
        $rowFirst  = $this->seedBuilding($charId, 5, 10, 10);
        $rowSecond = $this->seedBuilding($charId, 5, 20, 1);

        $character = ['id' => $charId, 'level' => 10, 'gold' => 1000, 'cell_number' => 20];
        $result    = $this->validator()->validate($character, 5, $this->freeRequirements());
        $this->assertTrue($result['ok']);

        $ctx = $result['context'];
        $this->applier()->apply($character, $ctx['charBuilding'], $ctx['nextLevel'], $ctx['requirements']);

        $this->assertSame(2, $this->rowLevel($rowSecond), 'уровень поднялся у строки текущей (второй) базы');
        $this->assertSame(10, $this->rowLevel($rowFirst), 'строка первой базы НЕ изменена — порча данных исключена');
    }

    // ---- AC: отказ «максимум» приходит, когда 10 уровня достигла ИМЕННО текущая база ----

    public function testMaxLevelErrorWhenCurrentBaseBuildingIsMaxed(): void
    {
        $charId = 1003;
        $this->seedBase($charId, 10);
        $this->seedBase($charId, 20);
        $this->seedBuilding($charId, 5, 10, 1);  // первая база — только 1 уровень
        $this->seedBuilding($charId, 5, 20, 10); // текущая (вторая) база — максимум

        $character = ['id' => $charId, 'level' => 10, 'gold' => 1000, 'cell_number' => 20];
        $result    = $this->validator()->validate($character, 5, $this->freeRequirements());

        $this->assertFalse($result['ok']);
        $this->assertSame('Здание уже достигло максимального уровня (10).', $result['error']);
    }

    // ---- AC: «нет здания с ID=» приходит, когда на текущей базе его нет, хотя на другой есть ----

    public function testNoBuildingErrorWhenOnlyOtherBaseHasIt(): void
    {
        $charId = 1004;
        $this->seedBase($charId, 10);
        $this->seedBase($charId, 20);
        $this->seedBuilding($charId, 5, 10, 3); // только на первой базе

        $character = ['id' => $charId, 'level' => 10, 'gold' => 1000, 'cell_number' => 20];
        $result    = $this->validator()->validate($character, 5, $this->freeRequirements());

        $this->assertFalse($result['ok']);
        $this->assertSame('У вас нет здания с ID=5.', $result['error']);
    }

    // ---- AC: неоднозначная база — согласованный текст, ресурсы не списаны ----

    public function testAmbiguousBaseReturnsAgreedMessageAndDoesNotApply(): void
    {
        $charId = 1005;
        $this->seedBase($charId, 10);
        $this->seedBase($charId, 20);
        $rowFirst  = $this->seedBuilding($charId, 5, 10, 1);
        $rowSecond = $this->seedBuilding($charId, 5, 20, 1);

        // Персонаж не стоит ни на одной из двух активных баз (cell_number=99) —
        // resolveTargetBaseCell не может выбрать между ними.
        $character = ['id' => $charId, 'level' => 10, 'gold' => 1000, 'cell_number' => 99];
        $result    = $this->validator()->validate($character, 5, $this->freeRequirements());

        $this->assertFalse($result['ok']);
        $this->assertSame(
            'Баз у тебя несколько. Встань на ту базу, с которой работаешь, — и открой экран снова.',
            $result['error']
        );
        $this->assertSame(1, $this->rowLevel($rowFirst));
        $this->assertSame(1, $this->rowLevel($rowSecond));
    }

    // ---- AC: ask и confirm видят одну и ту же базу ----

    public function testAskAndConfirmResolveSameBase(): void
    {
        $charId = 1006;
        $this->seedBase($charId, 10);
        $this->seedBase($charId, 20);
        $this->seedBuilding($charId, 5, 10, 10);
        $rowSecond = $this->seedBuilding($charId, 5, 20, 1);

        $character = ['id' => $charId, 'level' => 10, 'gold' => 1000, 'cell_number' => 20];

        $ask     = $this->validator()->validate($character, 5, $this->freeRequirements());
        $confirm = $this->validator()->validate($character, 5, $this->freeRequirements());

        $this->assertTrue($ask['ok']);
        $this->assertTrue($confirm['ok']);
        $this->assertSame($rowSecond, (int) $ask['context']['charBuilding']['id']);
        $this->assertSame(
            (int) $ask['context']['charBuilding']['id'],
            (int) $confirm['context']['charBuilding']['id']
        );
    }

    // ---- AC: персонаж с одной базой — поведение не изменилось ----

    public function testSingleBaseCharacterBehaviorUnchanged(): void
    {
        $charId = 1007;
        $this->seedBase($charId, 30);
        $row = $this->seedBuilding($charId, 5, 30, 4);

        $character = ['id' => $charId, 'level' => 10, 'gold' => 1000, 'cell_number' => 30];
        $result    = $this->validator()->validate($character, 5, $this->freeRequirements());

        $this->assertTrue($result['ok']);
        $this->assertSame($row, (int) $result['context']['charBuilding']['id']);
        $this->assertSame(4, $result['context']['currentLevel']);
        $this->assertSame(5, $result['context']['nextLevel']);
    }
}
