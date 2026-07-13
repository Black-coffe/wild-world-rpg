<?php

namespace App\Services\Player\BuildingUpgrade;

use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use App\Services\PVE\DefenseStructureService;

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
    private CharacterResourceModel $characterResourceModel;
    private ResourceModel $resourceModel;
    private CharacterBuildingModel $characterBuildingModel;
    private BuildingModel $buildingModel;
    private DefenseStructureService $defenseService;

    public function __construct(
        ?CharacterResourceModel $characterResourceModel = null,
        ?ResourceModel $resourceModel = null,
        ?CharacterBuildingModel $characterBuildingModel = null,
        ?BuildingModel $buildingModel = null,
        ?DefenseStructureService $defenseService = null
    ) {
        $this->characterResourceModel = $characterResourceModel ?? new CharacterResourceModel();
        $this->resourceModel          = $resourceModel          ?? new ResourceModel();
        $this->characterBuildingModel = $characterBuildingModel ?? new CharacterBuildingModel();
        $this->buildingModel          = $buildingModel          ?? new BuildingModel();
        $this->defenseService         = $defenseService         ?? new DefenseStructureService();
    }

    /**
     * @param array<string,mixed>|\App\Entities\CharacterEntity $character    Row/Entity з 'id' і 'gold'
     * @param array<string,mixed>     $charBuilding Row from character_buildings з 'id'
     * @param int                     $nextLevel    Target level (currentLevel + 1)
     * @param array{gold:int,resources:array<string,int>} $requirements
     */
    public function apply(array|\App\Entities\CharacterEntity $character, array $charBuilding, int $nextLevel, array $requirements): void
    {
        // Fix 2026-07-13 (класс lost-update): атомарное относительное списание
        // от СВЕЖЕГО золота (CharacterStatsService), floor 0 — дефолтный.
        (new \App\Services\Player\CharacterStatsService())
            ->adjust((int) $character['id'], ['gold' => -(int) $requirements['gold']]);

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

        $update = ['level' => $nextLevel];

        // ADR-041: апгрейд оборонной структуры восстанавливает hp до НОВОГО maxHp
        // (выше уровень = крепче + полный ремонт «в подарок» при апгрейде).
        if (($charBuilding['building_type'] ?? '') === 'defensive') {
            $bidRaw     = $charBuilding['building_id'] ?? null;
            $tmpl       = $this->buildingModel->find(is_numeric($bidRaw) ? (int) $bidRaw : 0);
            $hpRaw      = is_array($tmpl) ? ($tmpl['hp'] ?? null) : null;
            $templateHp = is_numeric($hpRaw) ? (int) $hpRaw : 0;
            if ($templateHp > 0) {
                $update['hp'] = $this->defenseService->maxHpFor($templateHp, $nextLevel);
            }
        }

        $this->characterBuildingModel->update($charBuilding['id'], $update);
    }
}
