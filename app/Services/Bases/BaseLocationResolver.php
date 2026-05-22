<?php

declare(strict_types=1);

namespace App\Services\Bases;

use App\Models\BiomeModel;
use App\Models\ClaimedCellModel;
use App\Models\MapModel;

/**
 * v0.51.74 (BaseInfoAction decomp Step 2) — extract claimed_cell + map_row
 * + biome_row lookups у dedicated resolver.
 *
 * Public API:
 *   findClaimedCell(charId): ?array       — claimed_cells row для character
 *   findMapRow(cellNumber): ?array        — map row by cell_number
 *   findBiomeRow(biomeId): ?array         — biome row by id
 */
final class BaseLocationResolver
{
    private ClaimedCellModel $claimedCellModel;
    private MapModel $mapModel;
    private BiomeModel $biomeModel;

    public function __construct(
        ?ClaimedCellModel $claimedCellModel = null,
        ?MapModel $mapModel = null,
        ?BiomeModel $biomeModel = null,
    ) {
        $this->claimedCellModel = $claimedCellModel ?? new ClaimedCellModel();
        $this->mapModel         = $mapModel         ?? new MapModel();
        $this->biomeModel       = $biomeModel       ?? new BiomeModel();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findClaimedCell(int $characterId): ?array
    {
        $row = $this->claimedCellModel->where('character_id', $characterId)->first();
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findMapRow(int $cellNumber): ?array
    {
        $row = $this->mapModel->where('cell_number', $cellNumber)->first();
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBiomeRow(int $biomeId): ?array
    {
        $row = $this->biomeModel->find($biomeId);
        if (is_array($row)) {
            return $row;
        }
        // Bugfix: BiomeModel::$returnType = BiomeEntity (object) → прежний `is_array`
        // всегда давал null → «Биом: ???» в инфо базы (showBaseBuildings/noBaseInfo/
        // campCreation). Приводим Entity к array, чтобы name/description/danger резолвились.
        if ($row instanceof \CodeIgniter\Entity\Entity) {
            return $row->toRawArray();
        }
        return null;
    }
}
