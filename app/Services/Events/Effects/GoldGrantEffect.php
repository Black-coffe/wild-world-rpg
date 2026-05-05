<?php

declare(strict_types=1);

namespace App\Services\Events\Effects;

use App\Services\Events\EventEffectInterface;

/**
 * F7.2 — видача золота за формулою. Використовується GoldMine.
 *
 * Формула 1:1 з GoldVeinHandler::calculateGoldFound:
 *   baseGold = rand(base_range)
 *   final = baseGold × (exp+agi+int)/stat_divisor × (effect_value/100)
 *
 * F7-новинки:
 *   - chance_per_tick    : 0.06 (6%) — gate перед обчисленням
 *   - requires_state     : 'gather' — не дає золота якщо не gathering
 *   - cap_formula        : 'level_50' → min(gold, max(500, char.level × 50))
 *                          анти-power-creep: на 1 лвл до 500g, на 300 лвл до 15k
 *
 * Підтримувані cap_formula:
 *   - 'level_50'  : min(gold, max(500, level × 50))
 *   - 'level_100' : min(gold, max(1000, level × 100))
 *   - 'none'      : без cap (legacy behavior, ~50k variance)
 */
final class GoldGrantEffect implements EventEffectInterface
{
    public function compute(array $character, array $eventConfig, array $activeEvent, array $context): array
    {
        $params = $eventConfig['effect_params'] ?? [];

        // Фільтр: chance_per_tick
        $chance = (float)($params['chance_per_tick'] ?? 0.06);
        if (mt_rand(1, 10000) / 10000.0 > $chance) {
            return EffectResultFactory::skipped("Не випало (chance={$chance})");
        }

        // Фільтр: requires_state
        $reqState = $params['requires_state'] ?? null;
        if ($reqState === 'gather' && empty($context['is_gathering'])) {
            return EffectResultFactory::skipped('Не gathering (GoldMine vimagaет gather)');
        }
        if ($reqState === 'explore' && empty($context['is_exploring'])) {
            return EffectResultFactory::skipped('Не exploring');
        }

        // Формула
        $baseRange   = $params['base_range'] ?? [1000, 150000];
        $statDivisor = (float)($params['stat_divisor'] ?? 3000.0);
        $effectValue = (float)($activeEvent['effect_value'] ?? 50);

        $exp = (float)($character['experience'] ?? 0);
        $agi = (float)($character['agility']    ?? 0);
        $int = (float)($character['intellect']  ?? 0);

        $base  = (float)mt_rand((int)$baseRange[0], (int)$baseRange[1]);
        $stats = ($exp + $agi + $int) / max(0.001, $statDivisor);

        $gold = $base * $stats;
        if (!empty($params['effect_value_mul'])) {
            $gold *= $effectValue / 100.0;
        }
        $gold = (int)round($gold);

        // Cap formula
        $capFormula = $params['cap_formula'] ?? 'none';
        $level      = max(1, (int)($character['level'] ?? 1));
        $cap        = match ($capFormula) {
            'level_50'  => max(500, $level * 50),
            'level_100' => max(1000, $level * 100),
            default     => PHP_INT_MAX,
        };
        $gold = min($gold, $cap);

        if ($gold <= 0) {
            return EffectResultFactory::skipped('Gold = 0 після формули');
        }

        return EffectResultFactory::make([
            'applied'     => true,
            'gold_delta'  => $gold,
            'log_summary' => "+{$gold} золота",
            'magnitude'   => [
                'effect_kind' => 'gold_grant',
                'gold_gain'   => $gold,
                'cap_applied' => $gold === $cap,
                'cap_value'   => $cap,
            ],
        ]);
    }
}
