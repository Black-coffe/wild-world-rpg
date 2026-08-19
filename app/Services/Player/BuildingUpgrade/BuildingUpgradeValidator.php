<?php

namespace App\Services\Player\BuildingUpgrade;

use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\ResourceModel;
use App\Services\GameSettings\GameSettingsReaderTrait;
use App\Services\Player\PlayerStateService;
use App\Services\Player\ResourcePoolService;

/**
 * v0.51.57 (UpgradeBuildingAction decomp Step 1) — extract повний validation
 * chain (character/base/building/level/gold/resources) у dedicated service.
 *
 * Public API:
 *   validate(character, buildingId, requirements): array{
 *     ok: bool,
 *     error?: string,                  // single early-fail reason
 *     missingResources?: array<string>, // multi-line list (resources error only)
 *     context?: array{                  // populated only if ok
 *       charBuilding: array,
 *       buildingInfo: array,
 *       currentLevel: int,
 *       nextLevel: int,
 *       requirements: array
 *     }
 *   }
 *
 * Validation steps:
 *  1. Player on base (PlayerStateService)
 *  2. Character has the building (character_buildings)
 *  3. Building info exists у buildings table
 *  4. Building level < MAX_LEVEL (10)
 *  5. nextLevel у $upgradeRequirements config
 *  6. Character level >= required
 *  7. Gold >= required
 *  8. Resources >= required (multi-error accumulation для askForUpgrade UX)
 *
 * Behavior preservation: identical у обох викликах (ask + confirm).
 * Caller вибирає: показати $error single, OR formatted missingResources list.
 */
class BuildingUpgradeValidator
{
    use GameSettingsReaderTrait;

    public const MAX_LEVEL = 10;

    private CharacterBuildingModel $characterBuildingModel;
    private BuildingModel $buildingModel;
    private ResourceModel $resourceModel;
    private PlayerStateService $playerStateService;
    private ResourcePoolService $resourcePool;

    public function __construct(
        ?CharacterBuildingModel $characterBuildingModel = null,
        ?BuildingModel $buildingModel = null,
        ?ResourceModel $resourceModel = null,
        ?PlayerStateService $playerStateService = null,
        ?ResourcePoolService $resourcePool = null
    ) {
        $this->characterBuildingModel = $characterBuildingModel ?? new CharacterBuildingModel();
        $this->buildingModel          = $buildingModel          ?? new BuildingModel();
        $this->resourceModel          = $resourceModel          ?? new ResourceModel();
        $this->playerStateService     = $playerStateService     ?? new PlayerStateService();
        $this->resourcePool           = $resourcePool           ?? new ResourcePoolService();
    }

