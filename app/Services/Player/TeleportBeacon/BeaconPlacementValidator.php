<?php

namespace App\Services\Player\TeleportBeacon;

use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\CharacterModel;
use App\Models\ClaimedCellModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\MapModel;
use App\Models\TeleportBeaconModel;
use App\Services\Bases\CampCheckService;

/**
 * v0.51.52 (TeleportBeaconSetAction decomp Step 1) — extract 7-step
 * validation chain з `processSetBeacon` у dedicated сервіс.
 *
 * Public API:
 *   validate(int telegramUserId, int coordX, int coordY): array{
 *     ok: bool,
 *     error?: string,                       // user-facing reason
 *     characterId?: int,
 *     mapRow?: array,                       // for installer
 *     beaconItem?: array,                   // for installer (inventory subtract)
 *     playerLevel?: int,
 *     maxBeacons?: int,
 *     existingBeacon?: array|null           // null → free, non-null → capture flow
 *   }
 *
 * Caller routing:
 *   - !ok → send error message
 *   - existingBeacon non-null → showCaptureOption flow
 *   - existingBeacon null → installBeacon flow
 *
 * Validation steps (sequential):
 *   1. characterId lookup by telegram_id
 *   2. active base check (claimed_cells)
 *   3. TeleportationCenter building present
 *   4. TeleportBeaconBasic у inventory (qty >= 1)
 *   5. Max beacons limit (player_level / 10 + building_level, capped)
 *   6. Map cell exists у coords
 *   7. Camp claimed at coords (CampCheckService)
 *   8. Existing beacon at coords:
 *      - own → error
 *      - foreign → capture flow (returned у context)
 *      - none → install flow
 */
class BeaconPlacementValidator
{
    private CharacterModel $characterModel;
    private ClaimedCellModel $claimedCellModel;
    private BuildingModel $buildingModel;
    private CharacterBuildingModel $characterBuildingModel;
    private CraftedItemsModel $craftedItemsModel;
    private CraftedItemsLogModel $craftedItemsLogModel;
    private MapModel $mapModel;
    private TeleportBeaconModel $teleportBeaconModel;
    private CampCheckService $campCheckService;

    public function __construct(
        ?CharacterModel $characterModel = null,
        ?ClaimedCellModel $claimedCellModel = null,
        ?BuildingModel $buildingModel = null,
        ?CharacterBuildingModel $characterBuildingModel = null,
        ?CraftedItemsModel $craftedItemsModel = null,
        ?CraftedItemsLogModel $craftedItemsLogModel = null,
        ?MapModel $mapModel = null,
        ?TeleportBeaconModel $teleportBeaconModel = null,
        ?CampCheckService $campCheckService = null
    ) {
        $this->characterModel         = $characterModel         ?? new CharacterModel();
        $this->claimedCellModel       = $claimedCellModel       ?? new ClaimedCellModel();
        $this->buildingModel          = $buildingModel          ?? new BuildingModel();
        $this->characterBuildingModel = $characterBuildingModel ?? new CharacterBuildingModel();
        $this->craftedItemsModel      = $craftedItemsModel      ?? new CraftedItemsModel();
        $this->craftedItemsLogModel   = $craftedItemsLogModel   ?? new CraftedItemsLogModel();
        $this->mapModel               = $mapModel               ?? new MapModel();
        $this->teleportBeaconModel    = $teleportBeaconModel    ?? new TeleportBeaconModel();
        $this->campCheckService       = $campCheckService       ?? new CampCheckService();
    }

