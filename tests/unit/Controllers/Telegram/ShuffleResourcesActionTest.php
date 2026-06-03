<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers\Telegram;

use App\Controllers\Telegram\Commands\Actions\Games\ShuffleResourcesAction;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Тест-дубль: пропускает родительский конструктор (модели/Telegram не нужны для формул),
 * подменяет gs*-чтения GameSettings и открывает protected-helpers для проверки.
 */
final class ShuffleResourcesActionTestDouble extends ShuffleResourcesAction
{
    public function __construct(private int $lossOverride, private string $countCsv)
    {
        // Намеренно НЕ зовём parent::__construct (нет callbackQuery/БД — тестируем чистые helpers).
    }

    protected function gsInt(string $key, int $default): int
    {
        return $this->lossOverride;
    }

    protected function gsString(string $key, string $default): string
    {
        return $this->countCsv;
    }

    public function pubReceived(int $c): int
    {
        return $this->received($c);
    }

    /** @return list<int> */
    public function pubCountOptions(): array
    {
        return $this->countOptions();
    }

    public function pubMinCount(): int
    {
        return $this->minCount();
    }

    public function pubLossPct(): int
    {
        return $this->lossPct();
    }
}

/**
 * ADR-093 «Перемешать ресурсы» — чистая балансовая математика (потеря при пересыпке,
 * парсинг вариантов количеств). Без БД (gs* подменены в тест-дубле).
 */
final class ShuffleResourcesActionTest extends CIUnitTestCase
{
    private function action(int $loss, string $countCsv): ShuffleResourcesActionTestDouble
    {
        return new ShuffleResourcesActionTestDouble($loss, $countCsv);
    }

    public function testReceivedAppliesLossWithFloor(): void
    {
        $a = $this->action(10, '5,10,25,50');
        $this->assertSame(4, $a->pubReceived(5));    // floor(4.5)
        $this->assertSame(9, $a->pubReceived(10));   // floor(9.0)
        $this->assertSame(22, $a->pubReceived(25));  // floor(22.5)
        $this->assertSame(45, $a->pubReceived(50));  // floor(45.0)
    }

    public function testZeroLossIsLossless(): void
    {
        $a = $this->action(0, '5,10,25,50');
        $this->assertSame(50, $a->pubReceived(50));
        $this->assertSame(0, $a->pubLossPct());
    }

    public function testLossPctClampedToHardRange(): void
    {
        $this->assertSame(90, $this->action(200, '5')->pubLossPct(), 'верхний clamp 90%');
        $this->assertSame(0, $this->action(-5, '5')->pubLossPct(), 'нижний clamp 0%');
        $this->assertSame(0, $this->action(95, '5')->pubReceived(5), 'при clamp 90% от 5 = floor(0.5)=0');
    }

    public function testCountOptionsParsedSortedDeduped(): void
    {
        $this->assertSame([5, 10, 25, 50], $this->action(10, '50,5,25,10,5')->pubCountOptions());
        $this->assertSame([5, 10, 25, 50], $this->action(10, ' 5 , 10 ,25, 50 ')->pubCountOptions());
    }

    public function testCountOptionsFallbackOnGarbage(): void
    {
        $this->assertSame([5, 10, 25, 50], $this->action(10, 'abc,,0,-3,x')->pubCountOptions());
        $this->assertSame(5, $this->action(10, 'abc')->pubMinCount());
    }

    public function testMinCountIsSmallestOption(): void
    {
        $this->assertSame(3, $this->action(10, '7,3,99')->pubMinCount());
    }
}
