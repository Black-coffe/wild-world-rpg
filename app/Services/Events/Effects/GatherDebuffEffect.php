<?php

declare(strict_types=1);

namespace App\Services\Events\Effects;

use App\Services\Events\EventEffectInterface;

/**
 * F7.2 — debuff на gather rate. Используетться Dryness, LocustExodus.
 *
 * Pure-strategy: возвращает `gather_modifier` (например -0.30 для Dryness).
 * Реальне применення — в GatherTaskHandler через GatherEventModifierService
 * (F2.7c уже існуесть и читаесть active_events; розширимо його щоб дивився Events.php
 * config в F7.3).
 *
 * Підтримувани params:
 *   - gather_rate_modifier         : float — например -0.30 (-30% gather)
 *   - water_consumption_multiplier : float — например 2.0 (×2 водоспоживання)
 *   - food_only                    : bool — модифікатор лише для food-resources
 *   - biome_type_filter            : ?string — null = global; иначе только в типи биому
 */
final class GatherDebuffEffect implements EventEffectInterface
{
    public function compute(array $character, array $eventConfig, array $activeEvent, array $context): array
    {
        $params = $eventConfig['effect_params'] ?? [];

        // Фільтр biome_type_filter
        $biomeFilter = $params['biome_type_filter'] ?? null;
        if ($biomeFilter !== null) {
            $playerBiomeType = $context['biome']['biome_type'] ?? null;
            if ($playerBiomeType !== $biomeFilter) {
                return EffectResultFactory::skipped("Не той biome_type ({$playerBiomeType} != {$biomeFilter})");
            }
        }

        $modifier = (float)($params['gather_rate_modifier'] ?? 0.0);

        // Це не tick-effect, це state — игрок маесть бути в курси через start-уведомлению,
        // а реальне применення — в GatherTaskHandler. compute() возвращает intent.
        return EffectResultFactory::make([
            'applied'         => true,
            'gather_modifier' => $modifier,
            'log_summary'     => "Gather: " . ($modifier * 100) . "%",
            'magnitude'       => [
                'effect_kind'    => 'gather_debuff',
                'modifier'       => $modifier,
                'water_mul'      => $params['water_consumption_multiplier'] ?? 1.0,
                'food_only'      => $params['food_only'] ?? false,
            ],
        ]);
    }
}
