<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Player\Death\LootProcessor;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * V24 (ADR-056) — LootProcessor::computeCraftLoss пропускает `insured=1` строки.
 * Pure-функция, без DB.
 *
 * @internal
 */
final class LootProcessorInsuredSkipTest extends CIUnitTestCase
{
    public function testInsuredRowsSkipped(): void
    {
        $rows = [
            ['id' => 1, 'crafted_item_id' => 10, 'quantity' => 100, 'insured' => 0],
            ['id' => 2, 'crafted_item_id' => 20, 'quantity' => 100, 'insured' => 1],
            ['id' => 3, 'crafted_item_id' => 30, 'quantity' => 100, 'insured' => 0],
        ];
        $lost = (new LootProcessor())->computeCraftLoss($rows, 0.5);
        $this->assertCount(2, $lost);
        $logIds = array_map(static fn ($x) => $x['logId'], $lost);
        $this->assertSame([1, 3], $logIds);
    }

    public function testMissingInsuredKeyTreatedAsZero(): void
    {
        // Legacy rows без insured-колонки (до миграции V24).
        $rows = [
            ['id' => 1, 'crafted_item_id' => 10, 'quantity' => 50],
        ];
        $lost = (new LootProcessor())->computeCraftLoss($rows, 0.5);
        $this->assertCount(1, $lost);
        $this->assertSame(25, $lost[0]['lossAmount']);
    }

    public function testInsuredStringOneAlsoSkipped(): void
    {
        // CI4 Model row может вернуть insured как string '1' (depending on driver).
        $rows = [
            ['id' => 1, 'crafted_item_id' => 10, 'quantity' => 100, 'insured' => '1'],
        ];
        $lost = (new LootProcessor())->computeCraftLoss($rows, 0.5);
        $this->assertSame([], $lost);
    }
}
