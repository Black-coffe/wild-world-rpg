<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Player\Death;

use App\Services\Player\Death\LootProcessor;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-172 — розыгрыш дробного остатка штрафа смерти на крафт-предметах.
 *
 * Проверяется поведение, а не реализация: сколько штук забирает смерть у строки
 * `crafted_items_log` при заданном штрафе и заданном броске. Источник случайности
 * подменяется callable'ом, поэтому обе ветки броска детерминированы.
 *
 * @internal
 */
final class LootProcessorFractionalLossTest extends CIUnitTestCase
{
    private LootProcessor $proc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->proc = new LootProcessor();
    }

    /** @return list<array{id:int,crafted_item_id:int,quantity:int,insured?:int}> */
    private function oneDrone(int $qty = 1, int $insured = 0): array
    {
        return [['id' => 100, 'crafted_item_id' => 7, 'quantity' => $qty, 'insured' => $insured]];
    }

    /** @param float $value */
    private function roll(float $value): callable
    {
        return static fn (): float => $value;
    }

    public function testKillswitchOffKeepsLegacyBehaviourForSingleItem(): void
    {
        // Дрон лежит строкой quantity=1. Прежняя формула floor(1 × 0.50) = 0 —
        // предмет не терялся даже при штрафе в половину имущества.
        $lost = $this->proc->computeCraftLoss($this->oneDrone(), 0.50, false, $this->roll(0.0));
        $this->assertSame([], $lost, 'При выключенном флаге поведение обязано остаться прежним');
    }

    public function testSingleItemLostWhenRollFallsUnderPenalty(): void
    {
        $lost = $this->proc->computeCraftLoss($this->oneDrone(), 0.03, true, $this->roll(0.02));

        $this->assertCount(1, $lost);
        $this->assertSame(100, $lost[0]['logId']);
        $this->assertSame(1, $lost[0]['lossAmount']);
    }

    public function testSingleItemSurvivesWhenRollAbovePenalty(): void
    {
        $lost = $this->proc->computeCraftLoss($this->oneDrone(), 0.03, true, $this->roll(0.04));
        $this->assertSame([], $lost);
    }

    public function testInsuredSingleItemSurvivesTheWorstRoll(): void
    {
        // Смысл полиса: даже при броске 0.0 и штрафе 50% застрахованный дрон цел.
        $lost = $this->proc->computeCraftLoss($this->oneDrone(1, 1), 0.50, true, $this->roll(0.0));
        $this->assertSame([], $lost);
    }

    public function testWholePartIsUnaffectedByTheRoll(): void
    {
        // 200 × 0.03 = 6.0 — дробного остатка нет, бросок ничего не решает.
        $rows = [['id' => 1, 'crafted_item_id' => 9, 'quantity' => 200, 'insured' => 0]];

        $unlucky = $this->proc->computeCraftLoss($rows, 0.03, true, $this->roll(0.0));
        $lucky   = $this->proc->computeCraftLoss($rows, 0.03, true, $this->roll(0.999));

        $this->assertSame(6, $unlucky[0]['lossAmount']);
        $this->assertSame(6, $lucky[0]['lossAmount']);
    }

    public function testWholePartPlusFractionAddsAtMostOne(): void
    {
        // 210 × 0.03 = 6.3 → 6 гарантированно + 30% шанс на седьмую.
        $rows = [['id' => 1, 'crafted_item_id' => 9, 'quantity' => 210, 'insured' => 0]];

        $this->assertSame(7, $this->proc->computeCraftLoss($rows, 0.03, true, $this->roll(0.1))[0]['lossAmount']);
        $this->assertSame(6, $this->proc->computeCraftLoss($rows, 0.03, true, $this->roll(0.9))[0]['lossAmount']);
    }

    public function testLossNeverExceedsWhatTheRowHolds(): void
    {
        // Полный штраф: 1 × 1.0 = 1.0, дробного остатка нет — забрать можно ровно штуку.
        $full = $this->proc->computeCraftLoss($this->oneDrone(), 1.0, true, $this->roll(0.0));
        $this->assertSame(1, $full[0]['lossAmount']);

        // 2 × 0.9 = 1.8 → 1 + шанс 0.8; при удачном для смерти броске забирается вся строка, не больше.
        $rows = [['id' => 1, 'crafted_item_id' => 9, 'quantity' => 2, 'insured' => 0]];
        $this->assertSame(2, $this->proc->computeCraftLoss($rows, 0.9, true, $this->roll(0.0))[0]['lossAmount']);
    }

    public function testZeroPenaltyLosesNothingEvenOnWorstRoll(): void
    {
        // Сработавшая личная страховка даёт penalty=0.0 — бросок не должен ничего отнимать.
        $lost = $this->proc->computeCraftLoss($this->oneDrone(5), 0.0, true, $this->roll(0.0));
        $this->assertSame([], $lost);
    }

    public function testDefaultRandomSourceStaysWithinBounds(): void
    {
        // Без подменённого источника случайности механика обязана оставаться в границах:
        // ни отрицательных потерь, ни списания больше, чем лежит в строке.
        for ($i = 0; $i < 200; $i++) {
            $lost = $this->proc->computeCraftLoss($this->oneDrone(), 0.03, true);
            if ($lost !== []) {
                $this->assertSame(1, $lost[0]['lossAmount']);
            }
        }
        $this->assertTrue(true);
    }
}
