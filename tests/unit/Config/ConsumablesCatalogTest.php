<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use CodeIgniter\Test\CIUnitTestCase;
use Config\Consumables;
use Config\Debuffs;

/**
 * Каталог полок (`Config\Consumables`): непересечение списков и связность с
 * `Config\Debuffs` — здесь, а не сканом файла, потому что именно эти инварианты
 * ломаются молча при добавлении нового предмета (docs/specs/pharmacy-split/brief.md).
 */
final class ConsumablesCatalogTest extends CIUnitTestCase
{
    public function testMedicineAndProvisionDoNotOverlap(): void
    {
        $overlap = array_intersect(Consumables::MEDICINE, Consumables::PROVISION);

        $this->assertSame([], $overlap, 'Предмет не может лежать сразу на двух полках: ' . implode(', ', $overlap));
    }

    /**
     * Каждый предмет, которым лечится хоть одна рана (`Config\Debuffs::cured_by`),
     * обязан быть на полке лекарств — иначе рана из брифа окажется нечем лечить с
     * экрана аптечки, а сам предмет молча уедет на полку с едой.
     */
    public function testEveryCuringItemIsClassifiedAsMedicine(): void
    {
        $curingItems = [];
        foreach (Debuffs::CATALOG as $row) {
            foreach ($row['cured_by'] as $itemNameEng) {
                $curingItems[$itemNameEng] = true;
            }
        }

        foreach (array_keys($curingItems) as $itemNameEng) {
            $this->assertContains(
                $itemNameEng,
                Consumables::MEDICINE,
                "«{$itemNameEng}» снимает рану по Config\\Debuffs, но не значится в Consumables::MEDICINE",
            );
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function knownItems(): array
    {
        return [
            'бинт → лекарство'    => ['Bandage', Consumables::SHELF_MEDICINE],
            'аптечка → лекарство' => ['FirstAidKit', Consumables::SHELF_MEDICINE],
            'тушёнка → провизия'  => ['StewPreserve', Consumables::SHELF_PROVISION],
            'уха → провизия'      => ['FishSoup', Consumables::SHELF_PROVISION],
        ];
    }

    /**
     * @dataProvider knownItems
     */
    public function testShelfOfKnownItems(string $nameEng, string $expectedShelf): void
    {
        $this->assertSame($expectedShelf, Consumables::shelfOf($nameEng));
    }

    /**
     * Незнакомое имя обязано уходить в провизию (см. класс-докблок Consumables) —
     * новый контент не должен молча засорять аптечку.
     */
    public function testUnknownNameFallsBackToProvision(): void
    {
        $this->assertSame(Consumables::SHELF_PROVISION, Consumables::shelfOf('ЧтоТоНовоеИзБудущегоПатча'));
    }
}
