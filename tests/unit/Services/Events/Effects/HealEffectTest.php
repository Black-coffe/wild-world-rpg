<?php

namespace Tests\Unit\Services\Events\Effects;

use App\Services\Events\Effects\HealEffect;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * F7.2 — тести на HealEffect (GeothermalFountains).
 * @internal
 */
final class HealEffectTest extends CIUnitTestCase
{
    private HealEffect $effect;

    protected function setUp(): void
    {
        parent::setUp();
        $this->effect = new HealEffect();
    }

    public function testHealRespectsCap(): void
    {
        // Гравець уже на 95 HP, cap=100, range=[1,10] → max +5
        for ($i = 0; $i < 50; $i++) {
            $r = $this->effect->compute(
                ['health' => 95, 'tired' => 95],
                ['effect_params' => ['heal_target' => 'health', 'amount_range' => [1, 10], 'cap' => 100]],
                [],
                []
            );
            if ($r['applied']) {
                $this->assertLessThanOrEqual(5, $r['health_delta']);
                $this->assertGreaterThan(0, $r['health_delta']);
            }
        }
    }

    public function testHealAtCapSkipped(): void
    {
        $r = $this->effect->compute(
            ['health' => 100, 'tired' => 100],
            ['effect_params' => ['heal_target' => 'health', 'amount_range' => [1, 10], 'cap' => 100]],
            [],
            []
        );
        $this->assertFalse($r['applied']);
    }

    public function testHealRandomTargetEitherHorT(): void
    {
        $hHits = 0;
        $tHits = 0;
        for ($i = 0; $i < 200; $i++) {
            $r = $this->effect->compute(
                ['health' => 50, 'tired' => 50],
                ['effect_params' => ['heal_target' => 'random_h_or_t', 'amount_range' => [1, 5], 'cap' => 100]],
                [],
                []
            );
            if ($r['health_delta'] > 0) {
                $hHits++;
            }
            if ($r['tired_delta'] > 0) {
                $tHits++;
            }
        }
        $this->assertGreaterThan(50, $hHits);
        $this->assertGreaterThan(50, $tHits);
    }

    public function testHealMagnitudeReportsAmount(): void
    {
        $r = $this->effect->compute(
            ['health' => 50, 'tired' => 100],
            ['effect_params' => ['heal_target' => 'health', 'amount_range' => [10, 10], 'cap' => 100]],
            [],
            []
        );
        $this->assertTrue($r['applied']);
        $this->assertSame(10.0, $r['magnitude']['heal_amount']);
        $this->assertSame('heal', $r['magnitude']['effect_kind']);
    }
}
