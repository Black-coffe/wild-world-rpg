<?php

declare(strict_types=1);

namespace App\Services\Events\Effects;

use App\Attributes\HandlerKey;
use App\Services\Events\EventEffectInterface;

/**
 * F7.2 — обчислення % втрати ресурсів. Використовується MeteorRain (rework з
 * random-cells на biome-targeted).
 *
 * Підтримувані params:
 *   - percent_per_event : float — % втрати від кожного stack ресурсів
 *   - state_modifier    : array — захист на базі (як у damage_health)
 *   - min_stack         : int   — нижче якого числа stack не падає (default 1)
 *   - one_shot_at_end   : bool  — застосовувати разово в кінці події (dispatcher вирішує
 *                                 чи кликати compute() per-tick чи only-on-end)
 *
 * Не виконує DB. Повертає `resource_loss_percent` — диспетчер пройде по
 * character_resources і застосує це у %.
 */
#[HandlerKey(
    key: 'damage_resources',
    displayName: 'Потеря ресурсов',
    description: 'Снижает запас ресурсов персонажа на % за событие (MeteorRain, MeteorImpact).',
    inputSchema: [
        ['name' => 'percent_per_event', 'type' => 'int', 'min' => 1, 'max' => 100],
        ['name' => 'state_modifier', 'type' => 'state_modifier'],
        ['name' => 'min_stack', 'type' => 'int', 'min' => 1, 'max' => 1000],
        ['name' => 'one_shot_at_end', 'type' => 'bool'],
    ],
)]
final class DamageResourcesEffect implements EventEffectInterface
{
    public function compute(array|\App\Entities\CharacterEntity $character, array $eventConfig, array $activeEvent, array $context): array
    {
        $params = $eventConfig['effect_params'] ?? [];

        $stateModifier = $params['state_modifier'] ?? ['base_idle' => 0.0, 'biome_idle' => 0.7, 'biome_active' => 1.0];
        $stateCoef     = EffectResultFactory::stateCoefficient($stateModifier, $context);

        if ($stateCoef <= 0.0) {
            return EffectResultFactory::skipped('Захищений (state_coef=0)');
        }

        // E17 (ADR-117) — уровневый tier-модификатор потери ресурсов (dormant → ×1.0 byte-identical).
        $tier    = (new \App\Services\Events\EventLevelTierService())->harmFactorFor($character);
        $percent = (float)($params['percent_per_event'] ?? 15) * $stateCoef * $tier;

        // Protection item — -50% втрати
        if (!empty($context['has_protection_item'])) {
            $percent *= 0.5;
        }

        return EffectResultFactory::make([
            'applied'              => true,
            'resource_loss_percent' => round($percent, 2),
            'log_summary'          => "Ресурси: -{$percent}%",
            'magnitude'            => [
                'resource_loss_percent' => $percent,
                'effect_kind'           => 'damage_resources',
            ],
        ]);
    }
}
