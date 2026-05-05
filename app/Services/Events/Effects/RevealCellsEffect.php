<?php

namespace App\Services\Events\Effects;

use App\Services\Events\EventEffectInterface;

/**
 * F7.2 — открыття свседніх ячейок карты. Используетться MountainEcho.
 *
 * Pure-strategy: возвращает `reveal_cells_intent` с кількістю — dispatcher F7.3
 * вызоваесть `MapModel->getSurroundingCells($charCell, $count)` и шле игроку.
 *
 * Підтримувани params:
 *   - level_table       : map<int, float> — level → percent → count
 *   - on_base_modifier  : float — на бази ефект ослаблений (0.25 = -75%)
 *   - one_shot_at_start : bool
 *
 * Формула 1:1 с MountainEchoHandler::determineNumberOfCells:
 *   percent = lookup(level_table, char.level)
 *   count = floor(24 × percent × effect_value / 10000)
 */
final class RevealCellsEffect implements EventEffectInterface
{
    public function compute(array $character, array $eventConfig, array $activeEvent, array $context): array
    {
        $params      = $eventConfig['effect_params'] ?? [];
        $levelTable  = $params['level_table'] ?? [];
        $onBaseMod   = (float)($params['on_base_modifier'] ?? 0.25);
        $effectValue = (float)($activeEvent['effect_value'] ?? 55);
        $level       = max(1, (int)($character['level'] ?? 1));

        // Найти percent с level_table
        $percent = 0.0;
        ksort($levelTable);
        foreach ($levelTable as $maxLevel => $p) {
            if ($level <= (int)$maxLevel) {
                $percent = (float)$p;
                break;
            }
        }

        if ($percent <= 0) {
            return EffectResultFactory::skipped("Level {$level} поза level_table");
        }

        $count = (int)floor((24.0 * $percent * $effectValue) / 10000.0);

        if ($count <= 0) {
            return EffectResultFactory::skipped("Count = 0 после формули");
        }

        // На бази ефект ослаблений
        if (!empty($context['on_base'])) {
            $count = (int)floor($count * $onBaseMod);
            if ($count <= 0) {
                return EffectResultFactory::skipped('На бази ефект знівельовано');
            }
        }

        return EffectResultFactory::make([
            'applied'             => true,
            'reveal_cells_intent' => ['count' => $count],
            'log_summary'         => "Открыто {$count} ячейок",
            'magnitude'           => [
                'effect_kind'  => 'reveal_cells',
                'cells_count'  => $count,
                'on_base'      => !empty($context['on_base']),
            ],
        ]);
    }
}