    /**
     * @return array<string,mixed>
     */
    public function validate(int $telegramUserId, int $coordX, int $coordY): array
    {
        // 1) Character lookup
        $characterId = $this->characterModel->getCharacterIdByTelegramId($telegramUserId);
        if (!$characterId) {
            return ['ok' => false, 'error' => "Персонаж не найден (TG={$telegramUserId})."];
        }

        // 2) Active base
        $activeBase = $this->claimedCellModel
            ->where('character_id', $characterId)
            ->where('status', 'active')
            ->first();
        if (!$activeBase) {
            return ['ok' => false, 'error' => "У тебя нет активной базы — без неё нельзя ставить маяк!"];
        }

        // 3) TeleportationCenter building
        $teleportCenter = $this->buildingModel->where('name_en', 'TeleportationCenter')->first();
        if (!$teleportCenter) {
            return ['ok' => false, 'error' => "Ошибка: в базе нет 'TeleportationCenter'."];
        }
        $centerRow = $this->characterBuildingModel
            ->where('character_id', $characterId)
            ->where('building_id', $teleportCenter['id'])
            ->first();
        if (!$centerRow) {
            return ['ok' => false, 'error' => "У тебя нет построенного Центра телепортации!"];
        }

        // 4) TeleportBeaconBasic у inventory
        $beaconItem = $this->craftedItemsModel->where('name_eng', 'TeleportBeaconBasic')->first();
        if (!$beaconItem) {
            return ['ok' => false, 'error' => "Не найден предмет 'TeleportBeaconBasic'."];
        }
        $beaconLog = $this->craftedItemsLogModel
            ->where('character_id', $characterId)
            ->where('crafted_item_id', $beaconItem['id'])
            ->first();
        if (!$beaconLog || $beaconLog['quantity'] < 1) {
            return ['ok' => false, 'error' => "У тебя нет ни одного маяка в инвентаре!"];
        }

        // 5) Max beacons limit (player_level/10 + building_level, max=10+10=20)
        $charRow         = $this->characterModel->find($characterId);
        $playerLevel     = (int) ($charRow['level'] ?? 1);
        $buildingLevel   = max(1, min(10, (int) $centerRow['level']));
        $baseMaxByPlayer = min(10, intdiv($playerLevel, 10));
        $maxBeacons      = $baseMaxByPlayer + $buildingLevel;

        $existingCount = $this->teleportBeaconModel
            ->where('character_id', $characterId)
            ->countAllResults();
        if ($existingCount >= $maxBeacons) {
            // Путь называем точно: снятие живёт на экране «📡 Маяки» → «🗑 Снять маяк».
            // Раньше отказ советовал «удали старый маяк», а механики удаления не было.
            return [
                'ok'    => false,
                'error' => "Ты достиг лимита маяков ({$maxBeacons}).\n"
                    . "Освободить место: «📡 Маяки» → «🗑 Снять маяк» — начни с выработанных (⚡ 0), "
                    . "они не принимают телепорты, но место занимают.",
            ];
        }

        // 6) Map cell exists
        $mapRow = $this->mapModel
            ->where('coordinate_x', $coordX)
            ->where('coordinate_y', $coordY)
            ->first();
        if (!$mapRow) {
            return ['ok' => false, 'error' => "Не найдена карта (X={$coordX}, Y={$coordY})."];
        }
        $cellNumber = (int) $mapRow['cell_number'];

        // 7) Camp at point (somebody else's base?)
        if ($this->campCheckService->isCellClaimedByAnyone($cellNumber)) {
            return ['ok' => false, 'error' => "Здесь уже расположена чья-то база!"];
        }

        // 8) Existing beacon at coords
        $existingBeacon = $this->teleportBeaconModel
            ->where('coordinate_x', $coordX)
            ->where('coordinate_y', $coordY)
            ->where('remaining_uses >', 0)
            ->first();

        if ($existingBeacon && (int) $existingBeacon['character_id'] === $characterId) {
            return ['ok' => false, 'error' => "У тебя уже есть маяк на этой точке!"];
        }

        // ALL VALID — return context for caller to install OR capture
        return [
            'ok'              => true,
            'characterId'     => (int) $characterId,
            'mapRow'          => $mapRow,
            'beaconItem'      => $beaconItem,
            'playerLevel'     => $playerLevel,
            'maxBeacons'      => $maxBeacons,
            'existingBeacon'  => $existingBeacon ?: null,
        ];
    }
}
