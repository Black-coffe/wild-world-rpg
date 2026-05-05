<?php

declare(strict_types=1);

namespace App\Services\Events\Effects;

use App\Services\Events\EventEffectInterface;

/**
 * F7.2 — буст случаового атрибуту. Используетться Starfall, NorthernLights.
 *
 * Поведінка 1:1 с ShootingStarHandler/NorthernLightsHandler:
 *   - Випадково с пулу: experience/health/strength/agility/intellect/tired/gold
 *   - Если атрибут health/tired/gold → integer rand(large_boost_range), cap=100
 *   - Если атрибут exp/str/agi/int → дробове rand(small_boost_range)
 *
 * Підтримувани params:
 *   - attribute_pool    : list<string>
 *   - small_boost_range : [min, max] — для stats
 *   - large_boost_range : [min, max] — для health/tired/gold
 *   - cap_h_t_g         : float — не превышувати (зазвичай 100)
 *   - one_shot_at_start : bool  — раз за подію
 */
final class AttributeBoostEffect implements EventEffectInterface
{
    private const LARGE_ATTRS = ['health', 'tired', 'gold'];

    public function compute(array $character, array $eventConfig, array $activeEvent, array $context): array
    {
        $params = $eventConfig['effect_params'] ?? [];
        $pool   = $params['attribute_pool'] ?? ['experience','health','strength','agility','intellect','tired','gold'];
        $cap    = (float)($params['cap_h_t_g'] ?? 100);

        $attr = $pool[array_rand($pool)];

        $isLarge = in_array($attr, self::LARGE_ATTRS, true);
        if ($isLarge) {
            $range = $params['large_boost_range'] ?? [1, 100];
            $value = (float)mt_rand((int)$range[0], (int)$range[1]);

            $current  = (float)($character[$attr] ?? 0);
            $headroom = max(0.0, $cap - $current);
            $value    = min($value, $headroom);

            if ($value <= 0) {
                return EffectResultFactory::skipped("Атрибут {$attr} уже на капу");
            }
        } else {
            $range = $params['small_boost_range'] ?? [1.00, 1.11];
            // [1.00, 1.11] → rand(100, 111) / 100
            $value = round(mt_rand((int)($range[0] * 100), (int)($range[1] * 100)) / 100.0, 2);
        }

        $deltas = [];
        $hd     = 0.0;
        $td     = 0.0;
        $gd     = 0;

        if ($attr === 'health') {
            $hd = $value;
        } elseif ($attr === 'tired') {
            $td = $value;
        } elseif ($attr === 'gold') {
            $gd = (int)$value;
        } else {
            $deltas[$attr] = $value;
        }

        return EffectResultFactory::make([
            'applied'          => true,
            'health_delta'     => $hd,
            'tired_delta'      => $td,
            'gold_delta'       => $gd,
            'attribute_deltas' => $deltas,
            'log_summary'      => "+{$value} до {$attr}",
            'magnitude'        => [
                'effect_kind' => 'attribute_boost',
                'attribute'   => $attr,
                'value'       => $value,
                'gold_gain'   => $gd,
            ],
        ]);
    }
}
