<?php

namespace Tests\Unit\Services\Player\Progression;

use App\Services\Player\Progression\EarlyProgressionService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * S3 (ADR-138) — unit-тесты ридера рычагов слома стены L1→L5.
 *
 * Тест-сим: конструктор принимает map-оверрайды (минуя GameSettings) → чистая логика без БД.
 * Главный инвариант: dormant (enabled=false) И ветеран (level>=cap) → НЕЙТРАЛЬНЫЕ значения
 * (byte-identical для call-site'ов).
 *
 * @internal
 */
final class EarlyProgressionServiceTest extends CIUnitTestCase
{
    /**
     * @param array<string, mixed> $overrides
     */
    private function svc(array $overrides): EarlyProgressionService
    {
        return new EarlyProgressionService(null, $overrides);
    }

    private function activeConfig(): array
    {
        return [
            'progression.early.enabled'                  => true,
            'progression.early.level_cap'                => 5,
            'progression.early.gather_xp'                => 0.30,
            'progression.early.gain_multiplier'          => 2.0,
            'progression.early.move_cost_factor'         => 0.80,
            'progression.early.interrupt_penalty_factor' => 0.0,
        ];
    }

    // ── DORMANT (enabled=false) → всё нейтрально (byte-identical) ─────────

    public function testDisabledIsNeutralRegardlessOfLevel(): void
    {
        $svc = $this->svc(['progression.early.enabled' => false]);

        $this->assertFalse($svc->isEnabled());
        $this->assertFalse($svc->isEarly(1));
        $this->assertSame(1.0, $svc->gainMultiplier(1));
        $this->assertSame(0.0, $svc->gatherXpEarned(1));
        $this->assertSame(1.0, $svc->moveCostFactor(1));
        $this->assertSame(1.0, $svc->interruptPenaltyFactor(1));
    }

    public function testEmptyConfigDefaultsToDisabledNeutral(): void
    {
        // Нет ни одного ключа → enabled default false → нейтрально (безопасная деградация).
        $svc = $this->svc([]);

        $this->assertFalse($svc->isEnabled());
        $this->assertSame(5, $svc->levelCap());
        $this->assertSame(1.0, $svc->gainMultiplier(1));
        $this->assertSame(0.0, $svc->gatherXpEarned(1));
    }

    // ── ACTIVE + early (level < cap) → активные значения ──────────────────

    public function testEnabledEarlyAppliesAllLevers(): void
    {
        $svc = $this->svc($this->activeConfig());

        $this->assertTrue($svc->isEarly(1));
        $this->assertSame(2.0, $svc->gainMultiplier(1));
        $this->assertSame(0.6, $svc->gatherXpEarned(1)); // 0.30 × 2.0
        $this->assertSame(0.80, $svc->moveCostFactor(1));
        $this->assertSame(0.0, $svc->interruptPenaltyFactor(1));
    }

    public function testEarlyJustBelowCapIsActive(): void
    {
        $svc = $this->svc($this->activeConfig());

        $this->assertTrue($svc->isEarly(4));
        $this->assertSame(2.0, $svc->gainMultiplier(4));
    }

    // ── ACTIVE + level >= cap → нейтрально (ветеран не затронут) ───────────

    public function testAtCapIsNeutral(): void
    {
        $svc = $this->svc($this->activeConfig());

        // level == cap → НЕ early (строгое <).
        $this->assertFalse($svc->isEarly(5));
        $this->assertSame(1.0, $svc->gainMultiplier(5));
        $this->assertSame(0.0, $svc->gatherXpEarned(5));
        $this->assertSame(1.0, $svc->moveCostFactor(5));
        $this->assertSame(1.0, $svc->interruptPenaltyFactor(5));
    }

    public function testAboveCapIsNeutral(): void
    {
        $svc = $this->svc($this->activeConfig());

        $this->assertFalse($svc->isEarly(20));
        $this->assertSame(1.0, $svc->gainMultiplier(20));
        $this->assertSame(1.0, $svc->interruptPenaltyFactor(20));
    }

    // ── gatherXpEarned применяет множитель ───────────────────────────────

    public function testGatherXpAppliesMultiplier(): void
    {
        $svc = $this->svc([
            'progression.early.enabled'         => true,
            'progression.early.level_cap'       => 5,
            'progression.early.gather_xp'       => 0.50,
            'progression.early.gain_multiplier' => 3.0,
        ]);

        $this->assertSame(1.5, $svc->gatherXpEarned(2)); // 0.50 × 3.0
    }

    // ── Coercion killswitch (admin шлёт строки/числа) ─────────────────────

    public function testEnabledCoercionTruthy(): void
    {
        foreach (['1', 'true', 'yes', 'on', 1, true] as $val) {
            $svc = $this->svc(['progression.early.enabled' => $val]);
            $this->assertTrue($svc->isEnabled(), 'truthy: ' . var_export($val, true));
        }
    }

    public function testEnabledCoercionFalsy(): void
    {
        foreach (['0', 'false', 'off', '', 0, false] as $val) {
            $svc = $this->svc(['progression.early.enabled' => $val]);
            $this->assertFalse($svc->isEnabled(), 'falsy: ' . var_export($val, true));
        }
    }

    public function testLevelCapMissingDefaultsToFive(): void
    {
        $svc = $this->svc(['progression.early.enabled' => true]);

        $this->assertSame(5, $svc->levelCap());
        $this->assertTrue($svc->isEarly(4));
        $this->assertFalse($svc->isEarly(5));
    }

    public function testGainMultiplierMissingUsesActiveDefault(): void
    {
        // enabled + early, но множитель не задан → активный default 2.0.
        $svc = $this->svc([
            'progression.early.enabled'   => true,
            'progression.early.level_cap' => 5,
        ]);

        $this->assertSame(2.0, $svc->gainMultiplier(1));
        $this->assertSame(0.6, $svc->gatherXpEarned(1)); // gather_xp default 0.30 × 2.0
    }
}
