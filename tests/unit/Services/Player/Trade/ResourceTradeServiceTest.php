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
}
