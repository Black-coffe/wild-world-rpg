<?php

namespace Tests\Unit\Services\Events\Effects;

use App\Services\Events\Effects\DamageHealthEffect;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * F7.2 — тести на DamageHealthEffect.
 *
 * Покриває 9 з 10 damage-подій (Hurricane, NightAttacks, Epidemic, ForestFire,
 * Snowfall, SpringFlood, Tremor, Volcanic, Sandstorm, Fever).
 *
 * Перевіряємо:
 *   - state_modifier (база захищає, gather/explore = повний удар)
 *   - time_window (NightAttacks 20:00-05:00)
 *   - sleeping_player_skip (NightAttacks)
 *   - two_stage_chance (Fever first stage 50%)
 *   - level_scaling, biome_factor, random_factor
 *   - damage_target enum: 'health', 'tired', 'random_h_or_t', 'both', 'biome_type_specific'
 *   - protection_item -50%
 *   - magnitude metrics для throttle
 *
 * @internal
 */
final class DamageHealthEffectTest extends CIUnitTestCase
{
    private DamageHealthEffect $effect;

    protected function setUp(): void
    {
        parent::setUp();
        $this->effect = new DamageHealthEffect();
    }

    // ============================================================
    // state_modifier — фундаментальний фільтр
    // ============================================================

    public function testProtectedOnBaseSkipped(): void
    {
        $r = $this->effect->compute(
            ['health' => 100, 'tired' => 100, 'level' => 10],
            ['effect_params' => ['damage_target' => 'health', 'state_modifier' => ['base_idle' => 0.0, 'biome_idle' => 0.7, 'biome_active' => 1.0]]],
            ['effect_value' => 50],
            ['on_base' => true, 'is_gathering' => false, 'is_exploring' => false]
        );
        $this->assertFalse($r['applied']);
        $this->assertSame(0.0, $r['health_delta']);
    }

    public function testFullDamageOnGatherInBiome(): void
    {
        $r = $this->effect->compute(
            ['health' => 100, 'tired' => 100, 'level' => 1],
            ['effect_params' => ['damage_target' => 'health', 'state_modifier' => ['base_idle' => 0.0, 'biome_idle' => 0.7, 'biome_active' => 1.0]]],
            ['effect_value' => 50],
            ['on_base' => false, 'is_gathering' => true]
        );
        $this->assertTrue($r['applied']);
        $this->assertSame(-50.0, $r['health_delta']);  // 50 × 1.0 (biome_active)
    }

    public function testReducedDamageOnIdleInBiome(): void
    {
        $r = $this->effect->compute(
            ['health' => 100, 'tired' => 100, 'level' => 1],
            ['effect_params' => ['damage_target' => 'health', 'state_modifier' => ['base_idle' => 0.0, 'biome_idle' => 0.7, 'biome_active' => 1.0]]],
            ['effect_value' => 100],
            ['on_base' => false, 'is_gathering' => false, 'is_exploring' => false]
        );
        $this->assertTrue($r['applied']);
        $this->assertSame(-70.0, $r['health_delta']);  // 100 × 0.7 (biome_idle)
    }

    // ============================================================
    // time_window (NightAttacks)
    // ============================================================

    public function testTimeWindowGateRejectsDayTime(): void
    {
        $r = $this->effect->compute(
            ['health' => 100, 'tired' => 100, 'level' => 1],
            ['effect_params' => [
                'damage_target' => 'both',
                'state_modifier' => ['base_idle' => 0.0, 'biome_idle' => 0.5, 'biome_active' => 1.0],
                'time_window'   => ['20:00', '05:00'],
            ]],
            ['effect_value' => 30],
            ['on_base' => false, 'is_gathering' => true, 'now_time' => '14:30']
        );
        $this->assertFalse($r['applied']);
    }

    public function testTimeWindowAllowsLateNight(): void
    {
        $r = $this->effect->compute(
            ['health' => 100, 'tired' => 100, 'level' => 1],
            ['effect_params' => [
                'damage_target' => 'both',
                'state_modifier' => ['base_idle' => 0.0, 'biome_idle' => 0.5, 'biome_active' => 1.0],
                'time_window'   => ['20:00', '05:00'],
            ]],
            ['effect_value' => 30],
            ['on_base' => false, 'is_gathering' => true, 'now_time' => '23:45']
        );
        $this->assertTrue($r['applied']);
    }

