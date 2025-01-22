<?php

namespace App\Services\Bases;

use App\Models\MapModel;
use App\Models\ClaimedCellModel;

/**
 * Сервис для проверки, занята ли конкретная ячейка карты
 * (имеется ли там активная база у кого-то).
 */
class CampCheckService
{
    protected MapModel $mapModel;
    protected ClaimedCellModel $claimedCellModel;

    public function __construct()
    {
        $this->mapModel         = new MapModel();
        $this->claimedCellModel = new ClaimedCellModel();
    }

    /**
     * Проверяет, нет ли в ячейке с данным cell_number чьего-то "активного" лагеря (базы).
     *
     * @param int $cellNumber — номер ячейки из поля map.cell_number
     * @return bool true, если ячейка занята лагерем любого игрока
     */
    public function isCellClaimedByAnyone(int $cellNumber): bool
    {
        // 1. Ищем запись в таблице map по cell_number
        //    (ведь claimed_cells ссылается на map.id).
        $mapRow = $this->mapModel
            ->where('cell_number', $cellNumber)
            ->first();

        if (!$mapRow) {
            // Если ячейка в таблице map не найдена,
            // технически считаем, что её "занять" нельзя,
            // но с точки зрения "уже занята" — возвращаем false
            return false;
        }

        $mapId = $mapRow['id'];

        // 2. Ищем в claimed_cells "active"-запись
        $claimed = $this->claimedCellModel
            ->where('map_cell_id', $mapId)
            ->where('status', 'active')
            ->first();

        return ($claimed !== null);
    }
}
