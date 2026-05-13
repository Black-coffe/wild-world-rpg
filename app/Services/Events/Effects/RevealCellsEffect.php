<?php

declare(strict_types=1);

namespace App\Services\Events\Effects;

use App\Attributes\HandlerKey;
use App\Services\Events\EventEffectInterface;

/**
 * F7.2 — відкриття сусідніх ячейок мапи. Використовується MountainEcho.
 *
 * Pure-strategy: повертає `reveal_cells_intent` з кількістю — dispatcher F7.3
 * викликає `MapModel->getSurroundingCells($charCell, $count)` і шле гравцеві.
 *
 * Підтримувані params:
 *   - level_table       : map<int, float> — level → percent → count
 *   - on_base_modifier  : float — на базі ефект ослаблений (0.25 = -75%)
 *   - one_shot_at_start : bool
 *
 * Формула 1:1 з MountainEchoHandler::determineNumberOfCells:
 *   percent = lookup(level_table, char.level)
 *   count = floor(24 × percent × effect_value / 10000)
 */
#[HandlerKey(
    key: 'reveal_cells',
    displayName: 'Открытие ячеек карты',
    description: 'Открывает соседние ячейки на карте мира по level_table формуле (MountainEcho).',
    inputSchema: [
        ['name' => 'level_table', 'type' => 'level_table'],
        ['name' => 'on_base_modifier', 'type' => 'float', 'min' => 0.0, 'max' => 1.0],
        ['name' => 'one_shot_at_start', 'type' => 'bool'],
    ],
)]
final class RevealCellsEffect implements EventEffectInterface
{
    public function compute(array|\App\Entities\CharacterEntity $character, array $eventConfig, array $activeEvent, array $context): array
    {
        $params      = $eventConfig['effect_params'] ?? [];
        $levelTable  = $params['level_table'] ?? [];
        $onBaseMod   = (float)($params['on_base_modifier'] ?? 0.25);
        $effectValue = (float)($activeEvent['effect_value'] ?? 55);
        $level       = max(1, (int)($character['level'] ?? 1));

        // Знайти percent з level_table
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
            return EffectResultFactory::skipped("Count = 0 після формули");
        }

        // На базі ефект ослаблений
        if (!empty($context['on_base'])) {
            $count = (int)floor($count * $onBaseMod);
            if ($count <= 0) {
                return EffectResultFactory::skipped('На базі ефект знівельовано');
            }
        }

        return EffectResultFactory::make([
            'applied'             => true,
            'reveal_cells_intent' => ['count' => $count],
            'log_summary'         => "Відкрито {$count} ячейок",
            'magnitude'           => [
                'effect_kind'  => 'reveal_cells',
                'cells_count'  => $count,
                'on_base'      => !empty($context['on_base']),
            ],
        ]);
    }
}
