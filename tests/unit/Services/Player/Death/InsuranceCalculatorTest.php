<?php

namespace Tests\Unit\Services\Player\Death;

use App\Services\Player\Death\InsuranceCalculator;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * F2.8 — unit-тесты на формулу стоимости страховки.
 * Источник истины: legacy DeathService::calculateInsuranceCost v0.4.0.
 *
 * Формула:
 *   resourceCost   = (totalResources / 1000) * 10
 *   timeCost       = (monthsInGame / 12) * 5
 *   levelCost      = (level / 10) * 20
 *   experienceCost = (experience / 10) * 5
 *   attributesCost = ((str+agi+int+tired) / 100) * 10
 *   goldCost       = (goldInChest / 10000) * 50
 *
 * @internal
 */
final class InsuranceCalculatorTest extends CIUnitTestCase
{
    private InsuranceCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new InsuranceCalculator();
    }

    /** Empty character — все нули → стоимость = 0 (но monthsInGame=1 даёт ~0.4 → round 0). */
    public function testZeroCharacterRoundsToZero(): void
    {
        $character = [
            'level' => 0, 'experience' => 0,
            'strength' => 0, 'agility' => 0, 'intellect' => 0,
            'tired' => 0, 'gold' => 0,
            'created_at' => date('Y-m-d H:i:s'),  // только что создан
        ];
        $this->assertSame(0, $this->calc->calculate($character, 0));
    }

    public function testLevel100Cost(): void
    {
        // Только level=100, все остальные 0:
        // levelCost = (100/10)*20 = 200, остальные 0
        // monthsInGame=1, timeCost = 5/12 ≈ 0.42
        // total ≈ 200.42 → round 200
        $character = [
            'level' => 100, 'experience' => 0,
            'strength' => 0, 'agility' => 0, 'intellect' => 0,
            'tired' => 0, 'gold' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        $this->assertSame(200, $this->calc->calculate($character, 0));
    }

    public function testGoldHeavyCharacter(): void
    {
        // gold=100000 → goldCost = 100000/10000 * 50 = 500
        $character = [
            'level' => 0, 'experience' => 0,
            'strength' => 0, 'agility' => 0, 'intellect' => 0,
            'tired' => 0, 'gold' => 100000,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        $this->assertSame(500, $this->calc->calculate($character, 0));
    }

    public function testFullStatsContribute(): void
    {
        // strength=25, agility=25, intellect=25, tired=25 → sum=100
        // attributesCost = (100/100) * 10 = 10
        $character = [
            'level' => 0, 'experience' => 0,
            'strength' => 25, 'agility' => 25, 'intellect' => 25,
            'tired' => 25, 'gold' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        $this->assertSame(10, $this->calc->calculate($character, 0));
    }

    public function testResourceCount(): void
    {
        // 1000 ресурсов → resourceCost = 1000/1000 * 10 = 10
        $character = [
            'level' => 0, 'experience' => 0,
            'strength' => 0, 'agility' => 0, 'intellect' => 0,
            'tired' => 0, 'gold' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        $this->assertSame(10, $this->calc->calculate($character, 1000));
    }

    public function testRealisticCharacter(): void
    {
        // Realistic mid-game: level 50, exp 100, stats 50 each, 10k gold,
        // 200 resources, 6 months in game.
        $character = [
            'level' => 50, 'experience' => 100,
            'strength' => 50, 'agility' => 50, 'intellect' => 50,
            'tired' => 50, 'gold' => 10000,
            'created_at' => (new \DateTime('-6 months'))->format('Y-m-d H:i:s'),
        ];
        $cost = $this->calc->calculate($character, 200);
        // resourceCost   = 200/1000 * 10 = 2
        // timeCost       = (6/12) * 5 = 2.5  (но diff()->m возвращает только m mod 12 — может быть 0!)
        // levelCost      = 50/10 * 20 = 100
        // experienceCost = 100/10 * 5 = 50
        // attributesCost = 200/100 * 10 = 20
        // goldCost       = 10000/10000 * 50 = 50
        // total ≈ 224 + timeCost (0..2.5) → round в районе 222-227
        $this->assertGreaterThan(220, $cost);
        $this->assertLessThan(230, $cost);
    }

    public function testHandlesMissingFields(): void
    {
        // Если поля отсутствуют — должно работать без warnings (default 0).
        $character = ['created_at' => date('Y-m-d H:i:s')];
        $this->assertSame(0, $this->calc->calculate($character, 0));
    }
}
