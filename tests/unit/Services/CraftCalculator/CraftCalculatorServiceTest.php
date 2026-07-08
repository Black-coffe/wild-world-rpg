<?php

declare(strict_types=1);

namespace Tests\Unit\Services\CraftCalculator;

use App\Services\CraftCalculator\CraftCalculatorService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-149 — ядро калькулятора крафта. Все data-seams переопределены фикстурами,
 * DB не трогается (чистый unit).
 *
 * @internal
 */
final class CraftCalculatorServiceTest extends CIUnitTestCase
{
    /**
     * @param array<string, array<string, mixed>> $recipes
     * @param array<string, array{buy: float, price: float, rarity: int, name_en: string, icon: string, tradeable: bool}> $resourceIndex
     */
    private function svc(array $recipes, array $resourceIndex): CraftCalculatorService
    {
        return new class ($recipes, $resourceIndex) extends CraftCalculatorService {
            /**
             * @param array<string, array<string, mixed>> $r
             * @param array<string, array{buy: float, price: float, rarity: int, name_en: string, icon: string, tradeable: bool}> $ri
             */
            public function __construct(private array $r, private array $ri)
            {
                parent::__construct();
            }

            protected function loadRecipeMap(): array
            {
                return $this->r;
            }

            protected function loadResourceIndex(): array
            {
                return $this->ri;
            }
        };
    }

    /** @return array<string, array<string, mixed>> */
    private function recipes(): array
    {
        return [
            'Bandage' => [
                'resources'     => ['Травы' => 2, 'Водоросли' => 3],
                'crafted_items' => [],
                'item_name_eng' => 'Bandage',
                'item_name_rus' => 'Повязка',
                'icon_emoji'    => '🩹',
                'zone_name'     => 'медицина',
                'zone_emoji'    => '💊',
            ],
            'BasicMedKit' => [
                'resources'     => ['Грибы' => 4],
                'crafted_items' => ['Bandage' => 5], // ссылка по КЛЮЧУ рецепта
                'item_name_eng' => 'FirstAidKit',
                'item_name_rus' => 'Аптечка',
                'icon_emoji'    => '🚑',
                'zone_name'     => 'медицина',
                'zone_emoji'    => '💊',
            ],
            'MetalFragments' => [
                'resources'     => ['Руда' => 3],
                'crafted_items' => [],
                'item_name_eng' => 'metalFragments', // ключ MetalFragments ≠ name_eng
                'item_name_rus' => 'Металлолом',
                'icon_emoji'    => '⚙️',
                'zone_name'     => 'производство',
            ],
            'Wiring' => [
                'resources'     => ['Мхи' => 2],
                'crafted_items' => ['metalFragments' => 3], // ссылка по item_name_eng!
                'item_name_eng' => 'wiring',
                'item_name_rus' => 'Проводка',
                'icon_emoji'    => '🔌',
                'zone_name'     => 'производство',
            ],
            'Workbench' => [
                'resources'     => ['Дерево' => 10],
                'crafted_items' => [],
                'gold_required' => 20000,
                'item_name_eng' => 'Workbench',
                'item_name_rus' => 'Верстак',
                'icon_emoji'    => '🛠',
                'zone_name'     => 'база',
            ],
            'Broken' => [
                'resources'     => ['Мхи' => 1],
                'crafted_items' => ['Ghost' => 2], // не резолвится ни в ключ, ни в name_eng
                'item_name_eng' => 'Broken',
                'item_name_rus' => 'Сломанное',
                'zone_name'     => 'прочее',
            ],
            'Special' => [
                'resources'     => ['Тайна' => 2], // сырьё без цены в индексе
                'crafted_items' => [],
                'item_name_eng' => 'Special',
                'item_name_rus' => 'Особое',
                'zone_name'     => 'прочее',
            ],
            'RelicItem' => [
                'resources'     => ['Реликт' => 2], // сырьё с ценой, но is_tradeable=0
                'crafted_items' => [],
                'item_name_eng' => 'RelicItem',
                'item_name_rus' => 'Реликвия',
                'zone_name'     => 'прочее',
            ],
            // Синтетический цикл A→B→A
            'A' => [
                'resources'     => ['Травы' => 1],
                'crafted_items' => ['B' => 1],
                'item_name_eng' => 'A',
                'item_name_rus' => 'А',
                'zone_name'     => 'прочее',
            ],
            'B' => [
                'resources'     => ['Мхи' => 1],
                'crafted_items' => ['A' => 1],
                'item_name_eng' => 'B',
                'item_name_rus' => 'Б',
                'zone_name'     => 'прочее',
            ],
        ];
    }

