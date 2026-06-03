<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Player\Trade\ResourceTradeService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * ADR-096 — оптовая продажа: интеграция write-path на реальной MySQL.
 *
 * Зеркало CharacterRepositoryAdjustGoldTest / WarehouseStorageAndSellBonusTest:
 * ручные минимальные таблицы. Проверяем то, что НЕ ловит чистый planBulkSale:
 * золото начисляется ОДНИМ апдейтом (а не перетирается per-resource), запасы
 * списываются (floor), пустые стопки удаляются, банк продаж обновляется, фильтр
 * редкости и пропуск bound/0-цены работают на БД.
 *
 * @internal
 */
final class BulkSellResourcesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const CHAR_ID = 491;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();
        $db = Database::connect('tests');

        $db->query('DROP TABLE IF EXISTS characters');
        $db->query('DROP TABLE IF EXISTS character_resources');
        $db->query('DROP TABLE IF EXISTS resources');
        $db->query('DROP TABLE IF EXISTS resources_bank');

        $db->query('
            CREATE TABLE characters (
                id INT AUTO_INCREMENT PRIMARY KEY,
                gold DECIMAL(18,2) NOT NULL DEFAULT 0,
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
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        $db->query('
            CREATE TABLE resources (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(191) NOT NULL,
                icon_text VARCHAR(191) NULL,
                sell_price DECIMAL(18,2) NOT NULL DEFAULT 0,
                rarity INT NOT NULL DEFAULT 1,
                is_tradeable TINYINT NOT NULL DEFAULT 1
            )
        ');
        $db->query('
            CREATE TABLE resources_bank (
                id INT AUTO_INCREMENT PRIMARY KEY,
                resource_id INT NOT NULL,
                current_quantity INT NOT NULL DEFAULT 0,
                resources_purchased INT NOT NULL DEFAULT 0,
                resources_sold INT NOT NULL DEFAULT 0,
                last_update DATETIME NULL
            )
        ');
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        $db->query('DROP TABLE IF EXISTS characters');
        $db->query('DROP TABLE IF EXISTS character_resources');
        $db->query('DROP TABLE IF EXISTS resources');
        $db->query('DROP TABLE IF EXISTS resources_bank');
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

    private function seedResource(int $id, int $rarity, float $sellPrice, int $isTradeable = 1): void
    {
        Database::connect('tests')->table('resources')->insert([
            'id' => $id, 'name' => "Res{$id}", 'icon_text' => '🔘',
            'sell_price' => $sellPrice, 'rarity' => $rarity, 'is_tradeable' => $isTradeable,
        ]);
    }

    private function giveResource(int $resourceId, int $qty): void
    {
        Database::connect('tests')->table('character_resources')->insert([
            'id_characters' => self::CHAR_ID, 'id_resources' => $resourceId, 'quantity' => $qty,
        ]);
    }

    private function seedCharacter(int $gold): void
    {
        Database::connect('tests')->table('characters')->insert(['id' => self::CHAR_ID, 'gold' => $gold]);
    }

    private function qtyOf(int $resourceId): ?int
    {
        $row = Database::connect('tests')->table('character_resources')
            ->where('id_characters', self::CHAR_ID)->where('id_resources', $resourceId)
            ->get()->getRowArray();

        return $row === null ? null : (int) $row['quantity'];
    }

    private function goldOf(): float
    {
        $row = Database::connect('tests')->table('characters')->where('id', self::CHAR_ID)->get()->getRowArray();

        return (float) ($row['gold'] ?? 0);
    }

    private function soldInBank(int $resourceId): int
    {
        $row = Database::connect('tests')->table('resources_bank')->where('resource_id', $resourceId)->get()->getRowArray();

        return $row === null ? 0 : (int) $row['resources_sold'];
    }

    /** @return array<string,mixed> */
    private function character(): array
    {
        return ['id' => self::CHAR_ID, 'gold' => $this->goldOf()];
    }

    public function testBulkSellAllAppliesPercentSkipsAndAddsGoldOnce(): void
    {
        $this->seedCharacter(1000);
        $this->seedResource(1, 3, 2.0);            // ок
        $this->seedResource(2, 3, 4.0);            // ок
        $this->seedResource(3, 3, 0.0);            // цена 0 → пропуск
        $this->seedResource(4, 5, 10.0);           // другая редкость, но scope=all → продаётся
        $this->seedResource(5, 3, 7.0, 0);         // bound → пропуск
        $this->giveResource(1, 100);
        $this->giveResource(2, 55);
        $this->giveResource(3, 100);
        $this->giveResource(4, 30);
        $this->giveResource(5, 100);

        $res = (new ResourceTradeService())->bulkSellResources($this->character(), 10, null);

        // 10%: r1 10→20💰, r2 5→20💰, r4 3→30💰; r3/r5 пропущены.
        $this->assertTrue($res['success']);
        $this->assertSame(3, $res['typesSold']);
        $this->assertSame(18, $res['totalQty']);   // 10 + 5 + 3
        $this->assertSame(70, $res['totalGold']);  // 20 + 20 + 30

        // 🔴 золото начислено ОДНИМ апдейтом (сумма всех, не последняя продажа).
        $this->assertSame(1070.0, $this->goldOf());

        $this->assertSame(90, $this->qtyOf(1));
        $this->assertSame(50, $this->qtyOf(2));
        $this->assertSame(27, $this->qtyOf(4));
        $this->assertSame(100, $this->qtyOf(3)); // bound/0-цена не тронуты
        $this->assertSame(100, $this->qtyOf(5));

        $this->assertSame(10, $this->soldInBank(1));
        $this->assertSame(5, $this->soldInBank(2));
        $this->assertSame(3, $this->soldInBank(4));
        $this->assertSame(0, $this->soldInBank(3));
    }

    public function testBulkSellRarityFilterTouchesOnlyThatRarity(): void
    {
        $this->seedCharacter(1000);
        $this->seedResource(1, 3, 2.0);
        $this->seedResource(2, 3, 4.0);
        $this->seedResource(4, 5, 10.0); // редкость 5 — не должна тронуться
        $this->giveResource(1, 100);
        $this->giveResource(2, 55);
        $this->giveResource(4, 30);

        $res = (new ResourceTradeService())->bulkSellResources($this->character(), 50, 3);

        // 50% редкости 3: r1 50→100💰, r2 27 (floor 27.5)→108💰.
        $this->assertTrue($res['success']);
        $this->assertSame(2, $res['typesSold']);
        $this->assertSame(77, $res['totalQty']);
        $this->assertSame(208, $res['totalGold']);
        $this->assertSame(1208.0, $this->goldOf());

        $this->assertSame(50, $this->qtyOf(1));
        $this->assertSame(28, $this->qtyOf(2));
        $this->assertSame(30, $this->qtyOf(4)); // другая редкость — нетронута
    }

    public function testBulkSellFullPercentDeletesEmptyStacks(): void
    {
        $this->seedCharacter(0);
        $this->seedResource(1, 2, 3.0);
        $this->giveResource(1, 40);

        $res = (new ResourceTradeService())->bulkSellResources($this->character(), 100, null);

        $this->assertTrue($res['success']);
        $this->assertSame(40, $res['totalQty']);
        $this->assertSame(120, $res['totalGold']);
        $this->assertSame(120.0, $this->goldOf());
        $this->assertNull($this->qtyOf(1)); // стопка обнулена → строка удалена
    }

    public function testBulkSellNothingToSellFailsAndKeepsGold(): void
    {
        $this->seedCharacter(500);
        $this->seedResource(3, 3, 0.0); // только 0-цена
        $this->giveResource(3, 100);

        $res = (new ResourceTradeService())->bulkSellResources($this->character(), 50, null);

        $this->assertFalse($res['success']);
        $this->assertSame(0, $res['totalGold']);
        $this->assertSame(500.0, $this->goldOf());   // золото не изменилось
        $this->assertSame(100, $this->qtyOf(3));      // запас не тронут
    }
}
