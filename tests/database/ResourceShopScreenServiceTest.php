<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Controllers\Telegram\Commands\Actions\ShopAction;
use App\Services\Player\Trade\ResourceShopScreenService;
use App\Services\Player\Trade\ResourceTradeService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * w2-n6-trade-storage-02 — нейтральные модели магазина сырья и потолок количества в ядре.
 *
 * Модели берут персонажа по id и не знают ни `chat_id`, ни Telegram; цены и итоги — той же
 * формулой, что сделка. «Своё число» выше потолка — отказ без единой записи; два подряд вызова
 * продажи последнего остатка продают его один раз. ForceReply бота идёт прямо в
 * `ResourceTradeService` и, как раньше, продаёт «сколько есть».
 *
 * @internal
 */
final class ResourceShopScreenServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = ['characters', 'character_resources', 'resources', 'resources_bank'];

    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('CREATE TABLE characters (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NULL, level INT DEFAULT 1, gold DECIMAL(14,2) DEFAULT 0, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $db->query('CREATE TABLE character_resources (id INT AUTO_INCREMENT PRIMARY KEY, id_characters INT, id_resources INT, quantity INT DEFAULT 0, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $db->query('CREATE TABLE resources (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64), name_en VARCHAR(64) NULL, buy_price DECIMAL(10,2) DEFAULT 0, sell_price DECIMAL(10,2) DEFAULT 0, is_tradeable TINYINT DEFAULT 1, rarity INT DEFAULT 1, level_required INT DEFAULT 0, icon_text VARCHAR(16) NULL)');
        $db->query('CREATE TABLE resources_bank (id INT AUTO_INCREMENT PRIMARY KEY, resource_id INT, current_quantity INT DEFAULT 0, resources_purchased INT DEFAULT 0, resources_sold INT DEFAULT 0, last_update DATETIME NULL, UNIQUE KEY uq_res (resource_id))');

        $db->table('resources')->insertBatch([
            ['id' => 7, 'name' => 'Ржавый лом', 'buy_price' => 10.0, 'sell_price' => 4.5, 'is_tradeable' => 1, 'rarity' => 1, 'icon_text' => '🔧'],
            ['id' => 8, 'name' => 'Глина', 'buy_price' => 5.0, 'sell_price' => 2.0, 'is_tradeable' => 1, 'rarity' => 1, 'icon_text' => '🟫'],
            ['id' => 9, 'name' => 'Семена', 'buy_price' => 0.0, 'sell_price' => 0.0, 'is_tradeable' => 0, 'rarity' => 1, 'icon_text' => '🌱'],
        ]);
        $db->table('characters')->insert(['id' => 1, 'name' => 'Тест', 'level' => 10, 'gold' => 103.0]);
        $db->table('character_resources')->insertBatch([
            ['id_characters' => 1, 'id_resources' => 7, 'quantity' => 40],
            ['id_characters' => 1, 'id_resources' => 8, 'quantity' => 3],
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

    public function testModelsTakeCharacterIdAndNoTelegram(): void
    {
        $class = new \ReflectionClass(ResourceShopScreenService::class);
        foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getParameters() as $param) {
                $this->assertStringNotContainsStringIgnoringCase('chat', $param->getName(), $method->getName());
            }
        }
        $source = (string) file_get_contents((string) $class->getFileName());
        $this->assertStringNotContainsString('Longman', $source);
        $this->assertStringNotContainsString('Request::', $source);
    }

    public function testSellModels(): void
    {
        $shop = new ResourceShopScreenService();

        $hub = $shop->sellHubModel(1);
        $this->assertSame(2, $hub['types']);
        $this->assertSame(186.0, $hub['total_value']);
        $this->assertSame([10, 25, 50, 100], $hub['bulk']);

        $rarity = $shop->sellRarityModel(1, 1);
        $this->assertTrue($rarity['known']);
        $this->assertSame([
            ['resource_id' => 7, 'name' => 'Ржавый лом', 'quantity' => 40, 'total' => 180],
            ['resource_id' => 8, 'name' => 'Глина', 'quantity' => 3, 'total' => 6],
        ], $rarity['rows']);
        $this->assertFalse($shop->sellRarityModel(1, 4)['known']);

        $card = $shop->sellCardModel(1, 7);
        $this->assertNotNull($card);
        $this->assertSame('4.5', $card['unit_text']);
        $this->assertSame(40, $card['max_qty']);
        $this->assertCount(12, $card['presets']);
        $this->assertSame(['qty' => 5, 'total' => 23], $card['presets'][1]);
        $seeds = $shop->sellCardModel(1, 9);
        $this->assertNotNull($seeds);
        $this->assertSame(0, $seeds['max_qty']);
        $this->assertNull($shop->sellCardModel(1, 999));
    }

    public function testBuyModels(): void
    {
        $shop = new ResourceShopScreenService();

        $hub = $shop->buyHubModel(1);
        $this->assertTrue($hub['allowed']);
        $this->assertSame(10, $hub['min_gold']);
        $this->assertSame(103.0, $hub['gold']);

        $this->assertSame([
            ['resource_id' => 7, 'name' => 'Ржавый лом', 'price_text' => '10'],
            ['resource_id' => 8, 'name' => 'Глина', 'price_text' => '5'],
        ], $shop->buyRarityModel(1)['rows'], 'семена с is_tradeable=0 на витрину не попадают');

        $card = $shop->buyCardModel(1, 7, 3);
        $this->assertNotNull($card);
        $this->assertSame(10, $card['max_qty'], '103💰 / 10 = 10 шт.');
        $this->assertSame(['qty' => 3, 'total' => 30], $card['need']);
        $plain = $shop->buyCardModel(1, 7);
        $this->assertNotNull($plain);
        $this->assertNull($plain['need']);

        Database::connect('tests')->query('UPDATE characters SET gold = 9 WHERE id = 1');
        $this->assertFalse($shop->buyHubModel(1)['allowed']);
    }

    public function testSellOverMaxIsRefusedAndTouchesNothing(): void
    {
        $r = (new ResourceShopScreenService())->sell(1, 8, 4);

        $this->assertSame(ResourceShopScreenService::OVER_MAX, $r['code']);
        $this->assertSame(3, $this->owned(8));
        $this->assertSame(103.0, $this->gold());
        $this->assertSame(0, $this->bank('resources_sold'));

        $this->assertSame(ResourceShopScreenService::BAD_QTY, (new ResourceShopScreenService())->sell(1, 8, 0)['code']);
    }

    public function testBuyOverMaxIsRefusedAndTouchesNothing(): void
    {
        $r = (new ResourceShopScreenService())->buy(1, 7, 11);

        $this->assertSame(ResourceShopScreenService::OVER_MAX, $r['code']);
        $this->assertSame(40, $this->owned(7));
        $this->assertSame(103.0, $this->gold());
        $this->assertSame(0, $this->bank('resources_purchased'));

        $ok = (new ResourceShopScreenService())->buy(1, 7, 10);
        $this->assertSame(ResourceShopScreenService::OK, $ok['code'], $ok['message']);
        $this->assertSame(50, $this->owned(7));
        $this->assertSame(3.0, $this->gold());
    }

    public function testTwoSellsOfTheLastRemainderSellItOnce(): void
    {
        $shop  = new ResourceShopScreenService();
        $first = $shop->sell(1, 8, 3);
        $again = $shop->sell(1, 8, 3);

        $this->assertSame(ResourceShopScreenService::OK, $first['code'], $first['message']);
        $this->assertNotSame(ResourceShopScreenService::OK, $again['code']);
        $this->assertSame(0, $this->owned(8));
        $this->assertSame(109.0, $this->gold(), '3 × 2💰 — один раз');
        $this->assertSame(3, $this->bank('resources_sold'));

        // Кнопка бота «всё» дважды подряд — тот же итог.
        $trade = new ResourceTradeService();
        $this->assertTrue($trade->sellResource(['id' => 1], 7, 'all')['success']);
        $this->assertFalse($trade->sellResource(['id' => 1], 7, 'all')['success']);
        $this->assertSame(289.0, $this->gold(), '40 × 4.5💰 — один раз');
    }

    /**
     * ForceReply «SELL:/BUY:» (`GenericmessageCommand::handleTradeReply`) зовёт ядро напрямую — как
     * раньше: продажа числом больше запаса продаёт всё, покупка списывает золото и выдаёт ресурс.
     */
    public function testForceReplyCoreSellsAndBuysAsBefore(): void
    {
        $trade = new ResourceTradeService();

        $sell = $trade->sellResource(['id' => 1, 'gold' => 103], 8, 25000);
        $this->assertTrue($sell['success'], $sell['message']);
        $this->assertSame(3, $sell['qty'] ?? 0);
        $this->assertSame(0, $this->owned(8));

        $buy = $trade->buyResource(['id' => 1, 'gold' => 109, 'level' => 10], 8, 4);
        $this->assertTrue($buy['success'], $buy['message']);
        $this->assertSame(4, $this->owned(8));
        $this->assertSame(89.0, $this->gold());
    }

    public function testBulkPreviewAndSellGates(): void
    {
        $shop = new ResourceShopScreenService();

        $this->assertSame(ResourceShopScreenService::INVALID, $shop->bulkPreviewModel(1, null, 33)['code']);
        $this->assertSame(ResourceShopScreenService::INVALID, $shop->bulkPreviewModel(1, 11, 50)['code']);
        $this->assertSame(ResourceShopScreenService::EMPTY, $shop->bulkPreviewModel(1, 2, 50)['code']);

        $p = $shop->bulkPreviewModel(1, null, 50);
        $this->assertSame([ResourceShopScreenService::OK, 2, 21, 92], [$p['code'], $p['types'], $p['qty'], $p['gold']]);

        $this->assertSame(ResourceShopScreenService::INVALID, $shop->bulkSell(1, null, 33)['code']);
        $this->assertSame(40, $this->owned(7), 'чужая доля не продаёт ничего');

        $s = $shop->bulkSell(1, null, 50);
        $this->assertSame(ResourceShopScreenService::OK, $s['code'], $s['message']);
        $this->assertSame(20, $this->owned(7));
        $this->assertSame(195.0, $this->gold());
    }

    public function testBotHubRowsComeFromTheModel(): void
    {
        $this->assertSame([
            [['text' => '💰 Продать ресы', 'callback_data' => 'sell'], ['text' => '🛍️ Купить ресы', 'callback_data' => 'buy']],
            [['text' => '💰 Продать крафт', 'callback_data' => 'sellCraft'], ['text' => '🛍️ Купить крафт', 'callback_data' => 'buyCraft']],
            [['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'], ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory']],
        ], ShopAction::hubRows((new ResourceShopScreenService())->hubEntries()));
    }

    private function owned(int $resourceId): int
    {
        $row = Database::connect('tests')->query('SELECT COALESCE(SUM(quantity), 0) AS q FROM character_resources WHERE id_characters = 1 AND id_resources = ?', [$resourceId])->getRowArray();

        return (int) ($row['q'] ?? 0);
    }

    private function gold(): float
    {
        return (float) (Database::connect('tests')->query('SELECT gold FROM characters WHERE id = 1')->getRowArray()['gold'] ?? -1);
    }

    private function bank(string $column): int
    {
        $row = Database::connect('tests')->query("SELECT COALESCE(SUM({$column}), 0) AS q FROM resources_bank")->getRowArray();

        return (int) ($row['q'] ?? 0);
    }
}
