<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\PVE\LootTableService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * ADR-107 — LootTableService: data-driven лут-таблицы (npcs.loot_table_id).
 * Детерминизм: сабкласс переопределяет seam'ы isEnabled/rollPercent/rollQty.
 *
 * @internal
 */
final class LootTableServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = [
        'loot_table_items', 'resources', 'character_resources', 'weapons',
        'characters_weapons', 'characters', 'game_settings',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('CREATE TABLE loot_table_items (id INT AUTO_INCREMENT PRIMARY KEY, loot_table_id INT,
                    item_type VARCHAR(20), item_id INT NULL, min_qty INT DEFAULT 1, max_qty INT DEFAULT 1,
                    drop_chance INT DEFAULT 100, sort_order INT DEFAULT 0)');
        $db->query('CREATE TABLE resources (id INT PRIMARY KEY, name VARCHAR(100), rarity INT DEFAULT 5)');
        $db->query('CREATE TABLE character_resources (id INT AUTO_INCREMENT PRIMARY KEY, id_characters INT,
                    id_resources INT, quantity INT DEFAULT 0, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $db->query('CREATE TABLE weapons (id INT PRIMARY KEY, name VARCHAR(150), rarity VARCHAR(50), durability INT DEFAULT 100)');
        $db->query('CREATE TABLE characters_weapons (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT,
                    weapon_id INT, quantity INT DEFAULT 1, current_durability INT DEFAULT 0, equipped TINYINT DEFAULT 0, slot VARCHAR(50) NULL)');
        $db->query('CREATE TABLE characters (id INT PRIMARY KEY, gold DECIMAL(15,2) DEFAULT 0)');
        $db->query('CREATE TABLE game_settings (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(64),
                    value_type VARCHAR(16) NULL, value_int INT NULL, value_bool TINYINT NULL, value_string TEXT NULL)');

        $db->table('characters')->insert(['id' => 491, 'gold' => 1000]);
        $db->table('resources')->insert(['id' => 69, 'name' => 'Сердце Дракона', 'rarity' => 1]);
        $db->table('weapons')->insert(['id' => 24, 'name' => 'Коса Жатвы', 'rarity' => 'Legendary', 'durability' => 80]);

        // Таблица 703: ресурс @100% / золото @100% / оружие @12% / ресурс-пустышка @0%.
        $db->table('loot_table_items')->insertBatch([
            ['loot_table_id' => 703, 'item_type' => 'resource', 'item_id' => 69, 'min_qty' => 2, 'max_qty' => 2, 'drop_chance' => 100, 'sort_order' => 1],
            ['loot_table_id' => 703, 'item_type' => 'gold', 'item_id' => null, 'min_qty' => 9000, 'max_qty' => 9000, 'drop_chance' => 100, 'sort_order' => 2],
            ['loot_table_id' => 703, 'item_type' => 'weapon', 'item_id' => 24, 'min_qty' => 1, 'max_qty' => 1, 'drop_chance' => 12, 'sort_order' => 3],
            ['loot_table_id' => 703, 'item_type' => 'resource', 'item_id' => 69, 'min_qty' => 1, 'max_qty' => 1, 'drop_chance' => 0, 'sort_order' => 4],
        ]);
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        parent::tearDown();
    }

    /** Сервис с детерминированными роллами: killswitch ON, фикс-процент, qty = min. */
    private function svc(int $rollPercent): LootTableService
    {
        return new class ($rollPercent) extends LootTableService {
            public function __construct(private int $fixedRoll)
            {
            }

            protected function isEnabled(): bool
            {
                return true;
            }

            protected function rollPercent(): int
            {
                return $this->fixedRoll;
            }

            protected function rollQty(int $min, int $max): int
            {
                return $min;
            }
        };
    }

    public function testGrantsResourceGoldAndLuckyWeapon(): void
    {
        // roll=10 ≤ 12% → оружие выпало; @0% строка не выпадает никогда.
        $lines = $this->svc(10)->rollAndGrant(703, 491);

        $this->assertSame(['Сердце Дракона ×2', '💰 +9000 золота', '⚔️ Коса Жатвы (Legendary)'], $lines);

        $db  = Database::connect('tests');
        $res = $db->table('character_resources')->where('id_characters', 491)->where('id_resources', 69)->get()->getRowArray();
        $this->assertSame(2, (int) $res['quantity']);
        $gold = $db->table('characters')->where('id', 491)->get()->getRowArray();
        $this->assertSame(10000.0, (float) $gold['gold']); // 1000 + 9000
        $weapon = $db->table('characters_weapons')->where('character_id', 491)->where('weapon_id', 24)->get()->getRowArray();
        $this->assertSame(1, (int) $weapon['quantity']);
        $this->assertSame(80, (int) $weapon['current_durability']); // полная прочность из определения
    }

    public function testUnluckyRollSkipsWeapon(): void
    {
        // roll=50 > 12% → оружие НЕ выпало, гарантированные строки выпали.
        $lines = $this->svc(50)->rollAndGrant(703, 491);

        $this->assertSame(['Сердце Дракона ×2', '💰 +9000 золота'], $lines);
        $this->assertSame(0, Database::connect('tests')->table('characters_weapons')->countAllResults());
    }

    public function testRepeatKillStacksWeaponQuantity(): void
    {
        $this->svc(1)->rollAndGrant(703, 491);
        $this->svc(1)->rollAndGrant(703, 491);

        $db     = Database::connect('tests');
        $weapon = $db->table('characters_weapons')->where('character_id', 491)->where('weapon_id', 24)->get()->getRowArray();
        $this->assertSame(2, (int) $weapon['quantity']); // upsert, не дубль-строка
        $this->assertSame(1, $db->table('characters_weapons')->countAllResults());
        // Ресурс стакается: 2 + 2.
        $res = $db->table('character_resources')->where('id_characters', 491)->where('id_resources', 69)->get()->getRowArray();
        $this->assertSame(4, (int) $res['quantity']);
    }

    public function testEmptyTableIsNoOp(): void
    {
        // Рейдерские 501/502/601 без строк → ничего (data-driven no-op).
        $this->assertSame([], $this->svc(1)->rollAndGrant(501, 491));
    }

    public function testKillswitchOffIsNoOp(): void
    {
        $svc = new class extends LootTableService {
            protected function isEnabled(): bool
            {
                return false;
            }
        };

        $this->assertSame([], $svc->rollAndGrant(703, 491));
        $this->assertSame(0, Database::connect('tests')->table('character_resources')->countAllResults());
    }

    public function testShouldDropPredicate(): void
    {
        $this->assertTrue(LootTableService::shouldDrop(100, 100));  // 100% — всегда
        $this->assertTrue(LootTableService::shouldDrop(12, 12));    // граница включительно
        $this->assertFalse(LootTableService::shouldDrop(13, 12));
        $this->assertFalse(LootTableService::shouldDrop(1, 0));     // 0% — никогда
    }
}
