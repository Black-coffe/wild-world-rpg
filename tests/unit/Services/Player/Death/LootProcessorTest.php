<?php

namespace Tests\Unit\Services\Player\Death;

use App\Services\Player\Death\LootProcessor;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * F2.8b — unit-тесты на чистые compute-методы LootProcessor.
 *
 * apply* и transfer* методы — DB writes, тестируются интеграционными
 * тестами через CIDatabaseTestCase (отдельный test-suite, F2.8c).
 *
 * @internal
 */
final class LootProcessorTest extends CIUnitTestCase
{
    private LootProcessor $proc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->proc = new LootProcessor();
    }

    public function testComputeResourceLossEmptyArray(): void
    {
        $this->assertSame([], $this->proc->computeResourceLoss([], 0.5));
    }

    public function testComputeResourceLossThreePercent(): void
    {
        $loserResources = [
            ['id' => 1, 'id_resources' => 5,  'quantity' => 1000],   // 30 lost
            ['id' => 2, 'id_resources' => 8,  'quantity' => 100],    // 3 lost
            ['id' => 3, 'id_resources' => 11, 'quantity' => 33],     // 0 (floor 0.99)
        ];
        $result = $this->proc->computeResourceLoss($loserResources, 0.03);

        $this->assertCount(2, $result);  // только 2 записи — третья floor=0
        $this->assertSame(['charResId' => 1, 'resourceId' => 5, 'lossAmount' => 30], $result[0]);
        $this->assertSame(['charResId' => 2, 'resourceId' => 8, 'lossAmount' => 3], $result[1]);
    }

    public function testComputeResourceLossFiftyPercent(): void
    {
        $loserResources = [
            ['id' => 1, 'id_resources' => 5,  'quantity' => 1000],  // 500 lost
        ];
        $result = $this->proc->computeResourceLoss($loserResources, 0.50);
        $this->assertCount(1, $result);
        $this->assertSame(500, $result[0]['lossAmount']);
    }

    public function testComputeResourceLossZeroPercentReturnsEmpty(): void
    {
        $loserResources = [
            ['id' => 1, 'id_resources' => 5, 'quantity' => 1000],
        ];
        $result = $this->proc->computeResourceLoss($loserResources, 0.0);
        $this->assertSame([], $result);
    }

    public function testComputeResourceLossSkipsZeroQuantity(): void
    {
        $loserResources = [
            ['id' => 1, 'id_resources' => 5, 'quantity' => 0],
            ['id' => 2, 'id_resources' => 8, 'quantity' => 100],   // 50 lost
        ];
        $result = $this->proc->computeResourceLoss($loserResources, 0.50);
        $this->assertCount(1, $result);
        $this->assertSame(2, $result[0]['charResId']);
    }

    public function testComputeCraftLossThreePercent(): void
    {
        $loserCrafted = [
            ['id' => 100, 'crafted_item_id' => 7, 'quantity' => 200],   // 6 lost
            ['id' => 101, 'crafted_item_id' => 9, 'quantity' => 33],    // 0 (floor 0.99)
        ];
        $result = $this->proc->computeCraftLoss($loserCrafted, 0.03);
        $this->assertCount(1, $result);
        $this->assertSame(['logId' => 100, 'craftedItemId' => 7, 'lossAmount' => 6], $result[0]);
    }

    public function testComputeCraftLossFiftyPercent(): void
    {
        $loserCrafted = [
            ['id' => 100, 'crafted_item_id' => 7, 'quantity' => 10],  // 5 lost
            ['id' => 101, 'crafted_item_id' => 9, 'quantity' => 1],   // 0 (floor 0.5)
        ];
        $result = $this->proc->computeCraftLoss($loserCrafted, 0.50);
        $this->assertCount(1, $result);
        $this->assertSame(5, $result[0]['lossAmount']);
    }

    public function testComputeCraftLossSkipsZero(): void
    {
        $loserCrafted = [
            ['id' => 100, 'crafted_item_id' => 7, 'quantity' => 0],
        ];
        $result = $this->proc->computeCraftLoss($loserCrafted, 0.50);
        $this->assertSame([], $result);
    }
}
