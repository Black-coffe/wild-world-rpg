<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Quest;

use App\Services\Quest\DailyTaskCatalog;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-109 (E8) — DailyTaskCatalog: tier-масштабирование + level-гейтинг. Чистая логика.
 *
 * @internal
 */
final class DailyTaskCatalogTest extends CIUnitTestCase
{
    public function testTierIndexByLevel(): void
    {
        $this->assertSame(0, DailyTaskCatalog::tierIndex(1));
        $this->assertSame(0, DailyTaskCatalog::tierIndex(9));
        $this->assertSame(1, DailyTaskCatalog::tierIndex(10));
        $this->assertSame(1, DailyTaskCatalog::tierIndex(24));
        $this->assertSame(2, DailyTaskCatalog::tierIndex(25));
        $this->assertSame(2, DailyTaskCatalog::tierIndex(100));
    }

    public function testAvailableForGatesByMinLevel(): void
    {
        // L1: explore/craft/sell (min_level 1), без hunt(3)/combat(5).
        $l1 = array_column(DailyTaskCatalog::availableFor(1), 'key');
        $this->assertContains('d_explore', $l1);
        $this->assertContains('d_craft', $l1);
        $this->assertContains('d_sell', $l1);
        $this->assertNotContains('d_hunt', $l1);
        $this->assertNotContains('d_battle', $l1);

        // L5: всё доступно.
        $l5 = array_column(DailyTaskCatalog::availableFor(5), 'key');
        $this->assertContains('d_hunt', $l5);
        $this->assertContains('d_battle', $l5);
        $this->assertCount(5, $l5);

        // L3: +hunt, ещё без combat.
        $l3 = array_column(DailyTaskCatalog::availableFor(3), 'key');
        $this->assertContains('d_hunt', $l3);
        $this->assertNotContains('d_battle', $l3);
    }

    public function testQtyAndGoldScaleWithTier(): void
    {
        // d_explore qty [5,8,12], gold [80,150,250].
        $this->assertSame(5, DailyTaskCatalog::qtyFor('d_explore', 1));
        $this->assertSame(8, DailyTaskCatalog::qtyFor('d_explore', 10));
        $this->assertSame(12, DailyTaskCatalog::qtyFor('d_explore', 25));
        $this->assertSame(80, DailyTaskCatalog::goldFor('d_explore', 1));
        $this->assertSame(250, DailyTaskCatalog::goldFor('d_explore', 30));
    }

    public function testFindUnknownReturnsNullAndZero(): void
    {
        $this->assertNull(DailyTaskCatalog::find('nope'));
        $this->assertSame(0, DailyTaskCatalog::qtyFor('nope', 10));
        $this->assertSame(0, DailyTaskCatalog::goldFor('nope', 10));
    }
}
