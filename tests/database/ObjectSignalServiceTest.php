<?php

namespace Tests\Database;

use App\Services\World\ObjectSignalService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * ADR-098 — ObjectSignalService: радио-сигнал к ближайшему active strategic-объекту
 * в радиусе во время Похода.
 *
 * Покрывает: сигнал к ближайшему active strategic в радиусе (направление+дистанция);
 * игнор cleared / обычных (non-strategic) / вне радиуса; выбор БЛИЖАЙШЕГО среди
 * нескольких; killswitch; cache-кулдаун на пару (player, instance); 8-румбовый компас.
 * Telegram-доставка подменена стабом (protected sendSignal). Евклидова метрика.
 *
 * @internal
 */
final class ObjectSignalServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private int $bunkerId    = 0;
    private int $ghostCityId = 0;
    private int $toolkitId   = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();

        $db = Database::connect('tests');
        foreach (['biome_world_object_map', 'world_objects', 'map', 'game_settings'] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }

        $db->query('
            CREATE TABLE world_objects (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NULL, name_en VARCHAR(255) NULL, status VARCHAR(16) NULL
            )');
        $db->query('
            CREATE TABLE biome_world_object_map (
                id INT AUTO_INCREMENT PRIMARY KEY,
                world_object_id INT NULL, map_id INT NULL, status VARCHAR(16) NULL
            )');
        $db->query('
            CREATE TABLE map (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cell_number INT NOT NULL, coordinate_x INT NOT NULL, coordinate_y INT NOT NULL
            )');
        $db->query('
            CREATE TABLE game_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(191) NOT NULL, category VARCHAR(64) NULL,
                value_type VARCHAR(16) NULL, value_int INT NULL, value_float DECIMAL(15,5) NULL,
                value_bool TINYINT NULL, value_string TEXT NULL,
                hard_min VARCHAR(32) NULL, hard_max VARCHAR(32) NULL
            )');

        // strategic + один обычный (не-strategic)
        $db->table('world_objects')->insert(['name' => 'Бункер', 'name_en' => 'Bunker', 'status' => 'active']);
        $this->bunkerId = (int) $db->insertID();
        $db->table('world_objects')->insert(['name' => 'Город-призрак', 'name_en' => 'GhostCity', 'status' => 'active']);
        $this->ghostCityId = (int) $db->insertID();
        $db->table('world_objects')->insert(['name' => 'Набор инструментов', 'name_en' => 'Toolkit', 'status' => 'active']);
        $this->toolkitId = (int) $db->insertID();

        foreach ([
            ['world.signal.enabled', 'bool', ['value_bool' => 1]],
            ['world.signal.range_cells', 'int', ['value_int' => 20]],
            ['world.signal.cooldown_sec', 'int', ['value_int' => 600]],
        ] as [$key, $type, $val]) {
            $db->table('game_settings')->insert(array_merge([
                'setting_key' => $key, 'category' => 'world', 'value_type' => $type,
                'hard_min' => '0', 'hard_max' => '86400',
            ], $val));
        }
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (['biome_world_object_map', 'world_objects', 'map', 'game_settings'] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
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

    /** Размещает инстанс объекта на (x,y); возвращает id инстанса (bwom). */
    private function placeObject(int $worldObjectId, int $x, int $y, string $status = 'active'): int
    {
        $db   = Database::connect('tests');
        $cell = ($x * 1000) + $y;
        $db->table('map')->insert(['cell_number' => $cell, 'coordinate_x' => $x, 'coordinate_y' => $y]);
        $mapId = (int) $db->insertID();
        $db->table('biome_world_object_map')->insert([
            'world_object_id' => $worldObjectId, 'map_id' => $mapId, 'status' => $status,
        ]);
        return (int) $db->insertID();
    }

    private function service(): RecordingObjectSignal
    {
        return new RecordingObjectSignal();
    }

    public function testSignalsNearestActiveStrategicInRange(): void
    {
        $this->placeObject($this->bunkerId, 8, 5); // (8,5) от mover (5,5): dx=3,dy=0 → восток, dist=3

        $svc  = $this->service();
        $sent = $svc->signalNearbyObjects(1, 5, 5);

        $this->assertSame(1, $sent);
        $this->assertCount(1, $svc->signals);
        $this->assertSame('Бункер', $svc->signals[0]['name']);
        $this->assertSame('east', $svc->signals[0]['dir']);
        $this->assertSame(3, $svc->signals[0]['dist']);
    }

    public function testIgnoresClearedObject(): void
    {
        $this->placeObject($this->bunkerId, 8, 5, 'cleared');

        $svc = $this->service();
        $this->assertSame(0, $svc->signalNearbyObjects(1, 5, 5));
    }

    public function testIgnoresNonStrategicObject(): void
    {
        $this->placeObject($this->toolkitId, 8, 5); // Toolkit — НЕ strategic

        $svc = $this->service();
        $this->assertSame(0, $svc->signalNearbyObjects(1, 5, 5));
    }

    public function testIgnoresOutOfRange(): void
    {
        $this->placeObject($this->bunkerId, 40, 5); // dist=35 > range 20

        $svc = $this->service();
        $this->assertSame(0, $svc->signalNearbyObjects(1, 5, 5));
    }

    public function testIgnoresObjectOnSameCell(): void
    {
        $this->placeObject($this->bunkerId, 5, 5); // distSq=0 → это discovery, не сигнал

        $svc = $this->service();
        $this->assertSame(0, $svc->signalNearbyObjects(1, 5, 5));
    }

    public function testPicksNearestAmongMultiple(): void
    {
        $this->placeObject($this->ghostCityId, 5, 11); // dist=6
        $this->placeObject($this->bunkerId, 8, 5);     // dist=3 ← ближе

        $svc  = $this->service();
        $sent = $svc->signalNearbyObjects(1, 5, 5);

        $this->assertSame(1, $sent);
        $this->assertSame('Бункер', $svc->signals[0]['name']);
        $this->assertSame(3, $svc->signals[0]['dist']);
    }

    public function testKillswitchOffNoSignal(): void
    {
        Database::connect('tests')->table('game_settings')
            ->where('setting_key', 'world.signal.enabled')
            ->update(['value_bool' => 0]);
        $this->placeObject($this->bunkerId, 8, 5);

        $svc = $this->service();
        $this->assertSame(0, $svc->signalNearbyObjects(1, 5, 5));
    }

    public function testCooldownSuppressesSecondSignal(): void
    {
        $this->placeObject($this->bunkerId, 8, 5);

        $svc = $this->service();
        $this->assertSame(1, $svc->signalNearbyObjects(1, 5, 5));
        // Повтор по той же паре (player 1, этот инстанс) — на кулдауне.
        $this->assertSame(0, $svc->signalNearbyObjects(1, 5, 5));
    }

    public function testCompassAllDirections(): void
    {
        $svc = $this->service();
        // dy: ось Y растёт на ЮГ (north => отрицательная dy)
        $this->assertSame('north', $svc->compass(0, -5));
        $this->assertSame('south', $svc->compass(0, 5));
        $this->assertSame('east', $svc->compass(5, 0));
        $this->assertSame('west', $svc->compass(-5, 0));
        $this->assertSame('northeast', $svc->compass(5, -5));
        $this->assertSame('northwest', $svc->compass(-5, -5));
        $this->assertSame('southeast', $svc->compass(5, 5));
        $this->assertSame('southwest', $svc->compass(-5, 5));
        // Доминирование одной оси → кардинальное направление
        $this->assertSame('east', $svc->compass(10, 2));
        $this->assertSame('south', $svc->compass(2, 10));
    }
}

/**
 * Подменяет Telegram-доставку на запись (вся остальная логика — настоящая).
 *
 * @internal
 */
final class RecordingObjectSignal extends ObjectSignalService
{
    /** @var list<array{name:string, dir:string, dist:int, range:int}> */
    public array $signals = [];

    protected function sendSignal(int $moverId, string $objectName, string $dir, int $dist, int $range): bool
    {
        $this->signals[] = ['name' => $objectName, 'dir' => $dir, 'dist' => $dist, 'range' => $range];
        return true;
    }
}
