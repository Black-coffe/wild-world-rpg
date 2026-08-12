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
 * 3. Проверяет наличие АКТИВНОЙ базы игрока в `claimed_cells` (status='active').
 *    Если активных нет, базы нет.
 * 4. Если база есть, спрашивает: стоит ли игрок на КАКОЙ-НИБУДЬ из своих активных баз
 *    (`ClaimedCellModel::findActiveCell` по текущему `characters.cell_number`).
 *    Мульти-база жива (ADR-095/122), поэтому «первая строка» тут не годится.
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

        // 3) Есть ли у игрока хоть одна АКТИВНАЯ база.
        // 🔴 Считаем именно активные: строка со status='abandoned' — это уже не база,
        // а старый след, и раньше она сюда попадала (фильтра по статусу не было вовсе).
        $hasBase = $this->claimedCellModel->countActiveBases($characterId) > 0;

        if (! $hasBase) {
            return [
                'hasBase'  => false,
                'isOnBase' => false,
                'x'        => $x,
                'y'        => $y,
            ];
        }

        // 4) На базе ли игрок СЕЙЧАС — матч по текущей клетке, а не по первой строке.
        // 🔴 До 2026-08-12 бралась первая запись `claimed_cells` игрока и её `map_cell_id`
        // сравнивался с текущей клеткой: владелец двух лагерей, стоящий на ВТОРОМ, получал
        // isOnBase=false. Тот же класс ошибки чинился в
        // {@see \App\Services\Player\PlayerStateService::isCharacterOnBase()} — резолв обязан
        // спрашивать «стою ли я на КАКОЙ-НИБУДЬ своей базе», а не «стою ли я на первой».
        // Канон — {@see ClaimedCellModel::findActiveCell()} (ADR-095 Фаза 1b).
        $cellForMatch = is_numeric($cellNumber) ? (int) $cellNumber : 0;
        $isOnBase     = $this->claimedCellModel->findActiveCell($characterId, $cellForMatch) !== null;

        return [
            'hasBase'  => true,
            'isOnBase' => $isOnBase,
            'x'        => $x,
            'y'        => $y,
        ];
    }
}
