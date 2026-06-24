<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Trade;

use App\Controllers\Telegram\Commands\Actions\Sell\BulkSellAction;
use App\Services\Player\Trade\ResourceTradeService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-096 — оптовая продажа. Чистая балансовая математика без БД (по образцу
 * ShuffleResources): планировщик доли (floor per-resource, skip zero-price/non-tradeable,
 * clamp процента), парсер CSV процентов и сборка ряда кнопок.
 *
 * @internal
 */
final class BulkSellPlanTest extends CIUnitTestCase
{
    /**
     * @param array<string,mixed> $over
     * @return array{id:int,charResId:int,quantity:int,sell_price:float,is_tradeable:int,rarity:int,name:string,icon:string}
     */
    private function row(array $over = []): array
    {
        return array_merge([
            'id'           => 1,
            'charResId'    => 10,
            'quantity'     => 100,
            'sell_price'   => 2.0,
            'is_tradeable' => 1,
            'rarity'       => 3,
            'name'         => 'Камень',
            'icon'         => '🪨',
        ], $over);
    }

    public function testFloorsPerResourceQuantityAndSumsGold(): void
    {
        // 10% от 100 = 10 ед.; 10% от 55 = 5 ед. (floor 5.5)
        $rows = [
            $this->row(['id' => 1, 'charResId' => 11, 'quantity' => 100, 'sell_price' => 2.0]),
            $this->row(['id' => 2, 'charResId' => 12, 'quantity' => 55,  'sell_price' => 4.0]),
        ];
        $plan = ResourceTradeService::planBulkSale($rows, 10);

        $this->assertSame(2, $plan['typesCount']);
        $this->assertSame(15, $plan['totalQty']);  // 10 + 5
        $this->assertSame(40, $plan['totalGold']); // 10*2 + 5*4
    }

    public function testSkipsZeroPriceAndNonTradeable(): void
    {
        $rows = [
            $this->row(['id' => 1, 'charResId' => 11, 'quantity' => 100, 'sell_price' => 0.0]),                      // цена 0 — пропуск
            $this->row(['id' => 2, 'charResId' => 12, 'quantity' => 100, 'sell_price' => 5.0, 'is_tradeable' => 0]), // bound — пропуск
            $this->row(['id' => 3, 'charResId' => 13, 'quantity' => 100, 'sell_price' => 3.0]),                      // ок
        ];
        $plan = ResourceTradeService::planBulkSale($rows, 50);

        $this->assertSame(1, $plan['typesCount']);
        $this->assertSame(50, $plan['totalQty']);
        $this->assertSame(150, $plan['totalGold']); // 50*3
        $this->assertSame(3, $plan['lines'][0]['id']);
        $this->assertSame(13, $plan['lines'][0]['charResId']);
    }

    public function testSmallStackBelowPercentYieldsNothing(): void
    {
        // 10% от 5 = 0.5 → floor 0 → ресурс пропущен целиком
        $plan = ResourceTradeService::planBulkSale([$this->row(['quantity' => 5])], 10);

        $this->assertSame(0, $plan['typesCount']);
        $this->assertSame(0, $plan['totalQty']);
        $this->assertSame(0, $plan['totalGold']);
        $this->assertSame([], $plan['lines']);
    }

    public function testPercentClampedToValidRange(): void
    {
        // 0 → clamp до 1%; 1% от 100 = 1
        $low = ResourceTradeService::planBulkSale([$this->row(['quantity' => 100, 'sell_price' => 1.0])], 0);
        $this->assertSame(1, $low['totalQty']);

        // 999 → clamp до 100%; 100% от 100 = 100
        $high = ResourceTradeService::planBulkSale([$this->row(['quantity' => 100, 'sell_price' => 1.0])], 999);
        $this->assertSame(100, $high['totalQty']);
    }

    public function testGoldRoundsFromUnitPrice(): void
    {
        // 50% от 6 = 3 ед.; 3 × 2.5 = 7.5 → round = 8💰
        $plan = ResourceTradeService::planBulkSale([$this->row(['quantity' => 6, 'sell_price' => 2.5])], 50);

        $this->assertSame(3, $plan['totalQty']);
        $this->assertSame(8, $plan['totalGold']);
    }

    public function testEmptyInputIsHandled(): void
    {
        $plan = ResourceTradeService::planBulkSale([], 50);

        $this->assertSame(0, $plan['typesCount']);
        $this->assertSame(0, $plan['totalGold']);
        $this->assertSame([], $plan['lines']);
    }

    public function testParsePercentsSortsDedupesAndClamps(): void
    {
        $this->assertSame([10, 25, 50], BulkSellAction::parsePercents('50,10,25,10'));
        $this->assertSame([5, 10], BulkSellAction::parsePercents(' 10 , 5 '));
        $this->assertSame([1, 100], BulkSellAction::parsePercents('0,1,100,101,200')); // 0/101/200 вне 1..100
    }

    public function testParsePercentsFallbackOnGarbage(): void
    {
        $this->assertSame([10, 25, 50], BulkSellAction::parsePercents(''));
        $this->assertSame([10, 25, 50], BulkSellAction::parsePercents('abc,-5,0'));
    }

    public function testButtonsRowAllScope(): void
    {
        $row = BulkSellAction::buttonsRow('all', [10, 25, 50]);

        $this->assertCount(3, $row);
        $this->assertSame('💰 10%', $row[0]['text']);
        $this->assertSame('bulkSell_all_10', $row[0]['callback_data']);
        $this->assertSame('bulkSell_all_50', $row[2]['callback_data']);
    }

    public function testButtonsRowRarityScopeResolvesToBulkSellSegment(): void
    {
        $row = BulkSellAction::buttonsRow('rarity_3', [25]);

        $this->assertCount(1, $row);
        $this->assertSame('bulkSell_rarity_3_25', $row[0]['callback_data']);
        // Первый сегмент — exact-роут 'bulkSell' (резолв по explode('_')[0]).
        $this->assertSame('bulkSell', explode('_', $row[0]['callback_data'])[0]);
    }

    /**
     * E28 «Продать всё»: 100% рендерится отдельной меткой «🧺 Всё» (не «💰 100%»),
     * callback остаётся в bulkSell-сегменте (тот же confirm-flow). Частичные доли
     * сохраняют метку «💰 N%».
     */
    public function testButtonsRowSellAllOptionUsesDistinctLabel(): void
    {
        $row = BulkSellAction::buttonsRow('all', [10, 50, 100]);

        $this->assertCount(3, $row);
        $this->assertSame('💰 10%', $row[0]['text']);
        $this->assertSame('🧺 Всё', $row[2]['text']);
        $this->assertSame('bulkSell_all_100', $row[2]['callback_data']);
        $this->assertSame('bulkSell', explode('_', $row[2]['callback_data'])[0]);
    }

    /** Новый дефолт включает 100 (продать всё) — варианты парсятся как 10/25/50/100. */
    public function testDefaultPercentsIncludeSellAll(): void
    {
        $this->assertSame([10, 25, 50, 100], BulkSellAction::parsePercents(BulkSellAction::DEFAULT_PERCENTS));
    }
}
