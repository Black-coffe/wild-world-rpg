<?php

namespace Tests\Unit\Services\Player\Trade;

use App\Entities\ResourceEntity;
use App\Services\Player\Trade\ResourceTradeService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * WB2 (ADR-137 «Узлы») — предикат продаваемости ресурса.
 *
 * `resourceIsTradeable` закрывает дыру: одиночная `sellResource` раньше не чекала
 * is_tradeable (только оптовая planBulkSale), и связанный ресурс сливался поштучно
 * (блокер ADR-137 #5). Тест pure — без БД.
 *
 * @internal
 */
final class ResourceTradeServiceTest extends CIUnitTestCase
{
    public function testTradeableBoolTrue(): void
    {
        $this->assertTrue(ResourceTradeService::resourceIsTradeable(['is_tradeable' => true]));
    }

    public function testBoundBoolFalse(): void
    {
        $this->assertFalse(ResourceTradeService::resourceIsTradeable(['is_tradeable' => false]));
    }

    public function testTradeableIntOne(): void
    {
        $this->assertTrue(ResourceTradeService::resourceIsTradeable(['is_tradeable' => 1]));
    }

    public function testBoundIntZero(): void
    {
        $this->assertFalse(ResourceTradeService::resourceIsTradeable(['is_tradeable' => 0]));
    }

    public function testStringDigitsCoerced(): void
    {
        $this->assertTrue(ResourceTradeService::resourceIsTradeable(['is_tradeable' => '1']));
        $this->assertFalse(ResourceTradeService::resourceIsTradeable(['is_tradeable' => '0']));
    }

    public function testMissingDefaultsToTradeable(): void
    {
        // Колонки нет → по умолчанию ходовой (не ломаем старые ресурсы без флага).
        $this->assertTrue(ResourceTradeService::resourceIsTradeable([]));
    }

    public function testResourceEntityBoolCastRespected(): void
    {
        // ResourceEntity кастует is_tradeable в bool (casts) — раньше is_numeric(false)
        // съедал фильтр. Предикат обязан резать связанный Entity-ресурс.
        $bound = new ResourceEntity(['is_tradeable' => 0]);
        $this->assertFalse(ResourceTradeService::resourceIsTradeable($bound));

        $tradeable = new ResourceEntity(['is_tradeable' => 1]);
        $this->assertTrue(ResourceTradeService::resourceIsTradeable($tradeable));
    }

    /**
     * 🔴 Экран обязан показывать то число, которое проведёт сделка.
     *
     * Раньше карточка количества брала `(int) $resource['sell_price']` — обрезала
     * дробь у ЦЕНЫ и умножала, — а сделка округляла ИТОГ. На проде 2026-08-06 дробную
     * цену продажи имеют 50 ресурсов из 80, покупки — 65 из 80, а кнопки доходят до
     * 5000 единиц.
     */
    public function testScreenTotalMatchesTransactionTotalOnFractionalPrices(): void
    {
        $svc = new ResourceTradeService();

        // «Отработанные ТВЭЛы»: продажа 237.50 / покупка 262.50 — худший случай прода.
        $sellUnit = $svc->unitPrice(['sell_price' => 237.50], true);
        $buyUnit  = $svc->unitPrice(['buy_price' => 262.50], false);

        $this->assertSame(237.5, $sellUnit);
        $this->assertSame(262.5, $buyUnit);

        // Старая экранная формула: (int) цена × количество.
        $this->assertSame(1_310_000, 5000 * (int) 262.50, 'контроль: так считал экран');
        // Формула сделки — и теперь она же на экране.
        $this->assertSame(1_312_500, $svc->totalFor(5000, $buyUnit));
        $this->assertSame(1_187_500, $svc->totalFor(5000, $sellUnit));

        // Разрыв в 2500 золота был против игрока на покупке.
        $this->assertSame(2500, $svc->totalFor(5000, $buyUnit) - 5000 * (int) 262.50);
    }

    public function testTotalRoundsHalfUpLikeTheDeal(): void
    {
        $svc = new ResourceTradeService();

        $this->assertSame(14, $svc->totalFor(3, 4.5));
        $this->assertSame(5, $svc->totalFor(1, 4.5));
        $this->assertSame(0, $svc->totalFor(0, 237.5));
        $this->assertSame(0, $svc->totalFor(100, 0.0));
    }

    /**
     * 🔴 `ResourceModel::find()` отдаёт Entity — голый `array`-typehint валил продажу
     * и покупку ресурсов в TypeError (поймано полным прогоном 2026-08-06).
     */
    public function testUnitPriceAcceptsResourceEntity(): void
    {
        $svc = new ResourceTradeService();

        $this->assertSame(237.5, $svc->unitPrice(new ResourceEntity(['sell_price' => '237.50']), true));
        $this->assertSame(262.5, $svc->unitPrice(new ResourceEntity(['buy_price' => '262.50']), false));
    }

    public function testUnitPriceNarrowsGarbageToZero(): void
    {
        $svc = new ResourceTradeService();

        $this->assertSame(0.0, $svc->unitPrice([], true));
        $this->assertSame(0.0, $svc->unitPrice(['sell_price' => null], true));
        $this->assertSame(0.0, $svc->unitPrice(['sell_price' => 'дорого'], true));
        // Строка из БД — обычный случай, она обязана считаться.
        $this->assertSame(10.71, $svc->unitPrice(['sell_price' => '10.71'], true));
    }

    /** Цена за единицу на экране не должна терять дробь и не должна тащить нули. */
    public function testUnitPriceFormattingKeepsFractionDropsTrailingZeros(): void
    {
        $svc = new ResourceTradeService();

        $this->assertSame('237.5', $svc->formatUnitPrice(237.50));
        $this->assertSame('4', $svc->formatUnitPrice(4.0));
        $this->assertSame('10.71', $svc->formatUnitPrice(10.71));
        $this->assertSame('0', $svc->formatUnitPrice(0.0));
    }

    /**
     * Анти-дрейф: экраны ресурсов не имеют права снова считать цену сами.
     */
    public function testResourceScreensDelegateTotalsToTheService(): void
    {
        foreach (['SellResourceAction', 'BuyResourceAction'] as $screen) {
            $source = (string) file_get_contents(
                APPPATH . "Controllers/Telegram/Commands/Actions/Sell/{$screen}.php"
            );

            $this->assertStringContainsString('totalFor(', $source, "{$screen} считает итог сам");
            $this->assertDoesNotMatchRegularExpression(
                "/\\\$unitPrice\s*=\s*\(int\)\s*\\\$resource\[/",
                $source,
                "{$screen} снова обрезает дробь у цены"
            );
        }
    }
}
