<?php

namespace Tests\Database;

use App\Services\Player\Death\PlayerRespawner;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * F2.8c — integration-тесты на PlayerRespawner.
 *
 * Покрывает все ветки выбора respawn-клетки:
 *   - есть claimed_cell со status='active' → респавн на map_cell_id базы
 *   - нет базы → случайная клетка из map где Y∈[900..999], biome_id ∈ [1,2,3,6]
 *   - нет ни одной подходящей клетки → fallback cell_number = 1
 * Также покрывает hasActiveBase.
 *
 * @internal
 */
final class PlayerRespawnerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    /** Не запускаем full migrations — создаём минимальную схему. */
    protected $migrate = false;

    private PlayerRespawner $respawner;

    protected function setUp(): void
    {
        parent::setUp();

        $db = Database::connect('tests');
        foreach (['characters', 'claimed_cells', 'map', 'events', 'active_events'] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }

        $db->query('
            CREATE TABLE characters (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cell_number INT NOT NULL DEFAULT 1,
                last_respawn_at DATETIME NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        $db->query('
            CREATE TABLE claimed_cells (
                id INT AUTO_INCREMENT PRIMARY KEY,
                character_id INT NOT NULL,
                map_cell_id INT NOT NULL,
                status VARCHAR(50) NOT NULL,
                claimed_at DATETIME NULL
            )
        ');
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
        // batch 4 — cleanupAfterDeath() читает events.effect_type + чистит active_events.effect_log
        $db->query('
            CREATE TABLE events (
                event_id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NULL,
                name_english VARCHAR(255) NULL,
                effect_type VARCHAR(50) NULL,
                effect_value INT NULL,
                biome_ids TEXT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        $db->query('
            CREATE TABLE active_events (
                id INT AUTO_INCREMENT PRIMARY KEY,
                event_id INT NULL,
                start_time DATETIME NULL,
                end_time DATETIME NULL,
                status VARCHAR(50) NULL,
                effect_applied TINYINT NULL,
                effect_log TEXT NULL,
                notified_users TEXT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');

        $this->respawner = new PlayerRespawner();
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (['characters', 'claimed_cells', 'map', 'events', 'active_events'] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        parent::tearDown();
    }

    // ---- hasActiveBase ----

    public function testHasActiveBaseTrueWhenActiveClaim(): void
    {
        $db = Database::connect('tests');
        $db->query("INSERT INTO claimed_cells (character_id, map_cell_id, status) VALUES (?, ?, 'active')", [10, 5000]);

        $this->assertTrue($this->respawner->hasActiveBase(10));
    }

    public function testHasActiveBaseFalseWhenNoClaim(): void
    {
        $this->assertFalse($this->respawner->hasActiveBase(10));
    }

    public function testHasActiveBaseFalseWhenStatusInactive(): void
    {
        $db = Database::connect('tests');
        $db->query("INSERT INTO claimed_cells (character_id, map_cell_id, status) VALUES (?, ?, 'inactive')", [10, 5000]);

        $this->assertFalse($this->respawner->hasActiveBase(10));
    }

    public function testHasActiveBaseFalseForDifferentCharacter(): void
    {
        $db = Database::connect('tests');
        $db->query("INSERT INTO claimed_cells (character_id, map_cell_id, status) VALUES (?, ?, 'active')", [10, 5000]);

        $this->assertFalse($this->respawner->hasActiveBase(99));
    }

    // ---- respawn (с базой) ----

    public function testRespawnSendsToClaimedCellWhenBaseActive(): void
    {
        $db = Database::connect('tests');
        $db->query('INSERT INTO characters (cell_number) VALUES (1)');
        $charId = (int) $db->insertID();
        $db->query("INSERT INTO claimed_cells (character_id, map_cell_id, status) VALUES (?, ?, 'active')", [$charId, 7777]);

        $newCell = $this->respawner->respawn($charId);

        $this->assertSame(7777, $newCell);
        $row = $db->query('SELECT cell_number FROM characters WHERE id = ?', [$charId])->getRowArray();
        $this->assertSame(7777, (int) $row['cell_number']);
    }

    // ---- respawn (без базы — random Y∈[900..999], biome ∈ [1,2,3,6]) ----

    public function testRespawnPicksRandomEligibleCellWhenNoBase(): void
    {
        $db = Database::connect('tests');
        $db->query('INSERT INTO characters (cell_number) VALUES (1)');
        $charId = (int) $db->insertID();

        // 3 подходящие клетки + 2 неподходящие (Y вне диапазона / biome не в списке).
        $eligible = [
            ['cell_number' => 100, 'coordinate_x' => 10, 'coordinate_y' => 950, 'biome_id' => 1],
            ['cell_number' => 200, 'coordinate_x' => 20, 'coordinate_y' => 920, 'biome_id' => 2],
            ['cell_number' => 300, 'coordinate_x' => 30, 'coordinate_y' => 999, 'biome_id' => 6],
        ];
        foreach ($eligible as $c) {
            $db->query('INSERT INTO map (cell_number, coordinate_x, coordinate_y, biome_id) VALUES (?, ?, ?, ?)', array_values($c));
        }
        // НЕподходящие.
        $db->query('INSERT INTO map (cell_number, coordinate_x, coordinate_y, biome_id) VALUES (?, ?, ?, ?)', [400, 40, 800, 1]); // Y вне
        $db->query('INSERT INTO map (cell_number, coordinate_x, coordinate_y, biome_id) VALUES (?, ?, ?, ?)', [500, 50, 950, 4]); // biome не в списке

        $newCell = $this->respawner->respawn($charId);

        $this->assertContains($newCell, [100, 200, 300]);
        $row = $db->query('SELECT cell_number FROM characters WHERE id = ?', [$charId])->getRowArray();
        $this->assertSame($newCell, (int) $row['cell_number']);
    }

    public function testRespawnFallbacksToCellOneWhenNoEligibleCells(): void
    {
        $db = Database::connect('tests');
        $db->query('INSERT INTO characters (cell_number) VALUES (1)');
        $charId = (int) $db->insertID();
        // map пуст или только неподходящие клетки.
        $db->query('INSERT INTO map (cell_number, coordinate_x, coordinate_y, biome_id) VALUES (?, ?, ?, ?)', [400, 40, 800, 1]);

        $newCell = $this->respawner->respawn($charId);

        $this->assertSame(1, $newCell);
        $row = $db->query('SELECT cell_number FROM characters WHERE id = ?', [$charId])->getRowArray();
        $this->assertSame(1, (int) $row['cell_number']);
    }

    public function testRespawnPrefersClaimedCellOverRandomFallback(): void
    {
        // Если есть и активная база, и подходящие клетки в map — побеждает база.
        $db = Database::connect('tests');
        $db->query('INSERT INTO characters (cell_number) VALUES (1)');
        $charId = (int) $db->insertID();
        $db->query("INSERT INTO claimed_cells (character_id, map_cell_id, status) VALUES (?, ?, 'active')", [$charId, 8888]);
        $db->query('INSERT INTO map (cell_number, coordinate_x, coordinate_y, biome_id) VALUES (?, ?, ?, ?)', [100, 10, 950, 1]);

        $newCell = $this->respawner->respawn($charId);

        $this->assertSame(8888, $newCell);
    }

    public function testRespawnIgnoresInactiveClaimedCell(): void
    {
        $db = Database::connect('tests');
        $db->query('INSERT INTO characters (cell_number) VALUES (1)');
        $charId = (int) $db->insertID();
        // Статус НЕ active — не должны юзать map_cell_id.
        $db->query("INSERT INTO claimed_cells (character_id, map_cell_id, status) VALUES (?, ?, 'inactive')", [$charId, 8888]);
        $db->query('INSERT INTO map (cell_number, coordinate_x, coordinate_y, biome_id) VALUES (?, ?, ?, ?)', [100, 10, 950, 1]);

        $newCell = $this->respawner->respawn($charId);

        $this->assertSame(100, $newCell);
    }

    // ---- batch 4: «чистый респаун» — last_respawn_at + чистка effect_log негативных событий ----

    public function testRespawnSetsLastRespawnAtAndClearsNegativeEventLogs(): void
    {
        $db = Database::connect('tests');
        $db->query('INSERT INTO characters (cell_number) VALUES (1)');
        $charId = (int) $db->insertID();
        $key    = (string) $charId;

        // events: 1 = damage (негативное), 2 = debuff (негативное), 3 = heal (позитивное)
        $db->query("INSERT INTO events (event_id, name, name_english, effect_type, effect_value) VALUES (1,'Эпидемия','Epidemic','damage',5)");
        $db->query("INSERT INTO events (event_id, name, name_english, effect_type, effect_value) VALUES (2,'Слабость','Weakness','debuff',1)");
        $db->query("INSERT INTO events (event_id, name, name_english, effect_type, effect_value) VALUES (3,'Родник','Spring','heal',5)");

        // active_events:
        //  A — damage, active, в effect_log наш персонаж + чужой 999 → персонаж должен сняться, 999 остаться
        //  B — debuff, active, только наш персонаж → effect_log должен опустеть
        //  C — heal,   active, наш персонаж → НЕ трогаем (позитивное)
        //  D — damage, ended,  наш персонаж → НЕ трогаем (не active)
        $logA = json_encode([$key => ['health_delta' => -42], '999' => ['health_delta' => -3]]);
        $db->query("INSERT INTO active_events (event_id, status, effect_log) VALUES (1, 'active', ?)", [$logA]);
        $idA = (int) $db->insertID();
        $db->query("INSERT INTO active_events (event_id, status, effect_log) VALUES (2, 'active', ?)", [json_encode([$key => ['tired_delta' => -1]])]);
        $idB = (int) $db->insertID();
        $db->query("INSERT INTO active_events (event_id, status, effect_log) VALUES (3, 'active', ?)", [json_encode([$key => ['health_delta' => 5]])]);
        $idC = (int) $db->insertID();
        $db->query("INSERT INTO active_events (event_id, status, effect_log) VALUES (1, 'ended', ?)", [json_encode([$key => ['health_delta' => -7]])]);
        $idD = (int) $db->insertID();

        $this->respawner->respawn($charId);

        // last_respawn_at выставлен
        $charRow = $db->query('SELECT last_respawn_at FROM characters WHERE id = ?', [$charId])->getRowArray();
        $this->assertNotNull($charRow['last_respawn_at']);

        $logOf = static function (int $id) use ($db): array {
            $r = $db->query('SELECT effect_log FROM active_events WHERE id = ?', [$id])->getRowArray();
            $d = json_decode((string) ($r['effect_log'] ?? ''), true);
            return is_array($d) ? $d : [];
        };

        // A: персонаж снят, 999 остался
        $this->assertArrayNotHasKey($key, $logOf($idA));
        $this->assertArrayHasKey('999', $logOf($idA));
        // B: пусто
        $this->assertSame([], $logOf($idB));
        // C (heal): не тронут
        $this->assertArrayHasKey($key, $logOf($idC));
        // D (ended): не тронут
        $this->assertArrayHasKey($key, $logOf($idD));
    }
}
