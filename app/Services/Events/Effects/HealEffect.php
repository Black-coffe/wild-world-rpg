<?php

declare(strict_types=1);

namespace App\Services\Events\Effects;

use App\Services\Events\EventEffectInterface;

/**
 * F7.2 — heal health/tired. Используетться GeothermalFountains.
 *
 * Підтримувани params:
 *   - heal_target       : 'health' | 'tired' | 'random_h_or_t' | 'both'
 *   - amount_range      : [min, max] — random rand(min, max)
 *   - cap               : float — не превышувати (зазвичай 100)
 *   - one_shot_at_start : bool  — применувати раз/event/игрок (dispatcher вирішує)
 *
 * Protection item НЕ применується (це buff, не damage).
 */
final class HealEffect implements EventEffectInterface
{
    public function compute(array $character, array $eventConfig, array $activeEvent, array $context): array
    {
        $params = $eventConfig['effect_params'] ?? [];

        $range  = $params['amount_range'] ?? [1, 10];
        $amount = (float)mt_rand((int)$range[0], (int)$range[1]);
        $cap    = (float)($params['cap'] ?? 100);

        $target  = $params['heal_target'] ?? 'random_h_or_t';
        $applyTo = $target;

        if ($target === 'random_h_or_t') {
            $applyTo = mt_rand(0, 1) === 0 ? 'health' : 'tired';
        }

        $healthDelta = 0.0;
        $tiredDelta  = 0.0;
        $logParts    = [];

        if ($applyTo === 'health' || $applyTo === 'both') {
            $current   = (float)($character['health'] ?? 0);
            $headroom  = max(0.0, $cap - $current);
            $applied   = min($amount, $headroom);
            if ($applied > 0) {
                $healthDelta = $applied;
                $logParts[]  = "+{$applied} HP";
            }
        }

        if ($applyTo === 'tired' || $applyTo === 'both') {
            $current   = (float)($character['tired'] ?? 0);
            $headroom  = max(0.0, $cap - $current);
            $applied   = min($amount, $headroom);
            if ($applied > 0) {
                $tiredDelta = $applied;
                $logParts[] = "+{$applied} вынос.";
            }
        }

        if ($healthDelta === 0.0 && $tiredDelta === 0.0) {
            return EffectResultFactory::skipped("Уже на капв ({$cap})");
        }

        return EffectResultFactory::make([
            'applied'      => true,
            'health_delta' => $healthDelta,
            'tired_delta'  => $tiredDelta,
            'log_summary'  => implode(', ', $logParts),
            'magnitude'    => [
                'effect_kind'  => 'heal',
                'heal_amount'  => $healthDelta + $tiredDelta,
            ],
        ]);
    }
}
