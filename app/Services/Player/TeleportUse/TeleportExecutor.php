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
     * @param array<string,mixed> $additionalFields — optional payment fields (gold/experience/etc.)
     */
    public function teleport(int $characterId, array $claimedCell, array $mapRow, array $additionalFields = []): void
    {
        $update = array_merge($additionalFields, [
            'cell_number' => $claimedCell['map_cell_id'],
            'biome_id'    => $mapRow['biome_id'],
        ]);

        $this->characterModel->update($characterId, $update);
    }
}
