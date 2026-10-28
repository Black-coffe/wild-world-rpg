<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Models\CharacterModel;
use App\Services\Player\Trade\ResourceTradeService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * s2-conditional-writes-10 (F-опт) — `bulkSellResources()` читает исход `increaseGold`.
 *
 * Раньше `false` от начисления оставлял ресурсы списанными, а золото — не начисленным:
 * сделка коммитилась и рапортовала успех. Двойник `CharacterModel` (опциональная зависимость
 * конструктора сервиса) принуждает `increaseGold` вернуть `false`; продажа обязана откатиться
 * целиком: ресурсы на месте, золото и банк не тронуты, текст — существующий отказ опта.
 *
 * Схема — та же минимальная DDL, что у `BulkSellResourcesTest`.
 *
 * @internal
 */
final class BulkSellIncreaseGoldRefusalTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const CHAR_ID = 492;
    private const MSG     = 'bulk sale committed although increaseGold refused, or withdraw-all issued the storage twice';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();
        $db = Database::connect('tests');
        $this->dropTables();

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
        $this->dropTables();
        $this->cleanCache();
        parent::tearDown();
    }

    public function testIncreaseGoldRefusalRollsBackWholeSale(): void
    {
        $db = Database::connect('tests');
        $db->table('characters')->insert(['id' => self::CHAR_ID, 'gold' => 1000]);
        $db->table('resources')->insert(['id' => 1, 'name' => 'Res1', 'icon_text' => '🔘', 'sell_price' => 2.0, 'rarity' => 3, 'is_tradeable' => 1]);
        $db->table('resources')->insert(['id' => 2, 'name' => 'Res2', 'icon_text' => '🔘', 'sell_price' => 4.0, 'rarity' => 3, 'is_tradeable' => 1]);
        $db->table('character_resources')->insert(['id_characters' => self::CHAR_ID, 'id_resources' => 1, 'quantity' => 100]);
        $db->table('character_resources')->insert(['id_characters' => self::CHAR_ID, 'id_resources' => 2, 'quantity' => 50]);

        $refusingGold = new class () extends CharacterModel {
            public function increaseGold(int $characterId, float $amount): bool
            {
                return false;
            }
        };

        $svc       = new ResourceTradeService($refusingGold);
        $character = ['id' => self::CHAR_ID, 'gold' => 1000];
        $token     = $svc->bulkSellPreview($character, 50, null)['token'];
        $res       = $svc->bulkSellResources($character, 50, null, $token);

        $this->assertFalse($res['success'], self::MSG);
        $this->assertStringStartsWith('Не удалось выполнить оптовую продажу', $res['message'], self::MSG);
        $this->assertSame(0, $res['totalGold'], self::MSG);
        $this->assertSame(100, $this->qtyOf(1), self::MSG);
        $this->assertSame(50, $this->qtyOf(2), self::MSG);
        $this->assertSame(1000.0, $this->goldOf(), self::MSG);
        $this->assertSame(0, (int) $db->table('resources_bank')->countAllResults(), self::MSG);
        $this->assertSame(0, $db->transDepth, 'транзакция опта не закрыта на пути отказа');
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

    private function dropTables(): void
    {
        $db = Database::connect('tests');
        foreach (['characters', 'character_resources', 'resources', 'resources_bank'] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
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
}
