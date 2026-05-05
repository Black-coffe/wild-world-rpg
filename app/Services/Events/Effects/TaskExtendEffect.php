<?php

declare(strict_types=1);

namespace App\Services\Events\Effects;

use App\Services\Events\EventEffectInterface;

/**
 * F7.2 — подовження активних задач + опційні side_effects (h/t/water loss).
 * Використовується MirageOases, PolarNight.
 *
 * Pure-strategy: повертає `task_extension_intent` (intent з filter + extra_minutes),
 * dispatcher F7.3 робить SQL UPDATE на character_tasks.end_time.
 *
 * Підтримувані params:
 *   - task_filter         : list<string>  — наприклад ['Gather','ExploreTheArea']
 *   - extra_minutes_range : [min, max]
 *   - state_modifier      : array — на базі ефект ослаблений (як у damage_health)
 *   - side_effects        : array — опційно: water_loss_range / health_loss_range / tired_loss_range
 *   - grants_immunity_to  : list<string> — informational, dispatcher використовує для skip-ів
 *
 * NB: фільтрацію власне activeEvent тасків (gather/explore in_work) робить dispatcher.
 */
final class TaskExtendEffect implements EventEffectInterface
{
    public function compute(array $character, array $eventConfig, array $activeEvent, array $context): array
    {
        $params = $eventConfig['effect_params'] ?? [];

        $stateModifier = $params['state_modifier'] ?? ['base_idle' => 0.25, 'biome_idle' => 1.0, 'biome_active' => 1.0];
        $stateCoef     = EffectResultFactory::stateCoefficient($stateModifier, $context);

        if ($stateCoef <= 0.0) {
            return EffectResultFactory::skipped('Захищений (state_coef=0)');
        }

        $range = $params['extra_minutes_range'] ?? [1, 15];
        $extra = (int)round(mt_rand((int)$range[0], (int)$range[1]) * $stateCoef);

        if ($extra <= 0) {
            return EffectResultFactory::skipped('Extra=0');
        }

        // Side-effects (water/health/tired loss)
        $sides       = $params['side_effects'] ?? [];
        $healthDelta = 0.0;
        $tiredDelta  = 0.0;
        $logParts    = ["+{$extra} хв до задач"];

        if (isset($sides['health_loss_range'])) {
            [$min, $max] = $sides['health_loss_range'];
            $loss        = (int)round(mt_rand((int)$min, (int)$max) * $stateCoef);
            $healthDelta = -$loss;
            $logParts[]  = "-{$loss} HP";
        }
        if (isset($sides['tired_loss_range'])) {
            [$min, $max] = $sides['tired_loss_range'];
            $loss        = (int)round(mt_rand((int)$min, (int)$max) * $stateCoef);
            $tiredDelta  = -$loss;
            $logParts[]  = "-{$loss} вынос.";
        }
        // water_loss_range — якщо буде окремий water-stat у characters; зараз skip.

        return EffectResultFactory::make([
            'applied'              => true,
            'health_delta'         => $healthDelta,
            'tired_delta'          => $tiredDelta,
            'task_extension_intent' => [
                'filter'        => $params['task_filter'] ?? ['Gather', 'ExploreTheArea'],
                'extra_minutes' => $extra,
            ],
            'log_summary'          => implode(', ', $logParts),
            'magnitude'            => [
                'effect_kind'   => 'task_extend',
                'extra_minutes' => $extra,
            ],
        ]);
    }
}