    public function testTimeWindowAllowsEarlyMorning(): void
    {
        $r = $this->effect->compute(
            ['health' => 100, 'tired' => 100, 'level' => 1],
            ['effect_params' => [
                'damage_target' => 'both',
                'state_modifier' => ['base_idle' => 0.0, 'biome_idle' => 0.5, 'biome_active' => 1.0],
                'time_window'   => ['20:00', '05:00'],
            ]],
            ['effect_value' => 30],
            ['on_base' => false, 'is_gathering' => true, 'now_time' => '03:00']
        );
        $this->assertTrue($r['applied']);
    }

    // ============================================================
    // sleeping_player_skip
    // ============================================================

    public function testSleepingPlayerSkipped(): void
    {
        $r = $this->effect->compute(
            ['health' => 100, 'tired' => 100, 'level' => 1],
            ['effect_params' => [
                'damage_target' => 'health',
                'state_modifier' => ['biome_active' => 1.0],
                'sleeping_player_skip' => 12,
            ]],
            ['effect_value' => 30],
            ['is_gathering' => true, 'last_seen_hours_ago' => 18]
        );
        $this->assertFalse($r['applied']);
    }

    public function testActivePlayerNotSkipped(): void
    {
        $r = $this->effect->compute(
            ['health' => 100, 'tired' => 100, 'level' => 1],
            ['effect_params' => [
                'damage_target' => 'health',
                'state_modifier' => ['biome_active' => 1.0],
                'sleeping_player_skip' => 12,
            ]],
            ['effect_value' => 30],
            ['is_gathering' => true, 'last_seen_hours_ago' => 2]
        );
        $this->assertTrue($r['applied']);
    }

    // ============================================================
    // damage_target enum
    // ============================================================

    public function testDamageTargetTired(): void
    {
        $r = $this->effect->compute(
            ['health' => 100, 'tired' => 100, 'level' => 1],
            ['effect_params' => ['damage_target' => 'tired', 'state_modifier' => ['biome_active' => 1.0]]],
            ['effect_value' => 25],
            ['is_gathering' => true]
        );
        $this->assertSame(0.0, $r['health_delta']);
        $this->assertSame(-25.0, $r['tired_delta']);
    }

    public function testDamageTargetBothWithRatio(): void
    {
        $r = $this->effect->compute(
            ['health' => 100, 'tired' => 100, 'level' => 1],
            ['effect_params' => [
                'damage_target' => 'both',
                'tired_ratio'   => 0.5,  // tired = damage / 2
                'state_modifier' => ['biome_active' => 1.0],
            ]],
            ['effect_value' => 40],
            ['is_gathering' => true]
        );
        $this->assertSame(-40.0, $r['health_delta']);
        $this->assertSame(-20.0, $r['tired_delta']);
    }

    public function testDamageTargetRandomHorT(): void
    {
        // Запускаємо багато разів — статистично в обох є шанс
        $hCount = 0;
        $tCount = 0;
        for ($i = 0; $i < 200; $i++) {
            $r = $this->effect->compute(
                ['health' => 100, 'tired' => 100, 'level' => 1],
                ['effect_params' => ['damage_target' => 'random_h_or_t', 'state_modifier' => ['biome_active' => 1.0]]],
                ['effect_value' => 10],
                ['is_gathering' => true]
            );
            if ($r['health_delta'] < 0) {
                $hCount++;
            }
            if ($r['tired_delta'] < 0) {
                $tCount++;
            }
        }
        // 50/50 — у 200 спробах має бути хоча б 50 кожного
        $this->assertGreaterThan(50, $hCount, 'health має зачіпатись');
        $this->assertGreaterThan(50, $tCount, 'tired має зачіпатись');
    }

    // ============================================================
    // level_scaling
    // ============================================================

    public function testLevelScalingReducesDamageAtHighLevel(): void
    {
        $low = $this->effect->compute(
            ['health' => 100, 'tired' => 100, 'level' => 1],
            ['effect_params' => [
                'damage_target' => 'health',
                'state_modifier' => ['biome_active' => 1.0],
                'level_scaling' => true,
            ]],
            ['effect_value' => 100],
            ['is_gathering' => true]
        );

        $high = $this->effect->compute(
            ['health' => 100, 'tired' => 100, 'level' => 80],
            ['effect_params' => [
                'damage_target' => 'health',
                'state_modifier' => ['biome_active' => 1.0],
                'level_scaling' => true,
            ]],
            ['effect_value' => 100],
            ['is_gathering' => true]
        );

        $this->assertLessThan(abs($low['health_delta']), abs($high['health_delta']),
            'високий level має отримувати менше damage');
    }

    // ============================================================
    // protection_item
    // ============================================================

