<?php

namespace Tests\Unit\Services\Events\Effects;

use App\Services\Events\Effects\AttributeBoostEffect;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * F7.2 — тесты на AttributeBoostEffect (Starfall, NorthernLights).
 * @internal
 */
final class AttributeBoostEffectTest extends CIUnitTestCase
{
    private AttributeBoostEffect $effect;

    protected function setUp(): void
    {
        parent::setUp();
        $this->effect = new AttributeBoostEffect();
    }

    public function testSmallStatsBoostInRange(): void
    {
        // Запускаем багато разів, проверяем що exp/str/agi/int отримують [1.00, 1.11]
        for ($i = 0; $i < 100; $i++) {
            $r = $this->effect->compute(
                ['health' => 50, 'tired' => 50, 'gold' => 0, 'experience' => 5, 'strength' => 5, 'agility' => 5, 'intellect' => 5],
                ['effect_params' => [
                    'attribute_pool'    => ['experience','strength','agility','intellect'],
                    'small_boost_range' => [1.00, 1.11],
                    'large_boost_range' => [1, 100],
                    'cap_h_t_g'         => 100,
                ]],
                [],
                []
            );

            if (!$r['applied']) {
                continue;
            }
            $this->assertCount(1, $r['attribute_deltas']);
            $val = array_values($r['attribute_deltas'])[0];
            $this->assertGreaterThanOrEqual(1.00, $val);
            $this->assertLessThanOrEqual(1.11, $val);
        }
    }

    public function testLargeBoostHealthRespectsCap(): void
    {
        $r = $this->effect->compute(
            ['health' => 100, 'tired' => 0, 'gold' => 0],
            ['effect_params' => [
                'attribute_pool'    => ['health'],  // только health
                'large_boost_range' => [50, 100],
                'cap_h_t_g'         => 100,
            ]],
            [],
            []
        );
        $this->assertFalse($r['applied'], 'health=100, на капу');
    }

    public function testLargeBoostGoldNotCapped(): void
    {
        // gold не должен cap у great_boost_range — large_boost_range стрясає [1..100]
        // але cap_h_t_g обрізає до 100. Тут cap не релевантний для gold-grant
        // (для gold AttributeBoost вкладає [1..100] незалежно від поточного gold).
        // Проверка: at least one з 100 попыток дав gold > 0
        $hits = 0;
        for ($i = 0; $i < 50; $i++) {
            $r = $this->effect->compute(
                ['health' => 50, 'tired' => 50, 'gold' => 50],
                ['effect_params' => [
                    'attribute_pool'    => ['gold'],
                    'large_boost_range' => [1, 100],
                    'cap_h_t_g'         => 100,
                ]],
                [],
                []
            );
            if ($r['gold_delta'] > 0) {
                $hits++;
            }
        }
        $this->assertGreaterThan(20, $hits, 'gold должен падати в більшости попыток');
    }

    public function testMagnitudeReportsAttribute(): void
    {
        $r = $this->effect->compute(
            ['health' => 50, 'tired' => 50, 'gold' => 0, 'experience' => 5, 'strength' => 5, 'agility' => 5, 'intellect' => 5],
            ['effect_params' => [
                'attribute_pool'    => ['experience'],
                'small_boost_range' => [1.00, 1.11],
                'large_boost_range' => [1, 100],
                'cap_h_t_g'         => 100,
            ]],
            [],
            []
        );
        $this->assertTrue($r['applied']);
        $this->assertSame('attribute_boost', $r['magnitude']['effect_kind']);
        $this->assertSame('experience', $r['magnitude']['attribute']);
    }
}
