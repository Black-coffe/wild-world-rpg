<?php

namespace Tests\Database;

use App\Models\BiomeWorldObjectMapModel;
use App\TaskHandlers\Other\WorldObjectGeneratorHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * S21 (ROADMAP-CRAFT §8) — strategic-объекты теперь спавнятся.
 *
 * BEFORE: WorldObjectGeneratorHandler::generateObjectByType() switch имел case
 * только truck/toolkit/warehouse → `default: break` (no-op). Bunker/Technopark/
 * GhostCity, даже будучи status='active', НИКОГДА не попадали в
 * biome_world_object_map → discovery/+500/quest мертвы (ADR-027, прод-аудит
 * 2026-05-20: 0 спавнов за всё время).
 *
 * AFTER: generic generateStrategicObject() + GameSettings-driven cap.
 *
 * @internal
 */
final class StrategicObjectSpawnTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();

        $db = Database::connect('tests');
        $db->query('DROP TABLE IF EXISTS biome_world_object_map');
        $db->query('DROP TABLE IF EXISTS world_objects');
        $db->query('DROP TABLE IF EXISTS map');
        $db->query('DROP TABLE IF EXISTS game_settings');

        $db->query('
            CREATE TABLE map (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cell_number INT NOT NULL,
                biome_id INT NULL,
                coordinate_x INT NULL,
                coordinate_y INT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        $db->query('
            CREATE TABLE world_objects (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NULL,
                name_en VARCHAR(255) NULL,
                description TEXT NULL,
                biome_id VARCHAR(255) NULL,
                max_count INT NOT NULL DEFAULT 0,
                discovery_tools TEXT NULL,
                contents TEXT NULL,
                respawn_time INT NULL,
                status VARCHAR(32) NOT NULL DEFAULT "inactive",
                handler_key VARCHAR(64) NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        $db->query('
            CREATE TABLE biome_world_object_map (
                id INT AUTO_INCREMENT PRIMARY KEY,
                biome_id INT NULL,
                world_object_id INT NULL,
                map_id INT NULL,
                status VARCHAR(32) NULL,
                object_type VARCHAR(32) NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        $db->query('
            CREATE TABLE game_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(191) NOT NULL,
                category VARCHAR(64) NULL,
                value_type VARCHAR(16) NULL,
                value_int INT NULL,
                value_float DECIMAL(15,5) NULL,
                value_bool TINYINT NULL,
                value_string TEXT NULL,
                default_value_text TEXT NULL,
                rationale_text TEXT NULL,
                effect_text TEXT NULL,
                above_effect_text TEXT NULL,
                below_effect_text TEXT NULL,
                recommended_min VARCHAR(32) NULL,
                recommended_max VARCHAR(32) NULL,
                hard_min VARCHAR(32) NULL,
                hard_max VARCHAR(32) NULL,
                updated_by VARCHAR(64) NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');

        // 40 клеток в биоме 8 (Вулканические) — щедро для rare spawn.
        $cells = [];
        for ($i = 1; $i <= 40; $i++) {
            $cells[] = [
                'cell_number'  => 5000 + $i,
                'biome_id'     => 8,
                'coordinate_x' => $i,
                'coordinate_y' => $i,
            ];
        }
        $db->table('map')->insertBatch($cells);
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        $db->query('DROP TABLE IF EXISTS biome_world_object_map');
        $db->query('DROP TABLE IF EXISTS world_objects');
        $db->query('DROP TABLE IF EXISTS map');
        $db->query('DROP TABLE IF EXISTS game_settings');
        $this->cleanCache();
        parent::tearDown();
    }

    private function cleanCache(): void
    {
        if (function_exists('cache')) {
            $c = cache();
            if (is_object($c) && method_exists($c, 'clean')) {
                $c->clean();
            }
        }
    }

    private function seedBunker(int $maxCount, string $status = 'active'): int
    {
        $db = Database::connect('tests');
        $db->table('world_objects')->insert([
            'name'            => 'Бункер',
            'name_en'         => 'Bunker',
            'description'     => 'test bunker',
            'biome_id'        => json_encode(['8']),
            'max_count'       => $maxCount,
            'discovery_tools' => json_encode([['IronPickaxe' => '1']]),
            'contents'        => json_encode([['resources' => ['Stones' => '10']]]),
            'respawn_time'    => 24,
            'status'          => $status,
        ]);
        return (int) $db->insertID();
    }

    private function activeSpawnCount(int $worldObjectId): int
    {
        return (new BiomeWorldObjectMapModel())
            ->where('world_object_id', $worldObjectId)
            ->where('status', 'active')
            ->countAllResults();
    }

    public function testStrategicObjectSpawnsWhenActive(): void
    {
        $id = $this->seedBunker(3);

        (new WorldObjectGeneratorHandler())->handle();

        $this->assertSame(3, $this->activeSpawnCount($id), 'Bunker должен заспавниться (раньше default no-op → 0).');
    }

    public function testSpawnsLandInAllowedBiomeCells(): void
    {
        $id = $this->seedBunker(3);

        (new WorldObjectGeneratorHandler())->handle();

        $rows = (new BiomeWorldObjectMapModel())->where('world_object_id', $id)->findAll();
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame(8, (int) $row['biome_id'], 'Спавн обязан попасть в разрешённый биом (8).');
            $this->assertSame('single_use', $row['object_type']);
        }
    }

    public function testRespectsCapAndIsIdempotent(): void
    {
        $id = $this->seedBunker(3);

        $handler = new WorldObjectGeneratorHandler();
        $handler->handle();
        $handler->handle();
        $handler->handle();

        $this->assertSame(3, $this->activeSpawnCount($id), 'Повторный прогон не должен превышать cap.');
    }

    public function testGameSettingsOverridesColumnCap(): void
    {
        // Колонка max_count=5, но GameSettings говорит 2 → cap=2 (constitutional).
        $id = $this->seedBunker(5);

        Database::connect('tests')->table('game_settings')->insert([
            'setting_key' => 'world.strategic.bunker.max_spawns',
            'category'    => 'world',
            'value_type'  => 'int',
            'value_int'   => 2,
            'hard_min'    => '0',
            'hard_max'    => '200',
        ]);
        $this->cleanCache();

        (new WorldObjectGeneratorHandler())->handle();

        $this->assertSame(2, $this->activeSpawnCount($id), 'GameSettings cap (2) должен победить колонку max_count (5).');
    }

    public function testInactiveStrategicObjectDoesNotSpawn(): void
    {
        $id = $this->seedBunker(3, 'inactive');

        (new WorldObjectGeneratorHandler())->handle();

        $this->assertSame(0, $this->activeSpawnCount($id), 'Inactive объект не должен спавниться.');
    }
}
