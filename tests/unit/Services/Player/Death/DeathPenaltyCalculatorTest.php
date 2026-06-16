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

    // ─── E31 (ADR-131) — level-scaled смягчение новичкам ──────────────────────

    /** @return array{level_max:int,penalty_no_base:float,penalty_with_base:float} */
    private function newbieCfg(): array
    {
        return ['level_max' => 10, 'penalty_no_base' => 0.10, 'penalty_with_base' => 0.0];
    }

    public function testDormantWhenNoNewbieConfigByteIdentical(): void
    {
        // $newbie=null (killswitch off) → легаси, даже для низкого уровня.
        $this->assertSame(0.50, $this->calc->decide(false, false, 5, null));
        $this->assertSame(0.03, $this->calc->decide(false, true, 5, null));
    }

    public function testNewbieNoBaseReducedPenalty(): void
    {
        // Новичок (L5 <= 10) без базы → сниженный штраф 10% вместо 50%.
        $this->assertSame(0.10, $this->calc->decide(false, false, 5, $this->newbieCfg()));
    }

    public function testNewbieWithBaseZeroPenalty(): void
    {
        // Новичок с базой → 0% вместо 3%.
        $this->assertSame(0.0, $this->calc->decide(false, true, 5, $this->newbieCfg()));
    }

    public function testNewbieAtBoundaryProtected(): void
    {
        // level == level_max → ещё в окне (<=).
        $this->assertSame(0.10, $this->calc->decide(false, false, 10, $this->newbieCfg()));
    }

    public function testVeteranAboveWindowLegacy(): void
    {
        // L30 > level_max(10) → легаси 50%/3% (защита только новичкам).
        $this->assertSame(0.50, $this->calc->decide(false, false, 30, $this->newbieCfg()));
        $this->assertSame(0.03, $this->calc->decide(false, true, 30, $this->newbieCfg()));
    }

    public function testLevelZeroUnknownNoProtection(): void
    {
        // level 0 (неизвестен) → НЕ новичок-защита (окно строго >0), легаси.
        $this->assertSame(0.50, $this->calc->decide(false, false, 0, $this->newbieCfg()));
    }

    public function testInsuredNewbieStillZeroPriority(): void
    {
        // Страховка приоритетнее newbie-смягчения.
        $this->assertSame(0.0, $this->calc->decide(true, false, 5, $this->newbieCfg()));
    }
}
