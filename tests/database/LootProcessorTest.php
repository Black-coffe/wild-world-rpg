<?php

namespace Tests\Database;

use App\Services\Player\Death\LootProcessor;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * F2.8c — integration-тесты на DB-write методы LootProcessor.
 *
 * Покрывает все ветки записи в реальной MySQL:
 *   applyLosses(...)              — character_resources.decreaseQtyById +
 *                                   characters.gold -= lostGold
 *   applyCraftLosses(...)         — crafted_items_log update qty или delete
 *   transferResourcesToWinner(...) — character_resources upsert (insert/update)
 *   transferCraftToWinner(...)    — crafted_items_log upsert
 *   transferGoldToWinner(...)     — characters.gold += amount
 *
 * @internal
 */
final class LootProcessorTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    /** Не запускаем full migrations — создаём минимальную схему. */
    protected $migrate = false;

    private LootProcessor $proc;

    protected function setUp(): void
    {
        parent::setUp();

        $db = Database::connect('tests');

        $db->query('DROP TABLE IF EXISTS characters');
        $db->query('DROP TABLE IF EXISTS character_resources');
        $db->query('DROP TABLE IF EXISTS crafted_items_log');

        $db->query('
            CREATE TABLE characters (
                id INT AUTO_INCREMENT PRIMARY KEY,
                gold DECIMAL(15,2) NOT NULL DEFAULT 0,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        $db->query('
            CREATE TABLE character_resources (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_characters INT NOT NULL,
                id_resources INT NOT NULL,
                quantity INT NOT NULL DEFAULT 0,
                custom_data TEXT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        $db->query('
            CREATE TABLE crafted_items_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                character_id INT NOT NULL,
                crafted_item_id INT NOT NULL,
                task_id INT NULL,
                type VARCHAR(100) NULL,
                direction_craft VARCHAR(100) NULL,
                crafting_location VARCHAR(255) NULL,
                durability_count INT NULL,
                durability_time DATETIME NULL,
                quantity INT NOT NULL DEFAULT 0,
                custom_setting TEXT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');

        $this->proc = new LootProcessor();
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        $db->query('DROP TABLE IF EXISTS characters');
        $db->query('DROP TABLE IF EXISTS character_resources');
        $db->query('DROP TABLE IF EXISTS crafted_items_log');
        parent::tearDown();
    }

    // ---- applyLosses ----

    public function testApplyLossesDecreasesResourceQuantities(): void
    {
        $db = Database::connect('tests');
        $db->query('INSERT INTO characters (gold) VALUES (1000)');
        $loserId = (int) $db->insertID();

        $db->query('INSERT INTO character_resources (id_characters, id_resources, quantity) VALUES (?, ?, ?)', [$loserId, 5, 100]);
        $resA = (int) $db->insertID();
        $db->query('INSERT INTO character_resources (id_characters, id_resources, quantity) VALUES (?, ?, ?)', [$loserId, 7, 50]);
        $resB = (int) $db->insertID();

        $this->proc->applyLosses(
            $loserId,
            [
                ['charResId' => $resA, 'resourceId' => 5, 'lossAmount' => 30],
                ['charResId' => $resB, 'resourceId' => 7, 'lossAmount' => 20],
            ],
            0
        );

        $rowA = $db->query('SELECT quantity FROM character_resources WHERE id = ?', [$resA])->getRowArray();
        $rowB = $db->query('SELECT quantity FROM character_resources WHERE id = ?', [$resB])->getRowArray();
        $this->assertSame(70, (int) $rowA['quantity']);
        $this->assertSame(30, (int) $rowB['quantity']);
    }

    public function testApplyLossesDeletesRowWhenQuantityHitsZero(): void
    {
        $db = Database::connect('tests');
        $db->query('INSERT INTO characters (gold) VALUES (0)');
        $loserId = (int) $db->insertID();

        $db->query('INSERT INTO character_resources (id_characters, id_resources, quantity) VALUES (?, ?, ?)', [$loserId, 5, 50]);
        $resA = (int) $db->insertID();

        $this->proc->applyLosses(
            $loserId,
            [['charResId' => $resA, 'resourceId' => 5, 'lossAmount' => 50]],
            0
        );

        $row = $db->query('SELECT quantity FROM character_resources WHERE id = ?', [$resA])->getRowArray();
        $this->assertNull($row, 'Resource row should be deleted when qty <= 0');
    }

    public function testApplyLossesSubtractsGold(): void
    {
        $db = Database::connect('tests');
        $db->query('INSERT INTO characters (gold) VALUES (1000)');
        $loserId = (int) $db->insertID();

        $this->proc->applyLosses($loserId, [], 250);

        $row = $db->query('SELECT gold FROM characters WHERE id = ?', [$loserId])->getRowArray();
        $this->assertSame('750.00', $row['gold']);
    }

    public function testApplyLossesGoldClampedAtZero(): void
    {
        $db = Database::connect('tests');
        $db->query('INSERT INTO characters (gold) VALUES (50)');
        $loserId = (int) $db->insertID();

        // Loss больше чем gold — clamp на 0.
        $this->proc->applyLosses($loserId, [], 500);

        $row = $db->query('SELECT gold FROM characters WHERE id = ?', [$loserId])->getRowArray();
        $this->assertSame('0.00', $row['gold']);
    }

    public function testApplyLossesNoGoldChangeWhenLostGoldZero(): void
    {
        $db = Database::connect('tests');
        $db->query('INSERT INTO characters (gold) VALUES (333)');
        $loserId = (int) $db->insertID();

        $this->proc->applyLosses($loserId, [], 0);

        $row = $db->query('SELECT gold FROM characters WHERE id = ?', [$loserId])->getRowArray();
        $this->assertSame('333.00', $row['gold']);
    }

    // ---- applyCraftLosses ----

    public function testApplyCraftLossesUpdatesQuantity(): void
    {
        $db = Database::connect('tests');
        $db->query('INSERT INTO crafted_items_log (character_id, crafted_item_id, quantity) VALUES (?, ?, ?)', [1, 42, 10]);
        $logId = (int) $db->insertID();

        $this->proc->applyCraftLosses(1, [
            ['logId' => $logId, 'craftedItemId' => 42, 'lossAmount' => 3],
        ]);

        $row = $db->query('SELECT quantity FROM crafted_items_log WHERE id = ?', [$logId])->getRowArray();
        $this->assertSame(7, (int) $row['quantity']);
    }

    public function testApplyCraftLossesDeletesRowWhenQuantityHitsZero(): void
    {
        $db = Database::connect('tests');
        $db->query('INSERT INTO crafted_items_log (character_id, crafted_item_id, quantity) VALUES (?, ?, ?)', [1, 42, 5]);
        $logId = (int) $db->insertID();

        $this->proc->applyCraftLosses(1, [
            ['logId' => $logId, 'craftedItemId' => 42, 'lossAmount' => 5],
        ]);

        $row = $db->query('SELECT quantity FROM crafted_items_log WHERE id = ?', [$logId])->getRowArray();
        $this->assertNull($row);
    }

    public function testApplyCraftLossesIgnoresMissingRows(): void
    {
        $this->proc->applyCraftLosses(1, [
            ['logId' => 99999, 'craftedItemId' => 42, 'lossAmount' => 5],
        ]);
        // Просто не должно бросать — пройти silent через find()=null.
        $this->assertTrue(true);
    }

    // ---- transferResourcesToWinner ----

    public function testTransferResourcesInsertsNewRowForFreshResource(): void
    {
        $db = Database::connect('tests');
        $db->query('INSERT INTO characters (gold) VALUES (0)');
        $winnerId = (int) $db->insertID();

        // Победитель ещё не имеет этого ресурса — должна появиться новая строка.
        $transferred = $this->proc->transferResourcesToWinner(
            $winnerId,
            [['charResId' => 99, 'resourceId' => 5, 'lossAmount' => 100]],
            0.5
        );

        $this->assertSame([['resourceId' => 5, 'amount' => 50]], $transferred);

        $row = $db->query('SELECT quantity FROM character_resources WHERE id_characters = ? AND id_resources = ?', [$winnerId, 5])->getRowArray();
        $this->assertSame(50, (int) $row['quantity']);
    }

    public function testTransferResourcesAddsToExistingRow(): void
    {
        $db = Database::connect('tests');
        $db->query('INSERT INTO characters (gold) VALUES (0)');
        $winnerId = (int) $db->insertID();
        $db->query('INSERT INTO character_resources (id_characters, id_resources, quantity) VALUES (?, ?, ?)', [$winnerId, 5, 200]);

        $this->proc->transferResourcesToWinner(
            $winnerId,
            [['charResId' => 99, 'resourceId' => 5, 'lossAmount' => 80]],
            0.5
        );

        // 200 + floor(80*0.5) = 200 + 40 = 240
        $row = $db->query('SELECT quantity FROM character_resources WHERE id_characters = ? AND id_resources = ?', [$winnerId, 5])->getRowArray();
        $this->assertSame(240, (int) $row['quantity']);
    }

    public function testTransferResourcesSkipsZeroFloorAmount(): void
    {
        $db = Database::connect('tests');
        $db->query('INSERT INTO characters (gold) VALUES (0)');
        $winnerId = (int) $db->insertID();

        // floor(1 * 0.3) = 0 → skip.
        $transferred = $this->proc->transferResourcesToWinner(
            $winnerId,
            [['charResId' => 99, 'resourceId' => 5, 'lossAmount' => 1]],
            0.3
        );
        $this->assertSame([], $transferred);

        $row = $db->query('SELECT quantity FROM character_resources WHERE id_characters = ? AND id_resources = ?', [$winnerId, 5])->getRowArray();
        $this->assertNull($row);
    }

    // ---- transferCraftToWinner ----

    public function testTransferCraftInsertsNewLogRowWithLootMetadata(): void
    {
        $db = Database::connect('tests');

        $transferred = $this->proc->transferCraftToWinner(
            10,
            [['logId' => 0, 'craftedItemId' => 42, 'lossAmount' => 6]],
            0.5
        );
        $this->assertSame([['craftedItemId' => 42, 'amount' => 3]], $transferred);

        $row = $db->query('SELECT * FROM crafted_items_log WHERE character_id = 10 AND crafted_item_id = 42')->getRowArray();
        $this->assertSame(3, (int) $row['quantity']);
        $this->assertSame('loot', $row['type']);
        $this->assertSame('pvp_loot', $row['direction_craft']);
        $this->assertSame('battlefield', $row['crafting_location']);
    }

    public function testTransferCraftIncrementsExistingLogRow(): void
    {
        $db = Database::connect('tests');
        $db->query('INSERT INTO crafted_items_log (character_id, crafted_item_id, quantity) VALUES (?, ?, ?)', [10, 42, 5]);

        $this->proc->transferCraftToWinner(
            10,
            [['logId' => 0, 'craftedItemId' => 42, 'lossAmount' => 6]],
            0.5
        );

        // 5 + floor(6*0.5) = 5 + 3 = 8.
        $row = $db->query('SELECT quantity FROM crafted_items_log WHERE character_id = 10 AND crafted_item_id = 42')->getRowArray();
        $this->assertSame(8, (int) $row['quantity']);
    }

    // ---- transferGoldToWinner ----

    public function testTransferGoldAddsToWinner(): void
    {
        $db = Database::connect('tests');
        $db->query('INSERT INTO characters (gold) VALUES (1000)');
        $winnerId = (int) $db->insertID();

        $this->proc->transferGoldToWinner($winnerId, 250);

        $row = $db->query('SELECT gold FROM characters WHERE id = ?', [$winnerId])->getRowArray();
        $this->assertSame('1250.00', $row['gold']);
    }

    public function testTransferGoldNoOpForZeroAmount(): void
    {
        $db = Database::connect('tests');
        $db->query('INSERT INTO characters (gold) VALUES (1000)');
        $winnerId = (int) $db->insertID();

        $this->proc->transferGoldToWinner($winnerId, 0);

        $row = $db->query('SELECT gold FROM characters WHERE id = ?', [$winnerId])->getRowArray();
        $this->assertSame('1000.00', $row['gold']);
    }

    public function testTransferGoldNoOpForUnknownWinner(): void
    {
        // Не должно бросать — find() вернёт null.
        $this->proc->transferGoldToWinner(99999, 100);
        $this->assertTrue(true);
    }
}
