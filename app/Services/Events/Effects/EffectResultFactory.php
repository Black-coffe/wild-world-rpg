<?php

declare(strict_types=1);

namespace App\Services\Events\Effects;

/**
 * F7.2 — фабрика для побудови EffectResult-array'їв.
 *
 * Усі ефекти повертають однотипний array згідно EventEffectInterface contract.
 * Цей factory гарантує консистентну структуру, заповнюючи default'ами
 * усі поля, які конкретний effect не використовує.
 *
 * Use:
 *     $r = EffectResultFactory::skipped('Не пройшов state-фільтр');
 *     $r = EffectResultFactory::make(['health_delta' => -23.5, 'log_summary' => 'Hurricane: -23 HP']);
 */
final class EffectResultFactory
{
    public const DEFAULTS = [
        'applied'                => false,
        'health_delta'           => 0.0,
        'tired_delta'            => 0.0,
        'gold_delta'             => 0,
        'attribute_deltas'       => [],
        'resource_grant_intents' => [],
        'resource_loss_percent'  => 0.0,
        'task_extension_intent'  => null,
        'reveal_cells_intent'    => null,
        'gather_modifier'        => 0.0,
        'log_summary'            => '',
        'magnitude'              => [],
    ];

    /**
     * Зібрати EffectResult з override-полів. Усі необхідні дефолти вже заповнено.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function make(array $overrides = []): array
    {
        return array_merge(self::DEFAULTS, $overrides);
    }

    /**
     * Skipped result (effect не спрацював через probability/state/cooldown).
     */
    public static function skipped(string $reason = ''): array
    {
        return self::make([
            'applied'     => false,
            'log_summary' => $reason !== '' ? "[skipped] {$reason}" : '',
        ]);
    }

    /**
     * Resolve player_state з context.
     * Повертає одне з: 'base_idle', 'biome_idle', 'biome_active'.
     */
    public static function playerStateKey(array $context): string
    {
        if (isset($context['player_state'])) {
            return $context['player_state'];
        }
        $onBase    = (bool)($context['on_base']      ?? false);
        $isGather  = (bool)($context['is_gathering'] ?? false);
        $isExplore = (bool)($context['is_exploring'] ?? false);

        if ($onBase && !$isGather && !$isExplore) {
            return 'base_idle';
        }
        if ($isGather || $isExplore) {
            return 'biome_active';
        }
        return 'biome_idle';
    }

    /**
     * Apply state_modifier coefficient (0.0..1.0) до effect-magnitude
     * згідно поточного player state.
     *
     * @param array<string, float> $stateModifier із eventConfig.effect_params.state_modifier
     */
    public static function stateCoefficient(array $stateModifier, array $context): float
    {
        $state = self::playerStateKey($context);
        return (float)($stateModifier[$state] ?? 1.0);
    }
}