    /** @return array<string, array{buy: float, price: float, rarity: int, name_en: string, icon: string, tradeable: bool}> */
    private function prices(): array
    {
        $mk = static fn (float $buy, int $rarity, string $en, bool $tradeable = true): array => [
            'buy' => $buy, 'price' => $buy, 'rarity' => $rarity, 'name_en' => $en, 'icon' => '', 'tradeable' => $tradeable,
        ];

        return [
            'Травы'     => $mk(5.0, 1, 'Herbs'),
            'Водоросли' => $mk(8.0, 1, 'Algae'),
            'Грибы'     => $mk(10.0, 2, 'Mushrooms'),
            'Руда'      => $mk(20.0, 3, 'Ore'),
            'Мхи'       => $mk(3.0, 1, 'Moss'),
            'Дерево'    => $mk(4.0, 1, 'Wood'),
            'Реликт'    => $mk(50.0, 9, 'Relic', false), // цена есть, но у торговца НЕ купить
            // 'Тайна' намеренно отсутствует — тест беcценового сырья
        ];
    }

    /**
     * @param list<array{name: string, name_en: string, qty: int, rarity: int, icon: string, buy: float, lineGold: float, priced: bool}> $raw
     * @return array<string, int>
     */
    private function rawMap(array $raw): array
    {
        $out = [];
        foreach ($raw as $r) {
            $out[$r['name']] = $r['qty'];
        }
        return $out;
    }

    /**
     * Порядок в rawResources — по qty desc (для UI), поэтому сравниваем спецификацию
     * order-insensitive (ksort обеих сторон).
     *
     * @param array<string, int> $expected
     * @param list<array{name: string, name_en: string, qty: int, rarity: int, icon: string, buy: float, lineGold: float, priced: bool}> $rawList
     */
    private function assertRawEquals(array $expected, array $rawList): void
    {
        $actual = $this->rawMap($rawList);
        ksort($actual);
        ksort($expected);
        $this->assertSame($expected, $actual);
    }

    public function testLeafRecipeExpandsToDirectResources(): void
    {
        $r = $this->svc($this->recipes(), $this->prices())->expand('Bandage', 1);

        $this->assertTrue($r['found']);
        $this->assertSame('Повязка', $r['name']);
        $this->assertRawEquals(['Травы' => 2, 'Водоросли' => 3], $r['rawResources']);
        $this->assertSame([], $r['subCrafts']);
        // 2*5 + 3*8 = 34
        $this->assertSame(34.0, $r['goldMarket']);
        $this->assertSame(34.0, $r['goldTotal']);
        $this->assertSame(0.0, $r['goldRequired']);
    }

    public function testQtyMultiplierScalesResourcesAndGold(): void
    {
        $r = $this->svc($this->recipes(), $this->prices())->expand('Bandage', 3);

        $this->assertRawEquals(['Травы' => 6, 'Водоросли' => 9], $r['rawResources']);
        $this->assertSame(102.0, $r['goldMarket']); // 34 * 3
        $this->assertSame(3, $r['qty']);
    }

    public function testOneLevelRecursionByRecipeKey(): void
    {
        $r = $this->svc($this->recipes(), $this->prices())->expand('BasicMedKit', 1);

        // Грибы 4 (direct) + Bandage×5 → Травы 10, Водоросли 15
        $this->assertRawEquals(['Грибы' => 4, 'Травы' => 10, 'Водоросли' => 15], $r['rawResources']);
        $this->assertSame([['recipe' => 'Bandage', 'name' => 'Повязка', 'icon' => '🩹', 'qty' => 5]], $r['subCrafts']);
        // 4*10 + 10*5 + 15*8 = 40 + 50 + 120 = 210
        $this->assertSame(210.0, $r['goldMarket']);
    }

