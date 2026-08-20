<?php

namespace Tests\Unit\Services\Player\Trade;

use App\Services\Player\Trade\ResourceTradeService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Спрос-01 — гейты покупки ресурса.
 *
 * До этих гейтов сделка не смотрела ни на `is_tradeable`, ни на `level_required`:
 * семена (`is_tradeable=0`, `buy_price=0.00`) уходили из магазина бесплатно и в любом
 * количестве, а персонаж 1 уровня мог купить ресурс с `level_required=100`.
 * Продажа `is_tradeable` уважала с ADR-137 — покупка была её незакрытым близнецом.
 *
 * Здесь проверяется чистый предикат, общий для обеих сторон сделки; сами отказы
 * покупки ходят в БД и живут в database-наборе.
 *
 * @internal
 */
final class BuyResourceGatesTest extends CIUnitTestCase
{
    public function testSeedsAreNotTradeable(): void
    {
        $seed = ['id' => 78, 'name' => 'Семена ягод', 'is_tradeable' => 0, 'buy_price' => 0.00];

        $this->assertFalse(ResourceTradeService::resourceIsTradeable($seed));
    }

    public function testOrdinaryResourceIsTradeable(): void
    {
        $clay = ['id' => 13, 'name' => 'Глина', 'is_tradeable' => 1, 'buy_price' => 4.00];

        $this->assertTrue(ResourceTradeService::resourceIsTradeable($clay));
    }

    public function testEntityStyleBooleanCastIsAccepted(): void
    {
        $this->assertFalse(ResourceTradeService::resourceIsTradeable(['is_tradeable' => false]));
        $this->assertTrue(ResourceTradeService::resourceIsTradeable(['is_tradeable' => true]));
    }

    public function testMissingColumnDefaultsToTradeable(): void
    {
        $this->assertTrue(ResourceTradeService::resourceIsTradeable(['id' => 1]));
    }

    /**
     * Ценник бесплатного товара: до фикса `totalFor(qty, 0.0)` давал 0 и списания не было
     * вовсе — покупка семян обходилась даром. Гейт выше не даёт дойти до этой арифметики,
     * но сама арифметика обязана остаться честной.
     */
    public function testZeroPriceCostsZero(): void
    {
        $svc = new ResourceTradeService();

        $this->assertSame(0, $svc->totalFor(1000, 0.0));
    }
}
