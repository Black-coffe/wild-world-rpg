<?php

namespace Tests\Unit\Services\Events\Effects;

use App\Services\Events\Effects\GoldGrantEffect;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * F7.2 — тесты на GoldGrantEffect (GoldMine) — особливо cap_formula
 * для боротьби з power-creep'ом.
 * @internal
 */
final class GoldGrantEffectTest extends CIUnitTestCase
{
    private GoldGrantEffect $effect;

    protected function setUp(): void
    {
        parent::setUp();
        $this->effect = new GoldGrantEffect();
    }

    public function testRequiresGatherState(): void
    {
        $r = $this->effect->compute(
            ['level' => 10, 'experience' => 100, 'agility' => 100, 'intellect' => 100, 'gold' => 0],
            ['effect_params' => [
                'base_range'        => [1000, 150000],
                'stat_divisor'      => 3000.0,
                'effect_value_mul'  => true,
                'cap_formula'       => 'level_50',
                'requires_state'    => 'gather',
                'chance_per_tick'   => 1.0,  // garanteed для тесту
            ]],
            ['effect_value' => 50],
            ['is_gathering' => false]
        );
        $this->assertFalse($r['applied']);
    }

    public function testCapFormulaLevel50LowLevel(): void
    {
        // На 1 рівни cap = max(500, 1×50) = 500
        for ($i = 0; $i < 50; $i++) {
            $r = $this->effect->compute(
                ['level' => 1, 'experience' => 1000, 'agility' => 1000, 'intellect' => 1000, 'gold' => 0],
                ['effect_params' => [
                    'base_range'        => [100000, 150000],  // високий base
                    'stat_divisor'      => 3000.0,
                    'effect_value_mul'  => true,
                    'cap_formula'       => 'level_50',
                    'requires_state'    => 'gather',
                    'chance_per_tick'   => 1.0,
                ]],
                ['effect_value' => 100],
                ['is_gathering' => true]
            );

            if ($r['applied']) {
                $this->assertLessThanOrEqual(500, $r['gold_delta'],
                    "Level 1 gold cap = 500, got {$r['gold_delta']}");
            }
        }
    }

    public function testCapFormulaLevel50HighLevel(): void
    {
        // На 300 рівни cap = max(500, 300×50) = 15000
        for ($i = 0; $i < 30; $i++) {
            $r = $this->effect->compute(
                ['level' => 300, 'experience' => 1000, 'agility' => 1000, 'intellect' => 1000, 'gold' => 0],
                ['effect_params' => [
                    'base_range'        => [100000, 150000],
                    'stat_divisor'      => 3000.0,
                    'effect_value_mul'  => true,
                    'cap_formula'       => 'level_50',
                    'requires_state'    => 'gather',
                    'chance_per_tick'   => 1.0,
                ]],
                ['effect_value' => 100],
                ['is_gathering' => true]
            );

            if ($r['applied']) {
                $this->assertLessThanOrEqual(15000, $r['gold_delta'],
                    "Level 300 gold cap = 15000, got {$r['gold_delta']}");
            }
        }
    }

    public function testCapFormulaNoneAllowsHugeGold(): void
    {
        // 'none' = legacy без cap
        $maxSeen = 0;
        for ($i = 0; $i < 30; $i++) {
            $r = $this->effect->compute(
                ['level' => 100, 'experience' => 1000, 'agility' => 1000, 'intellect' => 1000, 'gold' => 0],
                ['effect_params' => [
                    'base_range'        => [100000, 150000],
                    'stat_divisor'      => 3000.0,
                    'effect_value_mul'  => true,
                    'cap_formula'       => 'none',
                    'requires_state'    => 'gather',
                    'chance_per_tick'   => 1.0,
                ]],
                ['effect_value' => 100],
                ['is_gathering' => true]
            );
            if ($r['applied']) {
                $maxSeen = max($maxSeen, $r['gold_delta']);
            }
        }
        // Без cap'а ожидаемо що принаймни раз перевищило 5000 (level 100 × 50)
        $this->assertGreaterThan(5000, $maxSeen, "'none' формула должен давати > level_50 cap");
    }

    public function testChancePerTickGate(): void
    {
        // chance_per_tick=0 → всегда skip
        $applied = 0;
        for ($i = 0; $i < 50; $i++) {
            $r = $this->effect->compute(
                ['level' => 10, 'experience' => 100, 'agility' => 100, 'intellect' => 100, 'gold' => 0],
                ['effect_params' => [
                    'base_range'        => [1000, 150000],
                    'stat_divisor'      => 3000.0,
                    'effect_value_mul'  => true,
                    'cap_formula'       => 'level_50',
                    'requires_state'    => 'gather',
                    'chance_per_tick'   => 0.0,
                ]],
                ['effect_value' => 50],
                ['is_gathering' => true]
            );
            if ($r['applied']) {
                $applied++;
            }
        }
        $this->assertSame(0, $applied);
    }

    public function testMagnitudeReportsCapApplied(): void
    {
        $r = $this->effect->compute(
            ['level' => 10, 'experience' => 5000, 'agility' => 5000, 'intellect' => 5000, 'gold' => 0],
            ['effect_params' => [
                'base_range'        => [150000, 150000],  // фіксовано high
                'stat_divisor'      => 3000.0,
                'effect_value_mul'  => true,
                'cap_formula'       => 'level_50',  // cap = 500
                'requires_state'    => 'gather',
                'chance_per_tick'   => 1.0,
            ]],
            ['effect_value' => 100],
            ['is_gathering' => true]
        );
        $this->assertTrue($r['applied']);
        $this->assertTrue($r['magnitude']['cap_applied']);
        $this->assertSame(500, $r['magnitude']['cap_value']);
    }
}
