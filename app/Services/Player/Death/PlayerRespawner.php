<?php

namespace App\Services\Player\Death;

use App\Models\CharacterModel;
use App\Models\ClaimedCellModel;
use App\Models\MapModel;

/**
 * F2.8b — выбор клетки respawn'а после смерти и проверка наличия базы.
 *
 * Извлечено из DeathService::respawnPlayer + checkIfPlayerHasActiveBase
 * (legacy v0.5.0). Логика 1:1:
 *   - если есть claimed_cells.status='active' → респавн на map_cell_id базы
 *   - иначе случайная ячейка из map где Y in [900..999], biome_id ∈ [1,2,3,6]
 *   - fallback cell_number = 1 если совсем ничего не нашли
 *
 * Models инжектируются для testability.
 */
class PlayerRespawner
{
    private CharacterModel   $characterModel;
    private ClaimedCellModel $claimedCellModel;
    private MapModel         $mapModel;

    public function __construct(
        ?CharacterModel $characterModel = null,
        ?ClaimedCellModel $claimedCellModel = null,
        ?MapModel $mapModel = null
    ) {
        $this->characterModel   = $characterModel   ?? new CharacterModel();
        $this->claimedCellModel = $claimedCellModel ?? new ClaimedCellModel();
        $this->mapModel         = $mapModel         ?? new MapModel();
    }

    /**
     * Возвращает true если у персонажа есть активная база.
     */
    public function hasActiveBase(int $characterId): bool
    {
        $row = $this->claimedCellModel
            ->where('character_id', $characterId)
            ->where('status', 'active')
            ->first();
        return !empty($row);
    }

    /**
     * Перенести персонажа на respawn-клетку. Возвращает новый cell_number.
     */
    public function respawn(int $characterId): int
    {
        $claimed = $this->claimedCellModel
            ->where('character_id', $characterId)
            ->where('status', 'active')
            ->first();

        if ($claimed) {
            $respawnCell = (int) $claimed['map_cell_id'];
        } else {
            $cells = $this->mapModel
                ->where('coordinate_y >=', 900)
                ->where('coordinate_y <=', 999)
                ->whereIn('biome_id', [1, 2, 3, 6])
                ->findAll();
            if (!empty($cells)) {
                $randomCell  = $cells[array_rand($cells)];
                $respawnCell = (int) $randomCell['cell_number'];
            } else {
                $respawnCell = 1;
            }
        }

        $this->characterModel->update($characterId, ['cell_number' => $respawnCell]);
        return $respawnCell;
    }
}
