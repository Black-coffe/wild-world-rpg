<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Player\VehicleActivationService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * F22 (спека s2-conditional-writes, story 07) — `VehicleActivationService::activate()`
 * принимает только предмет, который резолвится в ключ транспорта
 * (`VehicleEffectsService::keyForItemNameEng()`). Предмет владельца без ключа — `false`, без записи.
 *
 * @internal
 */
final class VehicleActivationNonTransportTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = ['characters', 'crafted_items_log', 'crafted_items'];

    private \CodeIgniter\Database\BaseConnection $conn;
    private VehicleActivationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->conn = Database::connect('tests');

        foreach (self::TABLES as $t) {
            $this->conn->query("DROP TABLE IF EXISTS {$t}");
        }

        $this->conn->query('
            CREATE TABLE characters (
                id INT AUTO_INCREMENT PRIMARY KEY,
                active_vehicle_log_id INT NULL
            )
        ');
        $this->conn->query('
            CREATE TABLE crafted_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name_eng VARCHAR(150) NULL,
                durability_count INT NULL
            )
        ');
        $this->conn->query('
            CREATE TABLE crafted_items_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                character_id INT NOT NULL,
                crafted_item_id INT NOT NULL,
                durability_count INT NULL,
                quantity INT NOT NULL DEFAULT 1
            )
        ');

        $this->service = new VehicleActivationService($this->conn);
    }

    public function testNonTransportItemIsRejectedWithoutWrite(): void
    {
        $character = $this->seedCharacter();
        $item      = $this->seedTemplate('Hammer', 100);
        $log       = $this->seedLog($character, $item, 100);

        $result = $this->service->activate($character, $log);

        $this->assertFalse($result, 'non-transport item was activated as a vehicle');
        $this->assertNull(
            $this->pointerOf($character),
            'non-transport item was activated as a vehicle'
        );
    }

    public function testRealTransportIsActivated(): void
    {
        $character = $this->seedCharacter();
        $item      = $this->seedTemplate('LightCart', 300);
        $log       = $this->seedLog($character, $item, 300);

        $result = $this->service->activate($character, $log);

        $this->assertTrue($result, 'non-transport item was activated as a vehicle');
        $this->assertSame($log, $this->pointerOf($character));
    }

    private function seedCharacter(): int
    {
        $this->conn->table('characters')->insert(['active_vehicle_log_id' => null]);

        return (int) $this->conn->insertID();
    }

    private function seedTemplate(string $nameEng, ?int $durability): int
    {
        $this->conn->table('crafted_items')->insert(['name_eng' => $nameEng, 'durability_count' => $durability]);

        return (int) $this->conn->insertID();
    }

    private function seedLog(int $characterId, int $craftedItemId, ?int $durabilityCount): int
    {
        $this->conn->table('crafted_items_log')->insert([
            'character_id'     => $characterId,
            'crafted_item_id'  => $craftedItemId,
            'durability_count' => $durabilityCount,
        ]);

        return (int) $this->conn->insertID();
    }

    private function pointerOf(int $characterId): ?int
    {
        $row = $this->conn->table('characters')->select('active_vehicle_log_id')->where('id', $characterId)->get()->getRowArray();

        return isset($row['active_vehicle_log_id']) ? (int) $row['active_vehicle_log_id'] : null;
    }
}
