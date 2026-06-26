<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Player\ResourceOverviewService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * Slice 1 (инициатива «ресурс-грамотность») — ResourceOverviewService: единый срез
 * всех мест хранения (добыча/крафт/оружие/броня/склад базы/золото) + net worth
 * (gold + добыча×sell_price + крафт×price + склад×sell_price).
 *
 * @internal
 */
final class ResourceOverviewServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = [
        'characters', 'resources', 'character_resources',
        'crafted_items', 'crafted_items_log',
        'characters_weapons', 'characters_outfits', 'base_storage',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('CREATE TABLE characters (id INT AUTO_INCREMENT PRIMARY KEY, gold BIGINT NULL)');
        $db->query('CREATE TABLE resources (id INT AUTO_INCREMENT PRIMARY KEY, sell_price DECIMAL(10,2) NULL)');
        $db->query('CREATE TABLE character_resources (id INT AUTO_INCREMENT PRIMARY KEY, id_characters INT NULL, id_resources INT NULL, quantity BIGINT NULL)');
        $db->query('CREATE TABLE crafted_items (id INT AUTO_INCREMENT PRIMARY KEY, price DECIMAL(10,2) NULL)');
        $db->query('CREATE TABLE crafted_items_log (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NULL, crafted_item_id INT NULL, quantity INT NULL)');
        $db->query('CREATE TABLE characters_weapons (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NULL, quantity INT NULL)');
        $db->query('CREATE TABLE characters_outfits (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NULL, quantity INT NULL)');
        $db->query('CREATE TABLE base_storage (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NULL, resource_id INT NULL, quantity INT NULL)');

        // Ресурсы: id1 sell 10, id2 sell 4.
        $db->table('resources')->insert(['id' => 1, 'sell_price' => 10.00]);
        $db->table('resources')->insert(['id' => 2, 'sell_price' => 4.00]);
        // Крафт-предмет id5 price 100.
        $db->table('crafted_items')->insert(['id' => 5, 'price' => 100.00]);

        // Игрок 1 — наполнен во всех местах.
        $db->table('characters')->insert(['id' => 1, 'gold' => 1000]);
        // Добыча: 50×res1(=500) + 25×res2(=100) → kinds 2, units 75, value 600.
        $db->table('character_resources')->insert(['id_characters' => 1, 'id_resources' => 1, 'quantity' => 50]);
        $db->table('character_resources')->insert(['id_characters' => 1, 'id_resources' => 2, 'quantity' => 25]);
        // Крафт: 3×item5(=300) → kinds 1, units 3, value 300.
        $db->table('crafted_items_log')->insert(['character_id' => 1, 'crafted_item_id' => 5, 'quantity' => 3]);
        // Оружие 2, броня 1.
        $db->table('characters_weapons')->insert(['character_id' => 1, 'quantity' => 2]);
        $db->table('characters_outfits')->insert(['character_id' => 1, 'quantity' => 1]);
        // Склад базы: 60×res1(=600) → kinds 1, units 60, value 600.
        $db->table('base_storage')->insert(['character_id' => 1, 'resource_id' => 1, 'quantity' => 60]);

        // Игрок 2 — пустой, gold NULL.
        $db->table('characters')->insert(['id' => 2, 'gold' => null]);
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        parent::tearDown();
    }

    public function testSummaryAggregatesEveryLocation(): void
    {
        $s = (new ResourceOverviewService())->summary(1);

        $this->assertSame(2, $s['backpack']['kinds']);
        $this->assertSame(75, $s['backpack']['units']);
        $this->assertSame(600, $s['backpack']['value']);

        $this->assertSame(1, $s['crafted']['kinds']);
        $this->assertSame(3, $s['crafted']['units']);
        $this->assertSame(300, $s['crafted']['value']);

        $this->assertSame(2, $s['weapons']);
        $this->assertSame(1, $s['outfits']);

        $this->assertSame(1, $s['base_storage']['kinds']);
        $this->assertSame(60, $s['base_storage']['units']);
        $this->assertSame(600, $s['base_storage']['value']);

        $this->assertSame(1000, $s['gold']);
    }

    public function testNetWorthIncludesBaseStorage(): void
    {
        $s = (new ResourceOverviewService())->summary(1);
        // 1000 gold + 600 backpack + 300 crafted + 600 base_storage = 2500.
        $this->assertSame(2500, $s['net_worth']);
    }

    public function testEmptyCharacterAllZero(): void
    {
        $s = (new ResourceOverviewService())->summary(2);
        $this->assertSame(0, $s['backpack']['units']);
        $this->assertSame(0, $s['crafted']['units']);
        $this->assertSame(0, $s['weapons']);
        $this->assertSame(0, $s['outfits']);
        $this->assertSame(0, $s['base_storage']['units']);
        $this->assertSame(0, $s['gold']);
        $this->assertSame(0, $s['net_worth']);
    }
}
