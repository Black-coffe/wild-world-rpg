<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Events\ActiveDamageEventLookup;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Config\WorldEvents;

/**
 * ADR-200 (sudden-death-1125 S2) — какие активные события бьют по здоровью в биоме: от этого зависит, начнётся
 * ли добыча при низком здоровье. Глобальное событие (NULL `biome_ids`) покрывает каждый биом; события только на
 * выносливость (Песчаная буря) не считаются; завершённые и просроченные — тоже.
 *
 * @internal
 */
final class ActiveDamageEventLookupTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = ['events', 'active_events'];

    /** @var \CodeIgniter\Database\BaseConnection<object, object> */
    private $conn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->conn = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $this->conn->query("DROP TABLE IF EXISTS {$t}");
        }
        $this->conn->query('CREATE TABLE events (
            event_id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, name_english VARCHAR(255) NOT NULL,
            handler_key VARCHAR(64) NULL, description TEXT NULL, biome_ids TEXT NULL, img_path VARCHAR(255) NULL,
            effect_value INT NULL)');
        $this->conn->query("CREATE TABLE active_events (
            id INT AUTO_INCREMENT PRIMARY KEY, event_id INT NOT NULL, start_time DATETIME NULL, end_time DATETIME NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'active', effect_applied TINYINT NULL, effect_log TEXT NULL,
            notified_users TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)");
    }

    protected function tearDown(): void
    {
        foreach (self::TABLES as $t) {
            $this->conn->query("DROP TABLE IF EXISTS {$t}");
        }
        $this->conn->resetDataCache();
        parent::tearDown();
    }

    public function testDamageEventInTheBiomeIsFound(): void
    {
        $this->active($this->event('Подземные толчки', 'Tremor', '[3,5]'));

        $found = $this->lookup()->forBiome(3);

        $this->assertSame(['Подземные толчки'], array_column($found, 'name'));
        $this->assertSame([], $this->lookup()->forBiome(4), 'another biome is not covered');
    }

    public function testGlobalEventCoversEveryBiome(): void
    {
        $this->active($this->event('Ураган', 'Hurricane', null));
        $this->active($this->event('Радиоактивный туман', 'RadioactiveFog', ''));

        $this->assertSame(['Ураган', 'Радиоактивный туман'], array_column($this->lookup()->forBiome(9), 'name'));
    }

    public function testTiredOnlyAndNonDamageEventsDoNotCount(): void
    {
        $this->active($this->event('Песчаная буря', 'Sandstorm', '[2]'));
        $this->active($this->event('Засуха', 'Dryness', '[2]'));
        $this->active($this->event('Метеоритный дождь', 'MeteorRain', '[2]'));

        $this->assertSame([], $this->lookup()->forBiome(2));
    }

    public function testFeverCountsOnlyWhereItHurtsHealth(): void
    {
        $this->active($this->event('Лихорадка', 'Fever', '[2]'));

        $this->assertSame([], $this->lookup()->forBiome(2, 'dry'), 'dry biome: Fever drains stamina only');
        $this->assertCount(1, $this->lookup()->forBiome(2, 'wet'));
        $this->assertCount(1, $this->lookup()->forBiome(2, null), 'unknown type: default rule hurts health');
    }

    public function testFinishedOrExpiredEventsDoNotCount(): void
    {
        $tremor = $this->event('Подземные толчки', 'Tremor', '[3]');
        $this->active($tremor, 'completed');
        $this->active($tremor, 'active', time() - 60);

        $this->assertSame([], $this->lookup()->forBiome(3));
    }

    public function testHandlerKeyOverridesConfigKind(): void
    {
        $this->active($this->event('Странное', 'Tremor', '[3]', 'heal'));

        $this->assertSame([], $this->lookup()->forBiome(3), 'events.handler_key wins over WorldEvents effect_kind');
    }

    public function testOneEntryPerEvent(): void
    {
        $tremor = $this->event('Подземные толчки', 'Tremor', '[3]');
        $this->active($tremor);
        $this->active($tremor);

        $this->assertCount(1, $this->lookup()->forBiome(3));
    }

    public function testCoversBiomeRules(): void
    {
        $this->assertTrue(ActiveDamageEventLookup::coversBiome(null, 5));
        $this->assertTrue(ActiveDamageEventLookup::coversBiome('', 5));
        $this->assertTrue(ActiveDamageEventLookup::coversBiome('[]', 5));
        $this->assertTrue(ActiveDamageEventLookup::coversBiome('not json', 5));
        $this->assertTrue(ActiveDamageEventLookup::coversBiome('["5","6"]', 5));
        $this->assertFalse(ActiveDamageEventLookup::coversBiome('[6,7]', 5));
    }

    private function lookup(): ActiveDamageEventLookup
    {
        return new ActiveDamageEventLookup(new WorldEvents(), $this->conn);
    }

    private function event(string $name, string $nameEn, ?string $biomeIds, ?string $handlerKey = null): int
    {
        $this->conn->table('events')->insert(['name' => $name, 'name_english' => $nameEn, 'biome_ids' => $biomeIds, 'handler_key' => $handlerKey]);

        return (int) $this->conn->insertID();
    }

    private function active(int $eventId, string $status = 'active', ?int $endTs = null): void
    {
        $this->conn->table('active_events')->insert([
            'event_id'   => $eventId,
            'status'     => $status,
            'start_time' => date('Y-m-d H:i:s', time() - 600),
            'end_time'   => date('Y-m-d H:i:s', $endTs ?? time() + 3600),
        ]);
    }
}
