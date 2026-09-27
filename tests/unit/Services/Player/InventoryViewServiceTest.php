<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Player;

use App\Services\Player\InventoryViewService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * W2.N1-02 (ADR-190) — модель инвентаря: одни и те же строки для бота и веба.
 *
 * Бот рисует «Добытые» и «Крафтовые» из `gathered()`/`crafted()`, веб — единый список из
 * `build()`. Тест держит: каждая строка попадает в список с тем же количеством, полки идут в
 * порядке бота, неизвестный тип уходит в «Прочие», еда из крафта помечена, стоимость считается
 * только по добытому (как «Общая стоимость» в боте).
 *
 * @internal
 */
final class InventoryViewServiceTest extends CIUnitTestCase
{
    public function testEveryRowLandsOnItsShelfWithTheSameQuantity(): void
    {
        $inv = InventoryViewService::build(
            [
                ['quantity' => '1204', 'name' => 'Металлолом', 'rarity' => '2', 'price' => '0.5'],
                ['quantity' => 3, 'name' => 'Кварц', 'rarity' => 5, 'price' => 40],
            ],
            [
                ['quantity' => '2', 'name_rus' => 'Рыбный суп', 'type' => 'food', 'price' => '10', 'name' => 'Рыбный суп'],
                ['quantity' => '1', 'name_rus' => 'Кирка', 'type' => 'tool', 'price' => '100', 'name' => 'Кирка'],
                ['quantity' => '1', 'name_rus' => 'Посох', 'type' => 'magical item', 'price' => '5', 'name' => 'Посох'],
                ['quantity' => '7', 'name_rus' => 'Нечто', 'type' => 'brand_new_type', 'price' => '1', 'name' => 'Нечто'],
            ]
        );

        $flat = array_map(static fn (array $i): array => [$i['name'], $i['quantity'], $i['category']], $inv['items']);
        $this->assertSame([
            ['Металлолом', 1204, 'resources'],
            ['Кварц', 3, 'resources'],
            ['Рыбный суп', 2, 'food'],
            ['Кирка', 1, 'tool'],
            ['Посох', 1, 'magical'],
            ['Нечто', 7, 'other'],
        ], $flat);

        $this->assertSame(
            ['resources', 'food', 'tool', 'magical', 'other'],
            array_column($inv['categories'], 'key'),
            'полки — в порядке бота, «Прочие» последними'
        );
        $this->assertSame([2, 1, 1, 1, 1], array_column($inv['categories'], 'count'));
        $this->assertSame(1204 * 0.5 + 3 * 40.0, $inv['total_value'], 'стоимость — только добытое, как в боте');
    }

    public function testFoodIsMarkedAndResourceKeepsRarity(): void
    {
        $inv = InventoryViewService::build(
            [['quantity' => 1, 'name' => 'Кварц', 'rarity' => '5', 'price' => 1]],
            [['quantity' => 1, 'name_rus' => 'Суп', 'type' => 'food', 'price' => 1], ['quantity' => 1, 'name_rus' => 'Бинт', 'type' => 'drug', 'price' => 1]]
        );

        $this->assertSame('5', $inv['items'][0]['rarity']);
        $this->assertFalse($inv['items'][0]['food_unused']);
        $this->assertTrue($inv['items'][1]['food_unused']);
        $this->assertNull($inv['items'][1]['rarity']);
        $this->assertFalse($inv['items'][2]['food_unused']);
    }

    public function testEmptyInventoryHasNoShelves(): void
    {
        $this->assertSame(['items' => [], 'categories' => [], 'total_value' => 0.0], InventoryViewService::build([], []));
    }

    public function testShelfKeysAreSafeTabIds(): void
    {
        foreach (array_merge(array_values(InventoryViewService::CRAFTED_TYPES), [InventoryViewService::OTHER, InventoryViewService::RESOURCES]) as $meta) {
            $this->assertMatchesRegularExpression('/^[a-z]+$/', $meta['key'], "ключ полки «{$meta['title']}» идёт в id и якорь");
        }
        $this->assertSame('other', InventoryViewService::categoryOf('unknown'));
        $this->assertSame('magical', InventoryViewService::categoryOf('magical item'));
    }
}
