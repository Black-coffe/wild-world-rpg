<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Player;

use App\Services\Player\InventorySortService as S;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * W8 — чистая сортировка инвентаря/склада.
 *
 * @internal
 */
final class InventorySortServiceTest extends CIUnitTestCase
{
    /** @return list<array<string,mixed>> */
    private function rows(): array
    {
        return [
            ['name' => 'Берёза',  'rarity' => '2', 'quantity' => 5,  'price' => 10.0, 'weight' => 1.0],
            ['name' => 'Алмаз',   'rarity' => '5', 'quantity' => 2,  'price' => 100.0, 'weight' => 0.5],
            ['name' => 'Веточка', 'rarity' => '1', 'quantity' => 50, 'price' => 1.0,  'weight' => 0.1],
        ];
    }

    private function names(array $rows): array
    {
        return array_map(static fn ($r) => $r['name'], $rows);
    }

    public function testSortByNameAscending(): void
    {
        $out = S::sortRows($this->rows(), S::MODE_NAME);
        $this->assertSame(['Алмаз', 'Берёза', 'Веточка'], $this->names($out));
    }

    public function testSortByQuantityDescending(): void
    {
        $out = S::sortRows($this->rows(), S::MODE_QTY);
        $this->assertSame(['Веточка', 'Берёза', 'Алмаз'], $this->names($out)); // 50, 5, 2
    }

    public function testSortByValueDescending(): void
    {
        // value = price * qty: Берёза 50, Алмаз 200, Веточка 50 → Алмаз, затем стабильно Берёза/Веточка по 50
        $out = S::sortRows($this->rows(), S::MODE_VALUE);
        $this->assertSame('Алмаз', $out[0]['name']);
        $this->assertSame(200.0, $out[0]['price'] * $out[0]['quantity']);
    }

    public function testSortByWeightDescending(): void
    {
        // weight = weight * qty: Берёза 5.0, Алмаз 1.0, Веточка 5.0 → max первым
        $out = S::sortRows($this->rows(), S::MODE_WEIGHT);
        $top = $out[0]['weight'] * $out[0]['quantity'];
        $this->assertSame(5.0, $top);
    }

    public function testSortByRarityNumericAscending(): void
    {
        $out = S::sortRows($this->rows(), S::MODE_RARITY);
        $this->assertSame(['Веточка', 'Берёза', 'Алмаз'], $this->names($out)); // rarity 1,2,5
    }

    public function testRecentIsIdentity(): void
    {
        $out = S::sortRows($this->rows(), S::MODE_RECENT);
        $this->assertSame($this->names($this->rows()), $this->names($out));
    }

    public function testUnknownModeFallsBackToRarity(): void
    {
        $out = S::sortRows($this->rows(), 'garbage');
        $this->assertSame(['Веточка', 'Берёза', 'Алмаз'], $this->names($out));
    }

    public function testSortDoesNotMutateInput(): void
    {
        $in = $this->rows();
        S::sortRows($in, S::MODE_NAME);
        $this->assertSame('Берёза', $in[0]['name']); // исходный порядок сохранён
    }

    public function testMissingFieldsGraceful(): void
    {
        $rows = [['name' => 'X'], ['name' => 'A']];
        $out  = S::sortRows($rows, S::MODE_QTY); // quantity отсутствует → 0/0, стабильно
        $this->assertCount(2, $out);
        $out2 = S::sortRows($rows, S::MODE_NAME);
        $this->assertSame(['A', 'X'], $this->names($out2));
    }

    public function testNormalizeMode(): void
    {
        $this->assertSame(S::MODE_NAME, S::normalizeMode('name', S::RESOURCE_MODES, S::MODE_RARITY));
        $this->assertSame(S::MODE_RARITY, S::normalizeMode('garbage', S::RESOURCE_MODES, S::MODE_RARITY));
        $this->assertSame(S::MODE_RECENT, S::normalizeMode(null, S::STORAGE_MODES, S::MODE_RECENT));
        // value не входит в STORAGE_MODES → fallback
        $this->assertSame(S::MODE_RECENT, S::normalizeMode('value', S::STORAGE_MODES, S::MODE_RECENT));
    }
}