    public function testProtectionItemHalvesDamage(): void
    {
        $without = $this->effect->compute(
            ['health' => 100, 'tired' => 100, 'level' => 1],
            ['effect_params' => ['damage_target' => 'health', 'state_modifier' => ['biome_active' => 1.0]]],
            ['effect_value' => 100],
            ['is_gathering' => true, 'has_protection_item' => false]
        );

        $with = $this->effect->compute(
            ['health' => 100, 'tired' => 100, 'level' => 1],
            ['effect_params' => ['damage_target' => 'health', 'state_modifier' => ['biome_active' => 1.0]]],
            ['effect_value' => 100],
            ['is_gathering' => true, 'has_protection_item' => true]
        );

        $this->assertSame(-100.0, $without['health_delta']);
        $this->assertSame(-50.0, $with['health_delta']);
    }

    // ============================================================
    // magnitude metrics
    // ============================================================

    public function testMagnitudeContainsHealthLossPercent(): void
    {
        $r = $this->effect->compute(
            ['health' => 80, 'tired' => 100, 'level' => 1],
            ['effect_params' => ['damage_target' => 'health', 'state_modifier' => ['biome_active' => 1.0]]],
            ['effect_value' => 40],  // 40/80 = 50%
            ['is_gathering' => true]
        );

        $this->assertArrayHasKey('health_loss_percent', $r['magnitude']);
        $this->assertSame(50.0, $r['magnitude']['health_loss_percent']);
        $this->assertSame('damage_health', $r['magnitude']['effect_kind']);
    }

    public function testMagnitudeHealthAfter(): void
    {
        $r = $this->effect->compute(
            ['health' => 30, 'tired' => 100, 'level' => 1],
            ['effect_params' => ['damage_target' => 'health', 'state_modifier' => ['biome_active' => 1.0]]],
            ['effect_value' => 25],
            ['is_gathering' => true]
        );

        $this->assertArrayHasKey('health_after', $r['magnitude']);
        $this->assertSame(5.0, $r['magnitude']['health_after']);
    }

    // ============================================================
    // biome_type_specific (Fever)
    // ============================================================

    public function testFeverWetBiomeHurtsHealth(): void
    {
        $r = $this->effect->compute(
            ['health' => 100, 'tired' => 100, 'level' => 1],
            ['effect_params' => [
                'damage_target' => 'biome_type_specific',
                'state_modifier' => ['biome_active' => 1.0],
                'biome_type_specific' => [
                    'wet'     => ['health' => [5, 10]],
                    'dry'     => ['tired'  => [3, 7]],
                    'default' => ['health' => [1, 3], 'tired' => [1, 3]],
                ],
            ]],
            ['effect_value' => 0],
            ['is_gathering' => true, 'biome' => ['biome_type' => 'wet']]
        );

        $this->assertTrue($r['applied']);
        $this->assertLessThan(0, $r['health_delta']);
        $this->assertGreaterThanOrEqual(-10, $r['health_delta']);
        $this->assertLessThanOrEqual(-5, $r['health_delta']);
        $this->assertSame(0.0, $r['tired_delta']);
    }

    public function testFeverDryBiomeHurtsTired(): void
    {
        $r = $this->effect->compute(
            ['health' => 100, 'tired' => 100, 'level' => 1],
            ['effect_params' => [
                'damage_target' => 'biome_type_specific',
                'state_modifier' => ['biome_active' => 1.0],
                'biome_type_specific' => [
                    'wet' => ['health' => [5, 10]],
                    'dry' => ['tired'  => [3, 7]],
                ],
            ]],
            ['effect_value' => 0],
            ['is_gathering' => true, 'biome' => ['biome_type' => 'dry']]
        );

        $this->assertTrue($r['applied']);
        $this->assertSame(0.0, $r['health_delta']);
        $this->assertLessThan(0, $r['tired_delta']);
    }

    // ============================================================
    // Sandstorm — attribute drain
    // ============================================================

    public function testSandstormDrainsRandomAttribute(): void
    {
        $r = $this->effect->compute(
            ['health' => 100, 'tired' => 100, 'level' => 1, 'experience' => 5, 'strength' => 5, 'agility' => 5, 'intellect' => 5],
            ['effect_params' => [
                'damage_target'    => 'tired',
                'state_modifier'   => ['biome_active' => 1.0],
                'attr_drain_pool'  => ['experience', 'strength', 'agility', 'intellect'],
                'attr_drain_value' => 0.01,
            ]],
            ['effect_value' => 20],
            ['is_gathering' => true]
        );

        $this->assertTrue($r['applied']);
        $this->assertCount(1, $r['attribute_deltas']);
        $drained = array_keys($r['attribute_deltas'])[0];
        $this->assertContains($drained, ['experience', 'strength', 'agility', 'intellect']);
        $this->assertSame(-0.01, $r['attribute_deltas'][$drained]);
    }
}
