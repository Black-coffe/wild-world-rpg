<?php

namespace Tests\Database;

use App\Models\ClaimedCellModel;
use App\TaskHandlers\Built\GenericBuildingCompletionHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use ReflectionMethod;

/**
 * ADR-102 — привязка постройка↔база в мультибэйс-мире.
 *
 * Ф0: завершение постройки привязывает здание к КОНКРЕТНОЙ базе (map_cell_id из
 *     task_settings.base_cell), а не схлопывает в единственную строку на тип.
 *     amount растёт только при повторной постройке на ТОЙ ЖЕ базе.
 * Ф1: resolveTargetBaseCell корректно выбирает базу для сноса/переезда.
 *
 * @internal
 */
final class BuildingBaseBindingTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    protected function setUp(): void
    {
        parent::setUp();

        $db = Database::connect('tests');
        foreach (['character_buildings', 'characters', 'character_factions', 'claimed_cells'] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }

        $db->query('
            CREATE TABLE characters (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cell_number INT NULL,
                level INT NULL DEFAULT 1,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        $db->query('
            CREATE TABLE character_factions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                character_id INT NULL,
                faction_id INT NULL
            )
        ');
        $db->query('
            CREATE TABLE character_buildings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                character_id INT NULL,
                building_id INT NULL,
                faction_id INT NULL,
                map_cell_id INT NULL,
                amount INT NULL DEFAULT 1,
                character_level_during_construction INT NULL,
                hp INT NULL,
                level INT NULL DEFAULT 1,
                built_at DATETIME NULL,
                building_type VARCHAR(32) NULL,
                tax INT NULL,
                `usage` VARCHAR(16) NULL,
                last_tax_collected DATETIME NULL,
                tax_collection_status VARCHAR(16) NULL,
                disappearance_date DATETIME NULL,
                usage_count INT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        $db->query('
            CREATE TABLE claimed_cells (
                id INT AUTO_INCREMENT PRIMARY KEY,
                character_id INT NULL,
                map_cell_id INT NULL,
                claimed_at DATETIME NULL,
                status VARCHAR(16) NULL DEFAULT "active"
            )
        ');
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (['character_buildings', 'characters', 'character_factions', 'claimed_cells'] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        parent::tearDown();
    }

    private function seedCharacter(int $id, int $cell, int $level = 3): void
    {
        Database::connect('tests')->table('characters')->insert([
            'id' => $id, 'cell_number' => $cell, 'level' => $level,
        ]);
    }

    private function seedBase(int $charId, int $cell, string $status = 'active'): void
    {
        Database::connect('tests')->table('claimed_cells')->insert([
            'character_id' => $charId, 'map_cell_id' => $cell,
            'claimed_at' => date('Y-m-d H:i:s'), 'status' => $status,
        ]);
    }

    /**
     * Вызывает приватный updateCharacterBuildings (минуя Telegram-notify в handle()).
     */
    private function completeBuild(int $charId, int $buildingId, int $baseCell): void
    {
        $handler    = new GenericBuildingCompletionHandler();
        $task       = [
            'character_id'  => $charId,
            'task_settings' => json_encode(['building' => 'HandPump', 'base_cell' => $baseCell]),
        ];
        $buildingRow = [
            'id' => $buildingId, 'hp' => 100, 'tax' => 300,
            'usage' => 'personal', 'building_type' => 'farming',
        ];
        $recipe = ['completion_building_type' => null];

        $m = new ReflectionMethod($handler, 'updateCharacterBuildings');
        $m->setAccessible(true);
        $m->invoke($handler, $task, $buildingRow, $recipe);
    }

    private function rows(int $charId, int $cell): array
    {
        return Database::connect('tests')->table('character_buildings')
            ->where('character_id', $charId)->where('map_cell_id', $cell)
            ->get()->getResultArray();
    }

    // ---- Ф0: per-base consolidation ----

    public function testSameBuildingSameBaseStacksAmount(): void
    {
        $this->seedCharacter(5, 100);
        $this->completeBuild(5, 10, 100);
        $this->completeBuild(5, 10, 100);

        $rows = $this->rows(5, 100);
        $this->assertCount(1, $rows, 'Та же постройка на той же базе — одна строка');
        $this->assertSame(2, (int) $rows[0]['amount'], 'amount++ при повторе на той же базе');
    }

    public function testSameBuildingDifferentBaseCreatesSeparateRow(): void
    {
        $this->seedCharacter(5, 100);
        $this->completeBuild(5, 10, 100);
        $this->completeBuild(5, 10, 200); // вторая база

        $this->assertCount(1, $this->rows(5, 100), 'База 100 — своя строка');
        $this->assertCount(1, $this->rows(5, 200), 'База 200 — отдельная строка (не схлопнулась в базу 100)');
        $this->assertSame(1, (int) $this->rows(5, 200)[0]['amount']);
    }

    public function testLegacyTaskWithoutBaseCellFallsBackToCharacterCell(): void
    {
        $this->seedCharacter(5, 100);
        // task без base_cell → fallback на cell_number персонажа (100)
        $handler = new GenericBuildingCompletionHandler();
        $task    = ['character_id' => 5, 'task_settings' => json_encode(['building' => 'HandPump'])];
        $buildingRow = ['id' => 10, 'hp' => 100, 'tax' => 300, 'usage' => 'personal', 'building_type' => 'farming'];
        $m = new ReflectionMethod($handler, 'updateCharacterBuildings');
        $m->setAccessible(true);
        $m->invoke($handler, $task, $buildingRow, ['completion_building_type' => null]);

        $this->assertCount(1, $this->rows(5, 100));
    }

    // ---- Ф1: per-base deletion semantics ----

    public function testPerBaseDeletionKeepsOtherBase(): void
    {
        $this->seedCharacter(5, 100);
        $this->completeBuild(5, 10, 100);
        $this->completeBuild(5, 11, 200);

        // Снос только базы 200 (как в DeleteBaseAction/relocation после ADR-102)
        Database::connect('tests')->table('character_buildings')
            ->where('character_id', 5)->where('map_cell_id', 200)->delete();

        $this->assertCount(1, $this->rows(5, 100), 'База 100 уцелела');
        $this->assertCount(0, $this->rows(5, 200), 'База 200 снесена');
    }

    // ---- Ф1: resolveTargetBaseCell ----

    public function testResolveReturnsCurrentBaseWhenStandingOnIt(): void
    {
        $this->seedBase(7, 100);
        $this->seedBase(7, 200);
        $this->assertSame(100, (new ClaimedCellModel())->resolveTargetBaseCell(7, 100));
    }

    public function testResolveReturnsSingleBaseWhenOffBase(): void
    {
        $this->seedBase(7, 100);
        // игрок не на базе (999), но база одна → она
        $this->assertSame(100, (new ClaimedCellModel())->resolveTargetBaseCell(7, 999));
    }

    public function testResolveNullWhenMultiBaseAndOffBase(): void
    {
        $this->seedBase(7, 100);
        $this->seedBase(7, 200);
        $this->assertNull((new ClaimedCellModel())->resolveTargetBaseCell(7, 999));
    }

    public function testResolveNullWhenNoBase(): void
    {
        $this->assertNull((new ClaimedCellModel())->resolveTargetBaseCell(7, 100));
    }

    public function testCountActiveBases(): void
    {
        $this->seedBase(7, 100);
        $this->seedBase(7, 200);
        $this->seedBase(7, 300, 'abandoned');
        $this->assertSame(2, (new ClaimedCellModel())->countActiveBases(7));
    }
}
