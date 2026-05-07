<?php

namespace App\Services\Player\TeleportBeacon;

use App\Models\CraftedItemsLogModel;
use App\Models\TeleportBeaconModel;

/**
 * v0.51.54 (TeleportBeaconSetAction decomp Step 3) — extract DB write +
 * inventory subtract logic з `installBeacon`.
 *
 * Public API:
 *   install(int charId, array mapRow, int playerLevel, array beaconItem): array{
 *     updatedCount: int,    // total beacons after install
 *     beaconLeft: int       // remaining у inventory after subtract
 *   }
 *
 * Side effects:
 *  1. INSERT teleport_beacons row (ownership_type='author', remaining_uses=100,
 *     tax_cost=180 — hardcoded baseline)
 *  2. subtractItem('TeleportBeaconBasic', 1) — inventory write через
 *     CraftedItemsLogModel
 *  3. Read total beacon count + beacon items_left для downstream display
 */
class BeaconInstaller
{
    /**
     * Hardcoded constants from original installBeacon — could move у GameBalance
     * у future C/F6 wire-in (TODO).
     */
    private const REMAINING_USES = 100;
    private const TAX_COST       = 180;
    private const ITEM_NAME_ENG  = 'TeleportBeaconBasic';

    private TeleportBeaconModel $teleportBeaconModel;
    private CraftedItemsLogModel $craftedItemsLogModel;

    public function __construct(
        ?TeleportBeaconModel $teleportBeaconModel = null,
        ?CraftedItemsLogModel $craftedItemsLogModel = null
    ) {
        $this->teleportBeaconModel  = $teleportBeaconModel  ?? new TeleportBeaconModel();
        $this->craftedItemsLogModel = $craftedItemsLogModel ?? new CraftedItemsLogModel();
    }

    /**
     * Установка маяка: INSERT row + inventory subtract + counters return.
     *
     * @param array<string,mixed> $mapRow   map table row (потрібен `id`,
     *                                       `coordinate_x`, `coordinate_y`)
     * @param array<string,mixed> $beaconItem crafted_items row для inventory
     *                                        lookup post-subtract
     * @return array{updatedCount: int, beaconLeft: int}
     */
    public function install(
        int $charId,
        array $mapRow,
        int $playerLevel,
        array $beaconItem
    ): array {
        // 1) INSERT teleport_beacons row
        $this->teleportBeaconModel->insert([
            'character_id'             => $charId,
            'faction_id'               => null,
            'player_level_at_creation' => $playerLevel,
            'map_cell_id'              => (int) $mapRow['id'],
            'coordinate_x'             => $mapRow['coordinate_x'],
            'coordinate_y'             => $mapRow['coordinate_y'],
            'tax_cost'                 => self::TAX_COST,
            'remaining_uses'           => self::REMAINING_USES,
            'last_teleport_at'         => null,
            'ownership_type'           => 'author',
            'settings_json'            => null,
        ]);

        // 2) Total beacon count post-INSERT
        $updatedCount = $this->teleportBeaconModel
            ->where('character_id', $charId)
            ->countAllResults();

        // 3) Inventory subtract
        $this->craftedItemsLogModel->subtractItem($charId, self::ITEM_NAME_ENG, 1);

        // 4) Read remaining beacon items для display
        $beaconLogUpdated = $this->craftedItemsLogModel
            ->where('character_id', $charId)
            ->where('crafted_item_id', $beaconItem['id'])
            ->first();
        $beaconLeft = $beaconLogUpdated ? (int) $beaconLogUpdated['quantity'] : 0;

        return [
            'updatedCount' => (int) $updatedCount,
            'beaconLeft'   => $beaconLeft,
        ];
    }
}