    public function testRecursionResolvesByItemNameEng(): void
    {
        // Wiring → crafted_items['metalFragments'] должно резолвиться в рецепт 'MetalFragments'.
        $r = $this->svc($this->recipes(), $this->prices())->expand('Wiring', 1);

        // Мхи 2 (direct) + metalFragments×3 → Руда 3 each → Руда 9
        $this->assertRawEquals(['Мхи' => 2, 'Руда' => 9], $r['rawResources']);
        $this->assertSame('MetalFragments', $r['subCrafts'][0]['recipe']);
        $this->assertSame(3, $r['subCrafts'][0]['qty']);
        $this->assertSame([], $r['unresolved']);
        // 2*3 + 9*20 = 6 + 180 = 186
        $this->assertSame(186.0, $r['goldMarket']);
    }

    public function testGoldRequiredIsCountedSeparatelyAndScaled(): void
    {
        $r = $this->svc($this->recipes(), $this->prices())->expand('Workbench', 2);

        $this->assertSame(40000.0, $r['goldRequired']); // 20000 * 2
        $this->assertSame(80.0, $r['goldMarket']);       // Дерево 20 * 4
        $this->assertSame(40080.0, $r['goldTotal']);
    }

    public function testUnresolvedCraftRefIsCollectedNotDropped(): void
    {
        $r = $this->svc($this->recipes(), $this->prices())->expand('Broken', 1);

        $this->assertSame(['Ghost' => 2], $r['unresolved']);
        $this->assertRawEquals(['Мхи' => 1], $r['rawResources']);
    }

    public function testUnpricedResourceIsCountedButNotAddedToGold(): void
    {
        $r = $this->svc($this->recipes(), $this->prices())->expand('Special', 1);

        $this->assertRawEquals(['Тайна' => 2], $r['rawResources']);
        $this->assertFalse($r['rawResources'][0]['priced']);
        $this->assertSame(0.0, $r['goldMarket']);
    }

    public function testNonTradeableResourceIsCountedButNotPricedNorInGold(): void
    {
        // Реликт имеет buy=50, но is_tradeable=0 → у торговца не купить.
        $r = $this->svc($this->recipes(), $this->prices())->expand('RelicItem', 1);

        $this->assertRawEquals(['Реликт' => 2], $r['rawResources']);
        $this->assertFalse($r['rawResources'][0]['priced']); // не помечен покупаемым
        $this->assertSame(0.0, $r['goldMarket']);            // не попал в стоимость закупки
    }

    public function testCycleGuardTerminatesAndDoesNotInfiniteLoop(): void
    {
        $r = $this->svc($this->recipes(), $this->prices())->expand('A', 1);

        $this->assertTrue($r['found']);
        // Оба узла присутствуют в суб-крафтах, но рекурсия оборвана на цикле.
        $keys = array_column($r['subCrafts'], 'recipe');
        $this->assertContains('B', $keys);
        $this->assertContains('A', $keys);
        // Cycle-guard обрывает рекурсию на B (уже в inFlight): A входит дважды до обрыва
        // (Травы 2), B — раз (Мхи 1). Ключевое — ТЕРМИНАЦИЯ без взрыва, а не единичный обход.
        $this->assertRawEquals(['Травы' => 2, 'Мхи' => 1], $r['rawResources']);
    }

    // ── E1.1 — вложенное дерево крафта (разворот по клику) ────────────────────

    public function testTreeLeafHasNoChildrenAndScaledSortedRaw(): void
    {
        $r    = $this->svc($this->recipes(), $this->prices())->expand('Bandage', 2);
        $tree = $r['tree'];

        $this->assertIsArray($tree);
        $this->assertSame('Bandage', $tree['recipe']);
        $this->assertSame(2, $tree['qty']);
        $this->assertSame([], $tree['children']);
        // directRaw ×2, отсортировано по qty desc: Водоросли 6, Травы 4.
        $this->assertSame('Водоросли', $tree['directRaw'][0]['name']);
        $this->assertSame(6, $tree['directRaw'][0]['qty']);
        $this->assertSame(4, $tree['directRaw'][1]['qty']);
    }

    public function testTreeNestedChildCarriesScaledQtyAndOwnRaw(): void
    {
        $r    = $this->svc($this->recipes(), $this->prices())->expand('BasicMedKit', 1);
        $tree = $r['tree'];

        $this->assertIsArray($tree);
        // Прямое сырьё корня: Грибы 4 (rarity 2).
        $this->assertSame([['name' => 'Грибы', 'icon' => '', 'rarity' => 2, 'qty' => 4]], $tree['directRaw']);
        // Один дочерний узел — Повязка ×5 со СВОИМ развёрнутым сырьём.
        $this->assertCount(1, $tree['children']);
        $child = $tree['children'][0];
        $this->assertSame('Bandage', $child['recipe']);
        $this->assertSame(5, $child['qty']);
        $this->assertFalse($child['cyclic']);
        $this->assertSame([], $child['children']);
        $childRaw = [];
        foreach ($child['directRaw'] as $rr) {
            $childRaw[$rr['name']] = $rr['qty'];
        }
        ksort($childRaw);
        $this->assertSame(['Водоросли' => 15, 'Травы' => 10], $childRaw);
    }

