<?php

declare(strict_types=1);

namespace Tests\Database\Economy;

use App\TaskHandlers\ResourceBankUpdateHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * market-01 (`docs/specs/market-decay/`) — `ResourceBankUpdateHandler` состаривал
 * `resources_purchased`/`resources_sold` на `-1` за тик; на проде счётчики — миллионы
 * (brief.md), возврат к базовой цене занял бы 5-9 лет. Тест проверяет:
 *  - killswitch выключен (default) → поведение байт-идентично старому (`-1`, пол в нуле);
 *  - killswitch включён → пропорциональное затухание с полураспадом;
 *  - потолок счётчика втягивает исторические миллионы, сохраняя пропорцию purchased/sold;
 *  - втягивание не двигает цену на этом же тике (цена считается ДО масштабирования).
 *
 * @internal
 */
final class MarketDecayTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();

        $db = Database::connect('tests');
        foreach (['game_settings', 'resources', 'resources_bank'] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }

        $db->query('
            CREATE TABLE game_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(191) NOT NULL,
                category VARCHAR(64) NULL,
                value_type VARCHAR(16) NULL,
                value_int INT NULL,
                value_float DECIMAL(15,5) NULL,
                value_bool TINYINT NULL,
                value_string TEXT NULL,
                hard_min VARCHAR(32) NULL,
                hard_max VARCHAR(32) NULL
            )
        ');
        $db->query('
            CREATE TABLE resources (
                id INT AUTO_INCREMENT PRIMARY KEY,
                price INT NOT NULL DEFAULT 10,
                buy_price DECIMAL(10,2) NULL,
                sell_price DECIMAL(10,2) NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        $db->query('
            CREATE TABLE resources_bank (
                id INT AUTO_INCREMENT PRIMARY KEY,
                resource_id INT NOT NULL,
                current_quantity INT NULL DEFAULT 0,
                resources_purchased BIGINT NOT NULL DEFAULT 0,
                resources_sold BIGINT NOT NULL DEFAULT 0,
                last_update DATETIME NULL
            )
        ');

        $this->cleanCache();
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (['game_settings', 'resources', 'resources_bank'] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
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

    private function setGameSetting(string $key, string $type, mixed $value): void
    {
        $row = ['setting_key' => $key, 'value_type' => $type];
        switch ($type) {
            case 'bool':
                $row['value_bool'] = $value ? 1 : 0;
                break;
            case 'float':
                $row['value_float'] = $value;
                break;
            case 'int':
                $row['value_int'] = $value;
                break;
        }
        Database::connect('tests')->table('game_settings')->insert($row);
        $this->cleanCache();
    }

    private function seedResource(int $id, int $price = 10): void
    {
        Database::connect('tests')->table('resources')->insert(['id' => $id, 'price' => $price]);
    }

    private function seedBank(int $resourceId, int $purchased, int $sold): int
    {
        $db = Database::connect('tests');
        $db->table('resources_bank')->insert([
            'resource_id'          => $resourceId,
            'resources_purchased'  => $purchased,
            'resources_sold'       => $sold,
            'last_update'          => '2026-01-01 00:00:00',
        ]);

        return (int) $db->insertID();
    }

    /** @return array{purchased: int, sold: int, buy_price: float, sell_price: float} */
    private function readBank(int $bankId): array
    {
        $bank = Database::connect('tests')->table('resources_bank')->where('id', $bankId)->get()->getRowArray();

        return [
            'purchased' => (int) $bank['resources_purchased'],
            'sold'      => (int) $bank['resources_sold'],
        ];
    }

    private function readResourcePrices(int $resourceId): array
    {
        $res = Database::connect('tests')->table('resources')->where('id', $resourceId)->get()->getRowArray();

        return [
            'buy_price'  => (float) $res['buy_price'],
            'sell_price' => (float) $res['sell_price'],
        ];
    }

    public function testKillswitchOffMatchesOldMinusOneBehaviour(): void
    {
        // Killswitch не сеян в БД → default false в самом хэндлере.
        $this->seedResource(1, 10);
        $bankId = $this->seedBank(1, 5, 3);

        (new ResourceBankUpdateHandler())->process();

        $bank = $this->readBank($bankId);
        $this->assertSame(4, $bank['purchased'], 'При выключенном килсвитче состаривание обязано быть -1, как раньше.');
        $this->assertSame(2, $bank['sold'], 'При выключенном килсвитче состаривание обязано быть -1, как раньше.');
    }

    public function testKillswitchOffFloorsAtZero(): void
    {
        $this->seedResource(1, 10);
        $bankId = $this->seedBank(1, 0, 0);

        (new ResourceBankUpdateHandler())->process();

        $bank = $this->readBank($bankId);
        $this->assertSame(0, $bank['purchased']);
        $this->assertSame(0, $bank['sold']);
    }

    public function testKillswitchOffPriceFormulaUnchanged(): void
    {
        $this->seedResource(1, 10);
        // ratio = (5+1)/(3+1) = 1.5 → в коридоре, buy=10*1.5*1.05=15.75, sell=10*1.5*0.95=14.25
        $this->seedBank(1, 5, 3);

        (new ResourceBankUpdateHandler())->process();

        $prices = $this->readResourcePrices(1);
        $this->assertSame(15.75, $prices['buy_price']);
        $this->assertSame(14.25, $prices['sell_price']);
    }

    public function testProportionalDecayHalvesCounterAtOneHalfLife(): void
    {
        $this->setGameSetting('economy.market.proportional_decay_enabled', 'bool', true);
        $this->setGameSetting('economy.market.half_life_hours', 'float', 1.0);
        $this->setGameSetting('economy.market.counter_cap', 'int', 1000000); // потолок не мешает

        $this->seedResource(1, 10);
        $bankId = $this->seedBank(1, 1000, 1000);

        (new ResourceBankUpdateHandler())->process(60); // 60 минут = 1 период полураспада

        $bank = $this->readBank($bankId);
        $this->assertEqualsWithDelta(500, $bank['purchased'], 1, 'Полураспад за 1 час обязан ополовинить счётчик (±1 на округление).');
        $this->assertEqualsWithDelta(500, $bank['sold'], 1, 'Полураспад за 1 час обязан ополовинить счётчик (±1 на округление).');
    }

    public function testProportionalDecayNeverGoesNegativeAndFloorsSmallRemainder(): void
    {
        $this->setGameSetting('economy.market.proportional_decay_enabled', 'bool', true);
        $this->setGameSetting('economy.market.half_life_hours', 'float', 0.01); // почти мгновенный распад
        $this->setGameSetting('economy.market.counter_cap', 'int', 1000000);

        $this->seedResource(1, 10);
        $bankId = $this->seedBank(1, 5, 5);

        (new ResourceBankUpdateHandler())->process(60);

        $bank = $this->readBank($bankId);
        $this->assertSame(0, $bank['purchased'], 'Остаток меньше 1 после затухания обязан стать 0, а не уйти в минус.');
        $this->assertSame(0, $bank['sold'], 'Остаток меньше 1 после затухания обязан стать 0, а не уйти в минус.');
    }

    public function testCounterCapPreservesProportionWhenOverCap(): void
    {
        $this->setGameSetting('economy.market.proportional_decay_enabled', 'bool', true);
        // Полураспад огромный (счётчик почти не тает за тик), потолок маленький и активно
        // втягивает историческую сумму, сохраняя пропорцию purchased/sold.
        $this->setGameSetting('economy.market.half_life_hours', 'float', 100000.0);
        $this->setGameSetting('economy.market.counter_cap', 'int', 1000);

        $this->seedResource(1, 10);
        // 4 млн / 2 млн — пропорция 2:1, оба выше потолка 1000.
        $bankId = $this->seedBank(1, 4000000, 2000000);

        (new ResourceBankUpdateHandler())->process(1);

        $bank = $this->readBank($bankId);
        $this->assertSame(1000, $bank['purchased'], 'Максимум из двух счётчиков обязан после втягивания стать равен потолку.');
        $this->assertSame(500, $bank['sold'], 'Пропорция purchased/sold обязана сохраниться при втягивании в потолок.');
    }

    public function testCounterCapDoesNotChangePriceOnSameTick(): void
    {
        $this->setGameSetting('economy.market.proportional_decay_enabled', 'bool', true);
        $this->setGameSetting('economy.market.half_life_hours', 'float', 100000.0);
        $this->setGameSetting('economy.market.counter_cap', 'int', 1000);

        $this->seedResource(1, 10);
        // (purchased+1)/(sold+1) = 3000000/2000000 = 1.5 — тот же ratio, что и в
        // testKillswitchOffPriceFormulaUnchanged (6/4=1.5) — цена обязана совпасть,
        // посчитана ДО масштабирования (оба счётчика далеко выше потолка 1000).
        $this->seedBank(1, 2999999, 1999999);

        (new ResourceBankUpdateHandler())->process(1);

        $prices = $this->readResourcePrices(1);
        $this->assertSame(15.75, $prices['buy_price'], 'Цена считается из счётчиков ДО масштабирования потолком.');
        $this->assertSame(14.25, $prices['sell_price'], 'Цена считается из счётчиков ДО масштабирования потолком.');
    }

    public function testRowsWithoutBankEntryAreSkipped(): void
    {
        // Ресурс без записи в resources_bank — существующее поведение `continue`, крон не падает.
        $this->seedResource(1, 10);

        (new ResourceBankUpdateHandler())->process();

        $prices = Database::connect('tests')->table('resources')->where('id', 1)->get()->getRowArray();
        $this->assertNull($prices['buy_price']);
        $this->assertNull($prices['sell_price']);
    }
}
