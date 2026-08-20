<?php

declare(strict_types=1);

namespace Tests\Unit\Transport;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

// Файл миграции именован с датой-префиксом (CI4 convention) — не грузится по
// PSR-4 (класс≠файл), подключаем вручную, как и сама CI4 migration runner.
require_once APPPATH . 'Database/Migrations/2026-11-30-120000_SyncVehicleCatalogChargesFull.php';

use App\Database\Migrations\SyncVehicleCatalogChargesFull;

/**
 * transport-06.1 (ADR-174, `docs/specs/transport-system/`) —
 * `SyncVehicleCatalogChargesFull`: каталог `crafted_items.durability_count`
 * пяти машин синхронизирован с `world.vehicle.<key>.charges_full`, матч по
 * `name_eng` (не по id — см. rationale в самой миграции).
 *
 * Изолированная схема (как `VehicleActivationServiceTest`) — своя
 * `crafted_items` в `wildworld_tests`, не общая прод-схема.
 *
 * @internal
 */
final class VehicleCatalogChargesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private \CodeIgniter\Database\BaseConnection $conn;

    /** @var array<string, int> name_eng => старое (до миграции) значение */
    private const OLD_VALUES = [
        'LightCart'       => 120,
        'MountainBike'    => 150,
        'Snowmobile'      => 100,
        'DraftCart'       => 200,
        'AutonomousDrone' => 80,
    ];

    /** @var array<string, int> name_eng => новое (после миграции) значение */
    private const NEW_VALUES = [
        'LightCart'       => 300,
        'MountainBike'    => 350,
        'Snowmobile'      => 400,
        'DraftCart'       => 400,
        'AutonomousDrone' => 350,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->conn = Database::connect('tests');
        $this->conn->query('DROP TABLE IF EXISTS crafted_items');
        $this->conn->query('
            CREATE TABLE crafted_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name_eng VARCHAR(150) NULL,
                durability_count INT NULL
            )
        ');

        foreach (self::OLD_VALUES as $nameEng => $durability) {
            $this->conn->table('crafted_items')->insert([
                'name_eng'         => $nameEng,
                'durability_count' => $durability,
            ]);
        }
    }

    protected function tearDown(): void
    {
        $this->conn->query('DROP TABLE IF EXISTS crafted_items');
        parent::tearDown();
    }

    public function testUpSetsCatalogDurabilityToChargesFull(): void
    {
        (new SyncVehicleCatalogChargesFull())->up();

        foreach (self::NEW_VALUES as $nameEng => $expected) {
            $row = $this->conn->table('crafted_items')->where('name_eng', $nameEng)->get()->getRowArray();
            $this->assertNotNull($row, "row for {$nameEng} must exist");
            $this->assertSame($expected, (int) $row['durability_count'], "durability_count for {$nameEng}");
        }
    }

    public function testUpIsIdempotent(): void
    {
        $migration = new SyncVehicleCatalogChargesFull();
        $migration->up();
        $migration->up();

        foreach (self::NEW_VALUES as $nameEng => $expected) {
            $row = $this->conn->table('crafted_items')->where('name_eng', $nameEng)->get()->getRowArray();
            $this->assertSame($expected, (int) $row['durability_count'], "durability_count for {$nameEng} after double up()");
        }
    }

    public function testDownRestoresPreviousValues(): void
    {
        $migration = new SyncVehicleCatalogChargesFull();
        $migration->up();
        $migration->down();

        foreach (self::OLD_VALUES as $nameEng => $expected) {
            $row = $this->conn->table('crafted_items')->where('name_eng', $nameEng)->get()->getRowArray();
            $this->assertSame($expected, (int) $row['durability_count'], "durability_count for {$nameEng} after down()");
        }
    }

    public function testUpDoesNotFailWhenNameEngMissing(): void
    {
        $this->conn->table('crafted_items')->where('name_eng', 'DraftCart')->delete();

        (new SyncVehicleCatalogChargesFull())->up();

        $row = $this->conn->table('crafted_items')->where('name_eng', 'LightCart')->get()->getRowArray();
        $this->assertSame(300, (int) $row['durability_count']);

        $missing = $this->conn->table('crafted_items')->where('name_eng', 'DraftCart')->get()->getRowArray();
        $this->assertNull($missing);
    }

    public function testUpDoesNotTouchUnrelatedRows(): void
    {
        $this->conn->table('crafted_items')->insert(['name_eng' => 'Kayak', 'durability_count' => 42]);

        (new SyncVehicleCatalogChargesFull())->up();

        $row = $this->conn->table('crafted_items')->where('name_eng', 'Kayak')->get()->getRowArray();
        $this->assertSame(42, (int) $row['durability_count']);
    }
}
