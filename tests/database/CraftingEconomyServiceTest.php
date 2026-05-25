<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Economy\CraftingEconomyService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * V21 (ADR-053) — CraftingEconomyService: агрегаты экономики (summary, gold-concentration,
 * top crafted/resources, craft volume by month, CSV). Сидим минимальные таблицы.
 *
 * @internal
 */
final class CraftingEconomyServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    protected function setUp(): void
    {
        parent::setUp();
        $db  = Database::connect('tests');
        $now = date('Y-m-d H:i:s');

        foreach (['characters', 'crafted_items', 'crafted_items_log', 'resources', 'character_resources', 'transactions'] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('CREATE TABLE characters (id INT PRIMARY KEY, name VARCHAR(100), gold BIGINT)');
        $db->query('CREATE TABLE crafted_items (id INT PRIMARY KEY, name_rus VARCHAR(255), type VARCHAR(100))');
        $db->query('CREATE TABLE crafted_items_log (id INT AUTO_INCREMENT PRIMARY KEY, crafted_item_id INT, quantity INT, created_at DATETIME)');
        $db->query('CREATE TABLE resources (id INT PRIMARY KEY, name VARCHAR(255))');
        $db->query('CREATE TABLE character_resources (id INT AUTO_INCREMENT PRIMARY KEY, id_resources INT, quantity BIGINT)');
        $db->query('CREATE TABLE transactions (id INT AUTO_INCREMENT PRIMARY KEY, type VARCHAR(10), price DECIMAL(10,2), quantity INT, transaction_date DATETIME)');

        $db->table('characters')->insertBatch([
            ['id' => 1, 'name' => 'Alice', 'gold' => 1000],
            ['id' => 2, 'name' => 'Bob',   'gold' => 5000],
            ['id' => 3, 'name' => 'Carol', 'gold' => 0],
        ]);
        $db->table('crafted_items')->insertBatch([
            ['id' => 10, 'name_rus' => 'Меч',   'type' => 'weapon'],
            ['id' => 11, 'name_rus' => 'Зелье', 'type' => 'medicine'],
        ]);
        $db->table('crafted_items_log')->insertBatch([
            ['crafted_item_id' => 10, 'quantity' => 5, 'created_at' => $now],
            ['crafted_item_id' => 10, 'quantity' => 3, 'created_at' => $now],
            ['crafted_item_id' => 11, 'quantity' => 2, 'created_at' => $now],
        ]);
        $db->table('resources')->insertBatch([
            ['id' => 20, 'name' => 'Дерево'],
            ['id' => 21, 'name' => 'Камень'],
        ]);
        $db->table('character_resources')->insertBatch([
            ['id_resources' => 20, 'quantity' => 100],
            ['id_resources' => 20, 'quantity' => 50],
            ['id_resources' => 21, 'quantity' => 30],
        ]);
        $db->table('transactions')->insertBatch([
            ['type' => 'buy',  'price' => 100, 'quantity' => 1, 'transaction_date' => $now],
            ['type' => 'sell', 'price' => 80,  'quantity' => 1, 'transaction_date' => $now],
        ]);
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (['characters', 'crafted_items', 'crafted_items_log', 'resources', 'character_resources', 'transactions'] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        parent::tearDown();
    }

    public function testSummary(): void
    {
        $s = (new CraftingEconomyService())->summary();
        $this->assertSame(3, $s['players']);
        $this->assertSame(6000, $s['total_gold']);
        $this->assertSame(2000, $s['avg_gold']);
        $this->assertSame(3, $s['crafted_rows']);
        $this->assertSame(10, $s['crafted_qty']);
        $this->assertSame(180, $s['resource_qty']);
        $this->assertSame(2, $s['transactions']);
    }

    public function testGoldConcentration(): void
    {
        $gc = (new CraftingEconomyService())->goldConcentration(10);
        $this->assertSame(6000, $gc['total']);
        $this->assertSame('Bob', $gc['holders'][0]['name'], 'топ-холдер первым');
        $this->assertSame(5000, $gc['holders'][0]['gold']);
        $this->assertEqualsWithDelta(83.3, $gc['top_share_pct'], 0.1);
        // Carol (gold=0) исключена (WHERE gold>0): 2 холдера.
        $this->assertCount(2, $gc['holders']);
    }

    public function testTopCraftedItems(): void
    {
        $tc = (new CraftingEconomyService())->topCraftedItems(15);
        $this->assertSame('Меч', $tc[0]['name']);
        $this->assertSame(8, $tc[0]['qty']);
        $this->assertSame('Зелье', $tc[1]['name']);
        $this->assertSame(2, $tc[1]['qty']);
    }

    public function testTopResourcesHeld(): void
    {
        $tr = (new CraftingEconomyService())->topResourcesHeld(15);
        $this->assertSame('Дерево', $tr[0]['name']);
        $this->assertSame(150, $tr[0]['qty']);
    }

    public function testCraftVolumeByMonth(): void
    {
        $cv = (new CraftingEconomyService())->craftVolumeByMonth();
        $this->assertCount(1, $cv, 'все сидированы в текущем месяце');
        $this->assertSame(10, $cv[0]['qty']);
        $this->assertSame(3, $cv[0]['count']);
        $this->assertSame(date('Y-m'), $cv[0]['month']);
    }

    public function testTransactionsByMonth(): void
    {
        $tx = (new CraftingEconomyService())->transactionsByMonth();
        $this->assertCount(1, $tx);
        $this->assertSame(1, $tx[0]['buy']);
        $this->assertSame(1, $tx[0]['sell']);
    }

    public function testCsvRows(): void
    {
        $svc = new CraftingEconomyService();
        $this->assertCount(3, $svc->csvHeader());
        $rows = $svc->buildCsvRows();
        $this->assertNotEmpty($rows);
        // первая строка — период, вторая — сводка «Игроков».
        $this->assertSame('Период', $rows[0][0]);
        $this->assertSame(['Сводка', 'Игроков', '3'], $rows[1]);
    }
}
