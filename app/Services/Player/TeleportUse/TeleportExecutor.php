<?php

namespace App\Services\Player\TeleportUse;

use App\Models\CharacterModel;

/**
 * v0.51.65 (TeleportUseAction decomp Step 3) — extract single DB write
 * (character.cell_number + biome_id) у dedicated executor.
 *
 * Усі 4 teleport variants share той самий update pattern:
 *   characters.cell_number = claimedCell.map_cell_id
 *   characters.biome_id    = mapRow.biome_id
 * + optional payment field (gold/experience) при cost-bearing teleports.
 *
 * API:
 *   teleport(charId, claimedCell, mapRow, additionalFields = []): void
 *
 * additionalFields → merge у single update SQL (1 query замість 2 коли є cost).
 * Перенесений з Step 1 perf-bonus (combineб 2 updates у 1 для experience).
 */
class TeleportExecutor
{
    private CharacterModel $characterModel;

    public function __construct(?CharacterModel $characterModel = null)
    {
        $this->characterModel = $characterModel ?? new CharacterModel();
    }

    /**
     * @param array<string,mixed> $claimedCell — must have 'map_cell_id'
     * @param array<string,mixed> $mapRow      — must have 'biome_id'
     * @param array<string,int|float> $statDeltas — optional payment DELTAS (например ['gold' => -500])
     *
     * Fix 2026-07-13 (класс lost-update): оплата (gold/experience) — атомарной
     * ДЕЛЬТОЙ от свежих значений под row-lock'ом (CharacterStatsService), а не
     * абсолютом из снапшота вызова. Позиция — отдельным update (+1 SQL при
     * cost-bearing телепорте — цена корректности).
     */
    public function teleport(int $characterId, array $claimedCell, array $mapRow, array $statDeltas = []): void
    {
        if ($statDeltas !== []) {
            (new \App\Services\Player\CharacterStatsService())->adjust($characterId, $statDeltas);
        }

        $this->characterModel->update($characterId, [
            'cell_number' => $claimedCell['map_cell_id'],
            'biome_id'    => $mapRow['biome_id'],
        ]);
    }
}
