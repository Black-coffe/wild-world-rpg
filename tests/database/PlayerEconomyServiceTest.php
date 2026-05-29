<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Economy\PlayerEconomyService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * W24 (ADR-079) — PlayerEconomyService: net worth (gold + ресурсы×sell_price +
 * крафт×price), топ-холдинги по ценности, сводка торговли (transactions, price=итог).
 *
 * @internal
 */
final class PlayerEconomyServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = ['characters', 'character_resources', 'resources', 'crafted_items_log', 'crafted_items', 'transactions', 'character_factions', 'factions'];

    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('CREATE TABLE characters (id INT AUTO_INCREMENT PRIMARY KEY, gold BIGINT NULL)');
        $db->query('CREATE TABLE resources (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NULL, sell_price DECIMAL(10,2) NULL)');
        $db->query('CREATE TABLE character_resources (id INT AUTO_INCREMENT PRIMARY KEY, id_characters INT NULL, id_resources INT NULL, quantity BIGINT NULL)');
        $db->query('CREATE TABLE crafted_items (id INT AUTO_INCREMENT PRIMARY KEY, name_rus VARCHAR(255) NULL, price DECIMAL(10,2) NULL)');
        $db->query('CREATE TABLE crafted_items_log (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NULL, crafted_item_id INT NULL, quantity INT NULL)');
        $db->query("CREATE TABLE transactions (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NULL, crafted_item_id INT NULL, type VARCHAR(8) NULL, quantity INT NULL, price DECIMAL(10,2) NULL)");

        // Ресурсы: A (sell 10), B (sell 4).
        $db->table('resources')->insert(['id' => 1, 'name' => 'Руда', 'sell_price' => 10.00]);
        $db->table('resources')->insert(['id' => 2, 'name' => 'Древесина', 'sell_price' => 4.00]);
        // Крафт-предмет (price 100).
        $db->table('crafted_items')->insert(['id' => 5, 'name_rus' => 'Кирка', 'price' => 100.00]);

        // Игрок 1: gold 1000, 50×Руда(=500) + 25×Древесина(=100) = res 600; 3×Кирка(=300) craft.
        $db->table('characters')->insert(['id' => 1, 'gold' => 1000]);
        $db->table('character_resources')->insert(['id_characters' => 1, 'id_resources' => 1, 'quantity' => 50]);
        $db->table('character_resources')->insert(['id_characters' => 1, 'id_resources' => 2, 'quantity' => 25]);
        $db->table('crafted_items_log')->insert(['character_id' => 1, 'crafted_item_id' => 5, 'quantity' => 3]);
        // Торговля: продал на 800, купил на 300 (price = итог строки).
        $db->table('transactions')->insert(['character_id' => 1, 'crafted_item_id' => 5, 'type' => 'sell', 'quantity' => 8, 'price' => 800.00]);
        $db->table('transactions')->insert(['character_id' => 1, 'crafted_item_id' => 5, 'type' => 'buy', 'quantity' => 3, 'price' => 300.00]);

        // Игрок 2: gold NULL, без ресурсов/крафта/торговли.
        $db->table('characters')->insert(['id' => 2, 'gold' => null]);

        // W25 comparison: фракции + ещё чары (gold-only net worth для предсказуемости).
        $db->query('CREATE TABLE factions (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NULL)');
        $db->query('CREATE TABLE character_factions (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NULL, faction_id INT NULL)');
        $db->table('factions')->insert(['id' => 1, 'name' => 'Тестовая']);
        $db->table('factions')->insert(['id' => 2, 'name' => 'Малая']);
        // Чары 3-6 (gold-only nw): 5000/3000/10000/500.
        foreach ([3 => 5000, 4 => 3000, 5 => 10000, 6 => 500] as $id => $g) {
            $db->table('characters')->insert(['id' => $id, 'gold' => $g]);
        }
        // Фракция 1 = 5 членов (1,3,4,5,6) → faction-сравнение показывается.
        foreach ([1, 3, 4, 5, 6] as $cid) {
            $db->table('character_factions')->insert(['character_id' => $cid, 'faction_id' => 1]);
        }
        // Фракция 2 = 1 член (char 2) → faction-строка скрыта (< min 5).
        $db->table('character_factions')->insert(['character_id' => 2, 'faction_id' => 2]);
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        parent::tearDown();
    }

    public function testNetWorthBreakdown(): void
    {
        $r = (new PlayerEconomyService())->report(1);
        $this->assertSame(1000, $r['gold']);
        $this->assertSame(600, $r['resources_value']);   // 50×10 + 25×4
        $this->assertSame(300, $r['crafted_value']);      // 3×100
        $this->assertSame(1900, $r['net_worth']);         // 1000+600+300
    }

    public function testTopResourcesOrderedByValue(): void
    {
        $r = (new PlayerEconomyService())->report(1);
        $this->assertSame('Руда', $r['top_resources'][0]['name']); // 500 > 100
        $this->assertSame(500, $r['top_resources'][0]['value']);
        $this->assertSame('Древесина', $r['top_resources'][1]['name']);
    }

    public function testTradeSummary(): void
    {
        $r = (new PlayerEconomyService())->report(1);
        $this->assertNotNull($r['trade']);
        $this->assertSame(800, $r['trade']['sold']);
        $this->assertSame(300, $r['trade']['bought']);
        $this->assertSame(500, $r['trade']['profit']); // 800-300
        $this->assertSame(2, $r['trade']['count']);
    }

    public function testEmptyCharNullGoldAndNoTrade(): void
    {
        $r = (new PlayerEconomyService())->report(2);
        $this->assertSame(0, $r['gold']);            // NULL → 0
        $this->assertSame(0, $r['net_worth']);
        $this->assertSame([], $r['top_resources']);
        $this->assertNull($r['trade']);               // не торговал
    }

    // ── W25 (ADR-080) — сравнение «на фоне выживших» ──

    public function testServerComparisonPercentileAndMedian(): void
    {
        // Net worth: char1=1900, char2=0, char3=5000, char4=3000, char5=10000, char6=500.
        // sorted: [0,500,1900,3000,5000,10000] → медиана (n=6) = (1900+3000)/2 = 2450.
        $cmp = (new PlayerEconomyService())->comparison(1);
        $this->assertNotNull($cmp);
        $this->assertSame(1900, $cmp['net_worth']);
        $this->assertSame(6, $cmp['server']['count']);
        $this->assertSame(4, $cmp['server']['rank']);            // 3 богаче (5000,3000,10000) +1
        $this->assertSame(40, $cmp['server']['richer_than_pct']); // 2 беднее /(6-1)=40%
        $this->assertSame(2450, $cmp['server']['median']);
    }

    public function testFactionComparisonShownWhenEnoughMembers(): void
    {
        // Фракция 1 = 5 членов (1,3,4,5,6) nw [1900,5000,3000,10000,500] → median 3000.
        $cmp = (new PlayerEconomyService())->comparison(1);
        $this->assertNotNull($cmp['faction']);
        $this->assertSame('Тестовая', $cmp['faction']['name']);
        $this->assertSame(5, $cmp['faction']['count']);
        $this->assertSame(4, $cmp['faction']['rank']);   // в фракции 3 богаче +1
        $this->assertSame(3000, $cmp['faction']['median']);
    }

    public function testFactionComparisonHiddenWhenTooFewMembers(): void
    {
        // Фракция 2 = 1 член (char 2) < min 5 → faction null; server всё равно есть.
        $cmp = (new PlayerEconomyService())->comparison(2);
        $this->assertNotNull($cmp);
        $this->assertNull($cmp['faction']);
        $this->assertSame(6, $cmp['server']['count']);
        $this->assertSame(6, $cmp['server']['rank']);    // nw 0 — беднейший
    }

    public function testComparisonNullForUnknownChar(): void
    {
        $this->assertNull((new PlayerEconomyService())->comparison(999));
    }
}