    public function testTreeResolvesChildByItemNameEng(): void
    {
        $r    = $this->svc($this->recipes(), $this->prices())->expand('Wiring', 1);
        $tree = $r['tree'];

        $this->assertIsArray($tree);
        $this->assertCount(1, $tree['children']);
        $this->assertSame('MetalFragments', $tree['children'][0]['recipe']);
        $this->assertSame(3, $tree['children'][0]['qty']);
    }

    public function testTreeCycleGuardMarksLeafAndTerminates(): void
    {
        $r    = $this->svc($this->recipes(), $this->prices())->expand('A', 1);
        $tree = $r['tree'];

        // A → B → A → B(cyclic-лист, не разворачивается глубже) — рекурсия конечна.
        $this->assertIsArray($tree);
        $this->assertSame('A', $tree['recipe']);
        $b = $tree['children'][0];
        $this->assertSame('B', $b['recipe']);
        $this->assertFalse($b['cyclic']);
        $a2 = $b['children'][0];
        $this->assertSame('A', $a2['recipe']);
        $this->assertFalse($a2['cyclic']);
        $bLeaf = $a2['children'][0];
        $this->assertSame('B', $bLeaf['recipe']);
        $this->assertTrue($bLeaf['cyclic']);
        $this->assertSame([], $bLeaf['children']);
    }

    public function testTreeIsNullWhenRecipeNotFound(): void
    {
        $r = $this->svc($this->recipes(), $this->prices())->expand('DoesNotExist', 1);
        $this->assertNull($r['tree']);
    }

    public function testUnknownRecipeReturnsNotFound(): void
    {
        $r = $this->svc($this->recipes(), $this->prices())->expand('DoesNotExist', 1);

        $this->assertFalse($r['found']);
        $this->assertSame([], $r['rawResources']);
        $this->assertSame(0.0, $r['goldTotal']);
    }

    public function testQtyIsClampedToAtLeastOne(): void
    {
        $r = $this->svc($this->recipes(), $this->prices())->expand('Bandage', 0);
        $this->assertSame(1, $r['qty']);
    }

    public function testCatalogGroupsByZone(): void
    {
        $catalog = $this->svc($this->recipes(), $this->prices())->catalog();

        $zones = array_column($catalog, 'zone');
        $this->assertContains('медицина', $zones);
        $this->assertContains('производство', $zones);

        // В зоне «медицина» есть и Повязка, и Аптечка.
        $med = array_values(array_filter($catalog, static fn (array $g): bool => $g['zone'] === 'медицина'))[0];
        $names = array_column($med['items'], 'name');
        $this->assertContains('Повязка', $names);
        $this->assertContains('Аптечка', $names);
    }

    public function testCatalogLabelsCraftedIngredientByRussianName(): void
    {
        $catalog = $this->svc($this->recipes(), $this->prices())->catalog();
        $med = array_values(array_filter($catalog, static fn (array $g): bool => $g['zone'] === 'медицина'))[0];
        $kit = array_values(array_filter($med['items'], static fn (array $i): bool => $i['recipe'] === 'BasicMedKit'))[0];

        // Прямой ингредиент-крафт показан как «Повязка» (RU-имя), а не ключ 'Bandage'.
        $crafted = array_values(array_filter($kit['direct'], static fn (array $d): bool => $d['kind'] === 'crafted'))[0];
        $this->assertSame('Повязка', $crafted['name']);
        $this->assertSame(5, $crafted['qty']);
    }

    public function testItemListSortedByRussianName(): void
    {
        $list = $this->svc($this->recipes(), $this->prices())->itemList();

        $names = array_column($list, 'name');
        $sorted = $names;
        usort($sorted, static fn (string $a, string $b): int => strcmp($a, $b));
        $this->assertSame($sorted, $names);
        $this->assertContains('Повязка', $names);
    }
}
