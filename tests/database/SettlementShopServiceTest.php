<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Settlement\SettlementShopService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * ADR-101 Фаза 3 (рефайн) — SettlementShopService: readers, цена свой/чужой, listForSettlement,
 * write-path purchase (списание золота + выдача weapon/outfit), killswitch и отказы.
 *
 * @internal
 */
final class SettlementShopServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = [
        'game_settings', 'settlements', 'settlement_shop_items', 'weapons', 'outfits',
        'crafted_items', 'characters', 'characters_weapons', 'characters_outfits',
        'crafted_items_log', 'character_factions',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('CREATE TABLE game_settings (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(64) NOT NULL, category VARCHAR(32) NULL, value_type VARCHAR(16) NULL, value_int INT NULL, value_bool TINYINT NULL, value_string TEXT NULL)');
        $db->query('CREATE TABLE settlements (id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(64), name_ru VARCHAR(120) NULL, type VARCHAR(16) NULL, faction_id INT NULL, enabled TINYINT DEFAULT 1)');
        $db->query('CREATE TABLE settlement_shop_items (id INT AUTO_INCREMENT PRIMARY KEY, settlement_id INT, item_type VARCHAR(16), item_id INT, base_price INT, sort_order INT DEFAULT 0, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $db->query('CREATE TABLE weapons (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NULL, name_en VARCHAR(120) NULL, required_level INT NULL, rarity VARCHAR(24) NULL, price INT NULL)');
        $db->query('CREATE TABLE outfits (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NULL, name_en VARCHAR(120) NULL, slot VARCHAR(24) NULL, required_level INT NULL, rarity VARCHAR(24) NULL, price INT NULL)');
        $db->query('CREATE TABLE crafted_items (id INT AUTO_INCREMENT PRIMARY KEY, name_rus VARCHAR(120) NULL, name_eng VARCHAR(120) NULL, type VARCHAR(32) NULL, direction_craft VARCHAR(32) NULL, crafting_location VARCHAR(32) NULL, durability_count INT NULL, required_level INT NULL)');
        $db->query('CREATE TABLE characters (id INT AUTO_INCREMENT PRIMARY KEY, telegram_user_id INT NULL, gold INT NULL, level INT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $db->query('CREATE TABLE characters_weapons (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT, weapon_id INT, quantity INT, current_durability INT, equipped TINYINT, slot VARCHAR(24), created_at DATETIME NULL, updated_at DATETIME NULL)');
        $db->query('CREATE TABLE characters_outfits (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT, outfit_id INT, quantity INT, current_durability INT, equipped TINYINT, slot VARCHAR(24), created_at DATETIME NULL, updated_at DATETIME NULL)');
        $db->query('CREATE TABLE crafted_items_log (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT, task_id INT NULL, crafted_item_id INT, type VARCHAR(32) NULL, direction_craft VARCHAR(32) NULL, crafting_location VARCHAR(32) NULL, durability_count INT NULL, durability_time DATE NULL, quantity INT, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $db->query('CREATE TABLE character_factions (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT, faction_id INT)');
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        parent::tearDown();
    }

    private function setBool(string $key, int $val): void
    {
        Database::connect('tests')->table('game_settings')->insert([
            'setting_key' => $key, 'category' => 'world', 'value_type' => 'bool', 'value_bool' => $val,
        ]);
    }

    private function setInt(string $key, int $val): void
    {
        Database::connect('tests')->table('game_settings')->insert([
            'setting_key' => $key, 'category' => 'world', 'value_type' => 'int', 'value_int' => $val,
        ]);
    }

    private function svc(): SettlementShopService
    {
        return new SettlementShopService();
    }

    /** Оплот фракции 1 (id=10) с одним оружием (1000) и одной бронёй (500). */
    private function seedFactionShop(): void
    {
        $db = Database::connect('tests');
        $db->table('settlements')->insert(['id' => 10, 'code' => 'stronghold_military', 'name_ru' => 'Бастион', 'type' => 'faction', 'faction_id' => 1, 'enabled' => 1]);
        $db->table('weapons')->insert(['id' => 9, 'name' => 'Штурмовая винтовка', 'name_en' => 'AssaultRifle556', 'required_level' => 8, 'rarity' => 'Rare', 'price' => 1500]);
        $db->table('outfits')->insert(['id' => 13, 'name' => 'Тактическая броня', 'name_en' => 'TacticalArmorSuit', 'slot' => 'body', 'required_level' => 16, 'rarity' => 'Epic', 'price' => 1500]);
        $db->table('settlement_shop_items')->insert(['id' => 1, 'settlement_id' => 10, 'item_type' => 'weapon', 'item_id' => 9, 'base_price' => 1000, 'sort_order' => 0]);
        $db->table('settlement_shop_items')->insert(['id' => 2, 'settlement_id' => 10, 'item_type' => 'outfit', 'item_id' => 13, 'base_price' => 500, 'sort_order' => 1]);
    }

    public function testReadersDefaultsWhenUnset(): void
    {
        $this->assertFalse($this->svc()->enabled());
        $this->assertSame(SettlementShopService::DEFAULT_MEMBER_DISCOUNT, $this->svc()->memberDiscountPct());
        $this->assertSame(SettlementShopService::DEFAULT_OTHER_MARKUP, $this->svc()->otherMarkupPct());
    }

    public function testPriceForMemberAndOther(): void
    {
        $this->setInt('settlements.shop.member_discount_pct', 20);
        $this->setInt('settlements.shop.other_markup_pct', 50);

        // свой (faction 1 == settlement 1): 1000 → -20% = 800
        $this->assertSame(800, $this->svc()->priceFor(1000, 1, 1));
        // чужой (faction 2): 1000 → +50% = 1500
        $this->assertSame(1500, $this->svc()->priceFor(1000, 1, 2));
        // без фракции (0): наценка как чужому
        $this->assertSame(1500, $this->svc()->priceFor(1000, 1, 0));
    }

    public function testListForSettlementResolvesNamesAndPrices(): void
    {
        $this->seedFactionShop();
        $this->setInt('settlements.shop.member_discount_pct', 10);
        $this->setInt('settlements.shop.other_markup_pct', 25);

        // игрок-член фракции 1
        $list = $this->svc()->listForSettlement(10, 1, 1);
        $this->assertCount(2, $list);
        $this->assertSame('Штурмовая винтовка', $list[0]['name']);
        $this->assertSame(900, $list[0]['price'], 'свой: 1000 −10%');
        $this->assertTrue($list[0]['is_member']);

        // чужой игрок (фракция 2)
        $listOther = $this->svc()->listForSettlement(10, 1, 2);
        $this->assertSame(1250, $listOther[0]['price'], 'чужой: 1000 +25%');
        $this->assertFalse($listOther[0]['is_member']);
    }

    public function testPurchaseWeaponDeductsGoldAndGrants(): void
    {
        $this->seedFactionShop();
        $this->setBool('settlements.shop.enabled', 1);
        $this->setInt('settlements.shop.member_discount_pct', 0);
        $this->setInt('settlements.shop.other_markup_pct', 0);

        $db = Database::connect('tests');
        $db->table('characters')->insert(['id' => 50, 'telegram_user_id' => 1, 'gold' => 5000, 'level' => 10]);
        $db->table('character_factions')->insert(['character_id' => 50, 'faction_id' => 1]); // свой

        $res = $this->svc()->purchase(50, 1, 10, 1); // shop_item 1 = weapon, base 1000, no discount
        $this->assertTrue($res['success'], $res['message']);
        $this->assertSame(1000, $res['price'] ?? null);

        $char = $db->table('characters')->where('id', 50)->get()->getRowArray();
        $this->assertSame(4000, (int) $char['gold'], 'золото списано 5000-1000');

        $owned = $db->table('characters_weapons')->where('character_id', 50)->where('weapon_id', 9)->get()->getRowArray();
        $this->assertNotEmpty($owned, 'оружие выдано в инвентарь');
        $this->assertSame(1, (int) $owned['quantity']);
        $this->assertSame(0, (int) $owned['equipped']);
    }

    public function testPurchaseOutfitGrantsWithSlot(): void
    {
        $this->seedFactionShop();
        $this->setBool('settlements.shop.enabled', 1);
        $this->setInt('settlements.shop.member_discount_pct', 0);
        $this->setInt('settlements.shop.other_markup_pct', 0);

        $db = Database::connect('tests');
        $db->table('characters')->insert(['id' => 51, 'telegram_user_id' => 1, 'gold' => 5000, 'level' => 20]);
        $db->table('character_factions')->insert(['character_id' => 51, 'faction_id' => 1]);

        $res = $this->svc()->purchase(51, 2, 10, 1); // shop_item 2 = outfit, base 500
        $this->assertTrue($res['success'], $res['message']);

        $owned = $db->table('characters_outfits')->where('character_id', 51)->where('outfit_id', 13)->get()->getRowArray();
        $this->assertNotEmpty($owned);
        $this->assertSame('body', (string) $owned['slot'], 'slot взят из outfits.slot');
    }

    public function testPurchaseRejectedWhenDisabled(): void
    {
        $this->seedFactionShop();
        // killswitch OFF (не сеем enabled)
        $db = Database::connect('tests');
        $db->table('characters')->insert(['id' => 52, 'telegram_user_id' => 1, 'gold' => 5000, 'level' => 10]);

        $res = $this->svc()->purchase(52, 1, 10, 1);
        $this->assertFalse($res['success']);
        $char = $db->table('characters')->where('id', 52)->get()->getRowArray();
        $this->assertSame(5000, (int) $char['gold'], 'золото не тронуто при выключенной лавке');
    }

    public function testPurchaseRejectedWhenPoor(): void
    {
        $this->seedFactionShop();
        $this->setBool('settlements.shop.enabled', 1);
        $this->setInt('settlements.shop.member_discount_pct', 0);
        $this->setInt('settlements.shop.other_markup_pct', 0);

        $db = Database::connect('tests');
        $db->table('characters')->insert(['id' => 53, 'telegram_user_id' => 1, 'gold' => 100, 'level' => 10]);
        $db->table('character_factions')->insert(['character_id' => 53, 'faction_id' => 1]);

        $res = $this->svc()->purchase(53, 1, 10, 1); // нужно 1000, есть 100
        $this->assertFalse($res['success']);
        $owned = $db->table('characters_weapons')->where('character_id', 53)->get()->getRowArray();
        $this->assertEmpty($owned, 'предмет не выдан при нехватке золота');
    }

    public function testPurchaseRejectedWhenItemNotInSettlement(): void
    {
        $this->seedFactionShop();
        $this->setBool('settlements.shop.enabled', 1);

        $db = Database::connect('tests');
        $db->table('characters')->insert(['id' => 54, 'telegram_user_id' => 1, 'gold' => 5000, 'level' => 10]);

        // shop_item 1 принадлежит поселению 10, а просим из поселения 99
        $res = $this->svc()->purchase(54, 1, 99, 1);
        $this->assertFalse($res['success']);
        $char = $db->table('characters')->where('id', 54)->get()->getRowArray();
        $this->assertSame(5000, (int) $char['gold']);
    }
}
