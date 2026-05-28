<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Craft\ItemModifierService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * W19 (ADR-074) — ItemModifierService: killswitch + множитель урона + enchant (gold+ресурс, перезапись).
 *
 * @internal
 */
final class ItemModifierServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();
        $db = Database::connect('tests');
        foreach (['game_settings', 'item_modifiers', 'characters', 'character_resources', 'resources', 'characters_weapons'] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('CREATE TABLE game_settings (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(191) NOT NULL, category VARCHAR(64) NULL, value_type VARCHAR(16) NULL, value_int INT NULL, value_float DECIMAL(15,5) NULL, value_bool TINYINT NULL, value_string TEXT NULL, hard_min VARCHAR(32) NULL, hard_max VARCHAR(32) NULL)');
        $db->query('CREATE TABLE item_modifiers (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NOT NULL, item_type VARCHAR(16) NOT NULL, item_instance_id INT NOT NULL, stat VARCHAR(32) NOT NULL, bonus_pct INT NOT NULL DEFAULT 0, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $db->query('CREATE TABLE characters (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NULL, gold INT NOT NULL DEFAULT 0)');
        $db->query('CREATE TABLE character_resources (id INT AUTO_INCREMENT PRIMARY KEY, id_characters INT NOT NULL, id_resources INT NOT NULL, quantity INT NOT NULL DEFAULT 0, custom_data TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $db->query('CREATE TABLE resources (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NULL, rarity INT NULL)');
        $db->query('CREATE TABLE characters_weapons (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NOT NULL, weapon_id INT NOT NULL, equipped TINYINT NOT NULL DEFAULT 0, created_at DATETIME NULL, updated_at DATETIME NULL)');
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (['game_settings', 'item_modifiers', 'characters', 'character_resources', 'resources', 'characters_weapons'] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $this->cleanCache();
        parent::tearDown();
    }

    private function seedBool(string $key, int $bool): void
    {
        Database::connect('tests')->table('game_settings')->insert(['setting_key' => $key, 'category' => 'craft', 'value_type' => 'bool', 'value_bool' => $bool]);
        $this->cleanCache();
    }

    private function seedInt(string $key, int $int): void
    {
        Database::connect('tests')->table('game_settings')->insert(['setting_key' => $key, 'category' => 'craft', 'value_type' => 'int', 'value_int' => $int]);
        $this->cleanCache();
    }

    private function seedString(string $key, string $val): void
    {
        Database::connect('tests')->table('game_settings')->insert(['setting_key' => $key, 'category' => 'craft', 'value_type' => 'string', 'value_string' => $val]);
        $this->cleanCache();
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

    public function testDefaultsDormant(): void
    {
        $svc = new ItemModifierService();
        $this->assertFalse($svc->enabled());
        // dormant → множитель 1.0 БЕЗ модификатора.
        $this->assertSame(1.0, $svc->weaponDamageMultiplier(123));
        $this->assertSame(0, $svc->modifierBonusPct('weapon', 123, 'damage'));
    }

    public function testMultiplierDormantEvenWithModifierRow(): void
    {
        // Модификатор есть, но killswitch off → 1.0 (dormant gate).
        Database::connect('tests')->table('item_modifiers')->insert(['character_id' => 1, 'item_type' => 'weapon', 'item_instance_id' => 50, 'stat' => 'damage', 'bonus_pct' => 5]);
        $svc = new ItemModifierService();
        $this->assertSame(1.0, $svc->weaponDamageMultiplier(50));
    }

    public function testMultiplierWhenEnabled(): void
    {
        $this->seedBool('craft.modifier.enabled', 1);
        Database::connect('tests')->table('item_modifiers')->insert(['character_id' => 1, 'item_type' => 'weapon', 'item_instance_id' => 50, 'stat' => 'damage', 'bonus_pct' => 5]);
        $svc = new ItemModifierService();
        $this->assertTrue($svc->enabled());
        $this->assertSame(1.05, $svc->weaponDamageMultiplier(50));
        // Без модификатора у другого экземпляра → 1.0.
        $this->assertSame(1.0, $svc->weaponDamageMultiplier(999));
    }

    public function testEnchantDeductsGoldAndResourceAndCreatesModifier(): void
    {
        $db = Database::connect('tests');
        $this->seedBool('craft.modifier.enabled', 1);
        $this->seedInt('craft.modifier.gold_cost', 2000);
        $this->seedString('craft.modifier.resource_name', 'Минералы');
        $this->seedInt('craft.modifier.resource_qty', 5);
        $this->seedInt('craft.modifier.bonus_pct', 5);

        $db->table('characters')->insert(['id' => 1, 'name' => 'Hero', 'gold' => 10000]);
        $db->table('resources')->insert(['id' => 11, 'name' => 'Минералы', 'rarity' => 1]);
        $db->table('character_resources')->insert(['id_characters' => 1, 'id_resources' => 11, 'quantity' => 20]);
        $db->table('characters_weapons')->insert(['id' => 50, 'character_id' => 1, 'weapon_id' => 7, 'equipped' => 1]);

        $svc = new ItemModifierService();
        $res = $svc->enchant(1, 50);

        $this->assertTrue($res['ok']);
        $this->assertSame(5, $res['bonus_pct']);
        // Золото списано.
        $gold = (int) $db->table('characters')->select('gold')->where('id', 1)->get()->getRowArray()['gold'];
        $this->assertSame(8000, $gold);
        // Ресурс списан.
        $qty = (int) $db->table('character_resources')->select('quantity')->where('id_characters', 1)->get()->getRowArray()['quantity'];
        $this->assertSame(15, $qty);
        // Модификатор создан.
        $this->assertSame(5, $svc->modifierBonusPct('weapon', 50, 'damage'));
    }

    public function testEnchantOverwritesSameInstance(): void
    {
        $db = Database::connect('tests');
        $this->seedBool('craft.modifier.enabled', 1);
        $this->seedInt('craft.modifier.gold_cost', 1000);
        $this->seedString('craft.modifier.resource_name', '');
        $this->seedInt('craft.modifier.resource_qty', 0);
        $this->seedInt('craft.modifier.bonus_pct', 5);

        $db->table('characters')->insert(['id' => 1, 'name' => 'Hero', 'gold' => 10000]);
        $db->table('characters_weapons')->insert(['id' => 50, 'character_id' => 1, 'weapon_id' => 7, 'equipped' => 1]);

        $svc = new ItemModifierService();
        $this->assertTrue($svc->enchant(1, 50)['ok']);
        $this->assertTrue($svc->enchant(1, 50)['ok']); // повтор → перезапись, не дубль

        $count = (int) $db->table('item_modifiers')->where('item_type', 'weapon')->where('item_instance_id', 50)->countAllResults();
        $this->assertSame(1, $count, '1 модификатор на экземпляр (перезапись)');
        // Дважды списали золото.
        $gold = (int) $db->table('characters')->select('gold')->where('id', 1)->get()->getRowArray()['gold'];
        $this->assertSame(8000, $gold);
    }

    public function testEnchantFailsNoGold(): void
    {
        $db = Database::connect('tests');
        $this->seedBool('craft.modifier.enabled', 1);
        $this->seedInt('craft.modifier.gold_cost', 2000);
        $this->seedString('craft.modifier.resource_name', '');
        $this->seedInt('craft.modifier.resource_qty', 0);

        $db->table('characters')->insert(['id' => 1, 'name' => 'Hero', 'gold' => 100]);
        $db->table('characters_weapons')->insert(['id' => 50, 'character_id' => 1, 'weapon_id' => 7, 'equipped' => 1]);

        $res = (new ItemModifierService())->enchant(1, 50);
        $this->assertFalse($res['ok']);
        $this->assertSame('no_gold', $res['error']);
        // Золото не тронуто.
        $gold = (int) $db->table('characters')->select('gold')->where('id', 1)->get()->getRowArray()['gold'];
        $this->assertSame(100, $gold);
    }

    public function testEnchantFailsDormant(): void
    {
        // killswitch off → enchant отказывает.
        $res = (new ItemModifierService())->enchant(1, 50);
        $this->assertFalse($res['ok']);
        $this->assertSame('disabled', $res['error']);
    }
}
