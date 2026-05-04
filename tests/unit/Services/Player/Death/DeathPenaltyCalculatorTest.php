<?php

namespace Tests\Unit\Services\Player\Death;

use App\Services\Player\Death\DeathPenaltyCalculator;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * F2.8 — unit-тесты на чистую функцию выбора penalty.
 * Источник истины: legacy DeathService::handlePlayerDeathAndReward
 * v0.4.0 (строки 39-61).
 *
 * @internal
 */
final class DeathPenaltyCalculatorTest extends CIUnitTestCase
{
    private DeathPenaltyCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new DeathPenaltyCalculator();
    }

    public function testInsuranceCoveredZeroPercent(): void
    {
        // Страховка списалась → штраф 0%, hasBase irrelevant.
        $this->assertSame(0.0, $this->calc->decide(true, true));
        $this->assertSame(0.0, $this->calc->decide(true, false));
    }

    public function testInsuranceFailedWithBaseThreePercent(): void
    {
        // Страховка не сработала, но есть база → 3%.
        $this->assertSame(0.03, $this->calc->decide(false, true));
    }

    public function testInsuranceFailedNoBaseFiftyPercent(): void
    {
        // Страховка не сработала, базы нет → 50% (жесткий).
        $this->assertSame(0.50, $this->calc->decide(false, false));
    }
}