    /**
     * @param array<string,mixed>|\App\Entities\CharacterEntity $character
     * @param array<int,array<string,mixed>> $upgradeRequirements 2D array indexed by nextLevel
     * @return array<string,mixed>
     */
    public function validate(array|\App\Entities\CharacterEntity $character, int $buildingId, array $upgradeRequirements): array
    {
        // 1) Player on base
        if (!$this->playerStateService->isCharacterOnBase($character['id'])) {
            return ['ok' => false, 'error' => "Вы не на базе или база отсутствует. Нельзя улучшать постройки."];
        }

        // 2) Character has the building
        $charBuilding = $this->characterBuildingModel
            ->where('character_id', $character['id'])
            ->where('building_id', $buildingId)
            ->first();
        if (!$charBuilding) {
            return ['ok' => false, 'error' => "У вас нет здания с ID={$buildingId}."];
        }

        // 3) Building info exists
        $buildingInfo = $this->buildingModel->find($buildingId);
        if (!$buildingInfo) {
            return ['ok' => false, 'error' => "Справочная информация о здании (ID={$buildingId}) не найдена."];
        }

        // 4) Building below MAX_LEVEL
        $currentLevel = (int) $charBuilding['level'];
        if ($currentLevel >= self::MAX_LEVEL) {
            return ['ok' => false, 'error' => "Здание уже достигло максимального уровня (" . self::MAX_LEVEL . ")."];
        }

        // 5) Requirements available для nextLevel
        $nextLevel = $currentLevel + 1;
        if (!isset($upgradeRequirements[$nextLevel])) {
            return ['ok' => false, 'error' => "Нет данных для апгрейда до уровня {$nextLevel}."];
        }
        $req = $upgradeRequirements[$nextLevel];

        // ADR-043: per-building множитель ЗОЛОТА цены апгрейда (default 1.0 = базовая цена).
        // Единая точка: scaled $req идёт и в проверку золота, и в context (formatter + applier).
        $nameEn = '';
        if (is_array($buildingInfo)) {
            $rawName = $buildingInfo['name_en'] ?? null;
            $nameEn  = is_string($rawName) ? strtolower($rawName) : '';
        }
        if ($nameEn !== '') {
            $mult     = $this->gsFloat('economy.upgrade.cost_multiplier.' . $nameEn, 1.0);
            $baseGold = is_numeric($req['gold'] ?? null) ? (int) $req['gold'] : 0;
            $req['gold'] = (int) round($baseGold * $mult);
        }

        // 6) Character level >= required
        $requiredCharLvl = (int) $req['level'];
        if ((int) $character['level'] < $requiredCharLvl) {
            return ['ok' => false, 'error' => "Нужно иметь уровень >= {$requiredCharLvl}, у вас: {$character['level']}."];
        }

        // 7) Gold >= required
        $requiredGold = (int) $req['gold'];
        if ((int) $character['gold'] < $requiredGold) {
            return ['ok' => false, 'error' => "Нужно золото: {$requiredGold}, у вас: {$character['gold']}."];
        }

        // 8) Resources — accumulate ALL missing (multi-line error для UX)
        // ADR-171: достаточность считается по пулу рюкзак+склад (когда игрок на
        // базе), не только по рюкзаку — иначе отказ называл бы карманный остаток,
        // который не сходится с тем, что игрок видит на складе.
        //
        // Резолвим id/name ВСЕХ ресурсов ОДНИМ `whereIn()`-запросом до цикла, а
        // не `where()->first()` на переиспользуемой модели внутри — CI4 Model
        // builder state не гарантированно сбрасывается между вызовами в loop'е
        // (см. memory `ci4-model-builder-state-quirk`, живой инцидент S5b: второй
        // ресурс в цикле молча не находился из-за накопленного `AND` в builder'е —
        // для рецепта апгрейда с 2+ ресурсами вторая и далее строки молча не
        // проверялись бы).
        $resources     = is_array($req['resources'] ?? null) ? $req['resources'] : [];
        $resourceNames = [];
        foreach ($resources as $resNameEn => $needQty) {
            if (is_string($resNameEn) && is_numeric($needQty) && $needQty > 0) {
                $resourceNames[] = $resNameEn;
            }
        }

        $resourceRows = [];
        if ($resourceNames !== []) {
            foreach ($this->resourceModel->whereIn('name_en', $resourceNames)->findAll() as $row) {
                $r         = $row->toArray();
                $nameEnKey = is_string($r['name_en'] ?? null) ? $r['name_en'] : null;
                if ($nameEnKey !== null) {
                    $resourceRows[$nameEnKey] = $r;
                }
            }
        }

        $charId           = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $missingResources = [];
        foreach ($resources as $resNameEn => $needQty) {
            if (!is_string($resNameEn) || $needQty <= 0) {
                continue;
            }
            $resRow = $resourceRows[$resNameEn] ?? null;
            if (!is_array($resRow)) {
                $missingResources[] = "- Неизвестный ресурс {$resNameEn}";
                continue;
            }
            $resourceId = is_numeric($resRow['id'] ?? null) ? (int) $resRow['id'] : 0;
            $playerHas  = $this->resourcePool->available($charId, $resourceId);

            if ($playerHas < $needQty) {
                $missingResources[] = "- {$resRow['name']} (нужно: {$needQty}, есть: {$playerHas})";
            }
        }

        if (!empty($missingResources)) {
            return [
                'ok'               => false,
                'missingResources' => $missingResources,
                'nextLevel'        => $nextLevel, // for header text
            ];
        }

        // ALL VALID
        return [
            'ok'      => true,
            'context' => [
                'charBuilding'  => $charBuilding,
                'buildingInfo'  => $buildingInfo,
                'currentLevel'  => $currentLevel,
                'nextLevel'     => $nextLevel,
                'requirements'  => $req,
            ],
        ];
    }
}
