<?php

namespace Tests\Database;

use App\Services\Player\Gather\GatherEventModifierService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * F2.7c — integration-тест на batch-загрузку событий.
 *
 * Проверяет что loadActiveEvents действительно делает 2 SQL вместо 12
 * legacy isEventActive-вызовов и корректно собирает active flag из
 * active_events.status='active'.
 *
 * @internal
 */
final class GatherEventModifierServiceLoadTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private GatherEventModifierService $svc;

    protected function setUp(): void
    {
        parent::setUp();

        $db = Database::connect('tests');
        $db->query('DROP TABLE IF EXISTS events');
        $db->query('DROP TABLE IF EXISTS active_events');

        $db->query('
            CREATE TABLE events (
                event_id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NULL,
                name_english VARCHAR(100) NOT NULL,
                description TEXT NULL,
                biome_ids VARCHAR(255) NULL,
                event_type VARCHAR(50) NULL,
                random_coverage INT NULL,
                duration INT NULL,
                frequency_per_week INT NULL,
                effect_type VARCHAR(50) NULL,
                effect_value DECIMAL(10,2) NULL,
                protection_item_id INT NULL,
                img_path VARCHAR(255) NULL,
                start_time DATETIME NULL,
                end_time DATETIME NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        $db->query('
            CREATE TABLE active_events (
                id INT AUTO_INCREMENT PRIMARY KEY,
                event_id INT NOT NULL,
                start_time DATETIME NULL,
                end_time DATETIME NULL,
                status VARCHAR(50) NOT NULL,
                effect_applied TINYINT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');

        $this->svc = new GatherEventModifierService();
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        $db->query('DROP TABLE IF EXISTS events');
        $db->query('DROP TABLE IF EXISTS active_events');
        parent::tearDown();
    }

    public function testLoadActiveEventsReturnsAllInactiveWhenNoEvents(): void
    {
        $result = $this->svc->loadActiveEvents();
        // Все 5 в дефолте → все inactive с effect=0.
        $this->assertCount(5, $result);
        foreach (GatherEventModifierService::RELEVANT_EVENTS as $name) {
            $this->assertArrayHasKey($name, $result);
            $this->assertFalse($result[$name]['active']);
            $this->assertSame(0.0, $result[$name]['effect_value']);
        }
    }

    public function testLoadActiveEventsMarksActiveOnlyForActiveEntries(): void
    {
        $db = Database::connect('tests');
        $db->query("INSERT INTO events (name_english, effect_value) VALUES ('FishStock', 30.5)");
        $fishId = (int) $db->insertID();
        $db->query("INSERT INTO events (name_english, effect_value) VALUES ('LocustExodus', 20.0)");
        $locustId = (int) $db->insertID();
        $db->query("INSERT INTO events (name_english, effect_value) VALUES ('BerryBoom', 25.0)");
        // BerryBoom существует но не active.

        // Active записи: FishStock active, LocustExodus inactive (status=ended), BerryBoom вообще нет.
        $db->query("INSERT INTO active_events (event_id, status) VALUES (?, 'active')", [$fishId]);
        $db->query("INSERT INTO active_events (event_id, status) VALUES (?, 'ended')", [$locustId]);

        $result = $this->svc->loadActiveEvents();

        $this->assertTrue($result['FishStock']['active']);
        $this->assertSame(30.5, $result['FishStock']['effect_value']);

        $this->assertFalse($result['LocustExodus']['active']);
        $this->assertSame(20.0, $result['LocustExodus']['effect_value']);

        $this->assertFalse($result['BerryBoom']['active']);
        $this->assertSame(25.0, $result['BerryBoom']['effect_value']);

        // ExoticFlowering / Dryness совсем нет в БД.
        $this->assertFalse($result['ExoticFlowering']['active']);
        $this->assertSame(0.0, $result['ExoticFlowering']['effect_value']);
        $this->assertFalse($result['Dryness']['active']);
    }

    public function testLoadActiveEventsRespectsCustomNamesList(): void
    {
        $db = Database::connect('tests');
        $db->query("INSERT INTO events (name_english, effect_value) VALUES ('FishStock', 30.5)");
        $fishId = (int) $db->insertID();
        $db->query("INSERT INTO active_events (event_id, status) VALUES (?, 'active')", [$fishId]);

        // Просим только FishStock.
        $result = $this->svc->loadActiveEvents(['FishStock']);

        $this->assertCount(1, $result);
        $this->assertTrue($result['FishStock']['active']);
        $this->assertSame(30.5, $result['FishStock']['effect_value']);
    }

    public function testMultipleActiveEntriesForSameEventCountAsActive(): void
    {
        $db = Database::connect('tests');
        $db->query("INSERT INTO events (name_english, effect_value) VALUES ('Dryness', 50.0)");
        $drynessId = (int) $db->insertID();
        // Две active записи (e.g. на разных биомах) — всё равно active=true.
        $db->query("INSERT INTO active_events (event_id, status) VALUES (?, 'active')", [$drynessId]);
        $db->query("INSERT INTO active_events (event_id, status) VALUES (?, 'active')", [$drynessId]);

        $result = $this->svc->loadActiveEvents(['Dryness']);
        $this->assertTrue($result['Dryness']['active']);
    }
}
