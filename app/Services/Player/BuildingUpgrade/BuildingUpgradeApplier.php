<?php

namespace App\Services\Player\BuildingUpgrade;

use App\Models\CharacterBuildingModel;
use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;

/**
 * v0.51.60 (UpgradeBuildingAction decomp Step 3) — extract DB write block
 * у dedicated applier service: gold subtract + resources decrease + level bump.
 *
 * Public API:
 *   apply(character, charBuilding, nextLevel, requirements): void
 *
 * Pre-conditions: caller already validated through BuildingUpgradeValidator —
 * character has gold/level/resources, building exists, nextLevel reachable.
 *
 * Behavior preservation: identical 3-step sequence as inline у confirmUpgrade
 * pre-decomp:
 *  1. characters.gold -= requirements.gold
 *  2. for each (name_en, qty) in resources: character_resources.decreaseResources(...)
 *  3. character_buildings.level = nextLevel
 *
 * Resource lookup tolerates missing name_en (skip silently — same as legacy).
 */
class BuildingUpgradeApplier
{
    private CharacterModel $characterModel;
    private CharacterResourceModel $characterResourceModel;
    private ResourceModel $resourceModel;
    private CharacterBuildingModel $characterBuildingModel;

    public function __construct(
        ?CharacterModel $characterModel = null,
        ?CharacterResourceModel $characterResourceModel = null,
        ?ResourceModel $resourceModel = null,
        ?CharacterBuildingModel $characterBuildingModel = null
    ) {
        $this->characterModel         = $characterModel         ?? new CharacterModel();
        $this->characterResourceModel = $characterResourceModel ?? new CharacterResourceModel();
        $this->resourceModel          = $resourceModel          ?? new ResourceModel();
        $this->characterBuildingModel = $characterBuildingModel ?? new CharacterBuildingModel();
    }

    /**
     * @param array<string,mixed>     $character    Row with at least 'id' and 'gold'
     * @param array<string,mixed>     $charBuilding Row from character_buildings з 'id'
     * @param int                     $nextLevel    Target level (currentLevel + 1)
     * @param array{gold:int,resources:array<string,int>} $requirements
     */
    public function apply(array $character, array $charBuilding, int $nextLevel, array $requirements): void
    {
        $newGold = (int) $character['gold'] - (int) $requirements['gold'];
        $this->characterModel->update($character['id'], ['gold' => $newGold]);

        foreach ($requirements['resources'] as $resNameEn => $needQty) {
            if ($needQty <= 0) {
                continue;
            }
            $resRow = $this->resourceModel->where('name_en', $resNameEn)->first();
            if (!$resRow) {
                continue;
            }
            $this->characterResourceModel->decreaseResources(
                (int) $character['id'],
                (int) $resRow['id'],
                (int) $needQty
            );
        }

        $this->characterBuildingModel->update($charBuilding['id'], [
            'level' => $nextLevel,
        ]);
    }
}
