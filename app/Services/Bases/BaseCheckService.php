<?php

namespace App\Services\Bases;

use App\Models\CharacterModel;
use App\Models\MapModel;
use App\Models\ClaimedCellModel;

/**
 * Сервис BaseCheckService
 *
 * 1. По ID персонажа берёт данные из таблицы `characters` (важны поля: cell_number, biome_id).
 * 2. По этим данным ищет строку в таблице `map` (сравниваем `map.cell_number` и `characters.cell_number`),
 *    чтобы узнать coordinate_x, coordinate_y.
 * 3. Проверяет наличие базы игрока в `claimed_cells` (where character_id=?).
 *    Если ничего не нашли, база отсутствует.
 * 4. Если база есть, сравниваем:
 *    - `claimed_cells.map_cell_id` и `characters.cell_number`.
 *    Если равны — значит игрок сейчас физически на своей базе.
 *
 * Возвращает информацию (bool hasBase, bool isOnBase).
 */
class BaseCheckService
{
    protected CharacterModel $characterModel;
    protected MapModel $mapModel;
    protected ClaimedCellModel $claimedCellModel;

    public function __construct()
    {
        $this->characterModel   = new CharacterModel();
        $this->mapModel         = new MapModel();
        $this->claimedCellModel = new ClaimedCellModel();
    }

    /**
     * Проверяет, есть ли у игрока база, и находится ли он на своей базе.
     *
     * @param int $characterId
     * @return array [
     *     'hasBase'  => bool, // есть ли база
     *     'isOnBase' => bool, // находится ли игрок на базе прямо сейчас
     *     'x'        => mixed, // coordinate_x из таблицы map (или null)
     *     'y'        => mixed, // coordinate_y из таблицы map (или null)
     * ]
     */
    public function checkBaseStatus(int $characterId): array
    {
        // 1) Ищем персонажа в таблице `characters`
        $character = $this->characterModel->find($characterId);
        if (!$character) {
            // Если персонаж не найден, возвращаем статус без базы
            return [
                'hasBase'  => false,
                'isOnBase' => false,
                'x'        => null,
                'y'        => null,
            ];
        }

        // Важные поля
        $cellNumber = $character['cell_number'] ?? 0;
        // biome_id можно тоже использовать при необходимости (требовалось "два поля", но здесь biome_id не задействован)
        $biomeId    = $character['biome_id'] ?? 0;

        // 2) Ищем координаты в таблице `map`, где `map.cell_number = character.cell_number`
        $x = null;
        $y = null;
        if ($cellNumber) {
            $mapRow = $this->mapModel
                ->where('cell_number', $cellNumber)
                ->first();
            if ($mapRow) {
                $x = $mapRow['coordinate_x'];
                $y = $mapRow['coordinate_y'];
            }
        }

        // 3) Проверяем, есть ли запись в `claimed_cells` по character_id
        $claimedCell = $this->claimedCellModel
            ->where('character_id', $characterId)
            ->first();

        if (!$claimedCell) {
            // Базы нет
            return [
                'hasBase'  => false,
                'isOnBase' => false,
                'x'        => $x,
                'y'        => $y,
            ];
        }

        // 4) Сравниваем claimed_cells.map_cell_id и character.cell_number
        //    Если совпадают, значит игрок на своей базе
        // (причём тут важно, что map_cell_id в claimed_cells указывает на map.id,
        //  а мы сравниваем с cell_number. Убедитесь, что ваша схема хранит именно cell_number в map_cell_id
        //  или нужно дополнительно проверять map->id.)
        $isOnBase = ($claimedCell['map_cell_id'] == $cellNumber);

        return [
            'hasBase'  => true,
            'isOnBase' => $isOnBase,
            'x'        => $x,
            'y'        => $y,
        ];
    }
}
