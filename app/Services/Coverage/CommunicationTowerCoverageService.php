<?php

namespace App\Services\Coverage;

use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\CharacterModel;
use App\Models\ClaimedCellModel;
use App\Models\MapModel;

/**
 * Class CommunicationTowerCoverageService
 *
 * Сервис, рассчитывающий, "покрывает" ли Вышка связи (CommunicationTower)
 * текущее положение игрока, исходя из расстояния между игроком и базой.
 *
 * 1) Ищет у игрока базу (claimed_cells со статусом 'active').
 * 2) Проверяет, построено ли здание "CommunicationTower"
 *    (character_buildings, building_id => buildingRow['name_en']=='CommunicationTower').
 * 3) Если не найдено — "покрытия" нет.
 * 4) Если найдено — берём уровень (level) из character_buildings.
 *    Формула: макс. дистанция = уровень * 100 ходов (метрика Чебышёва).
 * 5) Сравниваем реальную дистанцию игрока от базы и макс. дистанцию.
 * 6) Возвращаем информацию: покрывает (bool), расстояние (int), лимит покрытия (int).
 */
class CommunicationTowerCoverageService
{
    /** @var BuildingModel */
    protected $buildingModel;

    /** @var CharacterBuildingModel */
    protected $characterBuildingModel;

    /** @var CharacterModel */
    protected $characterModel;

    /** @var ClaimedCellModel */
    protected $claimedCellModel;

    /** @var MapModel */
    protected $mapModel;

    public function __construct()
    {
        $this->buildingModel          = new BuildingModel();
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->characterModel         = new CharacterModel();
        $this->claimedCellModel       = new ClaimedCellModel();
        $this->mapModel               = new MapModel();
    }

    /**
     * Проверяет, распространяется ли сигнал Вышки связи на текущую позицию игрока.
     *
     * @param int $characterId
     * @return array [
     *   'hasTower' => bool,            // есть ли вообще здание CommunicationTower
     *   'towerLevel' => int|null,      // уровень вышки, если есть
     *   'distanceToBase' => int|null,  // расстояние от игрока до базы (ходов)
     *   'maxCoverage' => int|null,     // максимально покрываемая дистанция
     *   'isCovered' => bool,           // false, если нет вышки или дистанция > maxCoverage
     *   'message' => string,           // короткое пояснение
     * ]
     */
    public function checkCoverage(int $characterId): array
    {
        // 1) Проверяем, есть ли активная база у игрока
        $claimedCellRow = $this->claimedCellModel
            ->where('character_id', $characterId)
            ->where('status', 'active')
            ->first();
        if (!$claimedCellRow) {
            // Базы нет, значит и вышка не имеет смысла
            return [
                'hasTower'      => false,
                'towerLevel'    => null,
                'distanceToBase'=> null,
                'maxCoverage'   => null,
                'isCovered'     => false,
                'message'       => 'У игрока нет базы, следовательно нет вышки связи.',
            ];
        }

        // 2) Ищем в character_buildings запись со зданием CommunicationTower
        //    Сначала найдём сам buildingRow (из таблицы buildings)
        $towerBuildingRow = $this->buildingModel
            ->where('name_en', 'CommunicationTower')
            ->first();
        if (!$towerBuildingRow) {
            // Если в таблице buildings вообще нет CommunicationTower
            return [
                'hasTower'      => false,
                'towerLevel'    => null,
                'distanceToBase'=> null,
                'maxCoverage'   => null,
                'isCovered'     => false,
                'message'       => 'Building "CommunicationTower" не найдено в БД.',
            ];
        }

        $towerBuildingId = $towerBuildingRow['id'];

        // Ищем у игрока постройку именно с таким building_id
        $characterTower = $this->characterBuildingModel
            ->where('character_id', $characterId)
            ->where('building_id', $towerBuildingId)
            ->first();
        if (!$characterTower) {
            // Значит вышка не построена
            return [
                'hasTower'      => false,
                'towerLevel'    => null,
                'distanceToBase'=> null,
                'maxCoverage'   => null,
                'isCovered'     => false,
                'message'       => 'У игрока нет здания "Вышка связи".',
            ];
        }

        // 3) Уровень вышки
        $towerLevel = (int)$characterTower['level'];
        if ($towerLevel < 1) {
            // Теоретически бывает, если level=0, но у нас min=1
            $towerLevel = 1;
        }

        // 4) Вычисляем расстояние (метрика Чебышёва) между
        //    текущей позицией персонажа и базой (map_cell_id из claimed_cells).
        //    - Координаты игрока
        $characterRow = $this->characterModel->find($characterId);
        if (!$characterRow) {
            return [
                'hasTower'      => true,
                'towerLevel'    => $towerLevel,
                'distanceToBase'=> null,
                'maxCoverage'   => $towerLevel * 100,
                'isCovered'     => false,
                'message'       => 'Персонаж не найден. Ошибка.',
            ];
        }

        $cellNumPlayer = $characterRow['cell_number'] ?? 0;
        $cellNumBase   = $claimedCellRow['map_cell_id'];

        // Ищем mapRow для игрока
        $mapRowPlayer = $this->mapModel->where('cell_number', $cellNumPlayer)->first();
        if (!$mapRowPlayer) {
            return [
                'hasTower'      => true,
                'towerLevel'    => $towerLevel,
                'distanceToBase'=> null,
                'maxCoverage'   => $towerLevel * 100,
                'isCovered'     => false,
                'message'       => 'Карта для игрока не найдена.',
            ];
        }
        // Ищем mapRow для базы
        $mapRowBase = $this->mapModel->find($cellNumBase);
        if (!$mapRowBase) {
            return [
                'hasTower'      => true,
                'towerLevel'    => $towerLevel,
                'distanceToBase'=> null,
                'maxCoverage'   => $towerLevel * 100,
                'isCovered'     => false,
                'message'       => 'Карта для базы не найдена. Ошибка данных.',
            ];
        }

        // Координаты
        $pX = (int)$mapRowPlayer['coordinate_x'];
        $pY = (int)$mapRowPlayer['coordinate_y'];
        $bX = (int)$mapRowBase['coordinate_x'];
        $bY = (int)$mapRowBase['coordinate_y'];

        // Расстояние (метрика Чебышёва)
        $distX = abs($pX - $bX);
        $distY = abs($pY - $bY);
        $distance = max($distX, $distY);

        // 5) Максимальное покрытие
        $maxCoverage = $towerLevel * 100;  // пример: 1 уровень => 100 ходов

        // 6) Проверяем, попадает ли distance в зону
        $covered = ($distance <= $maxCoverage);

        $msg = $covered
            ? "Вышка связи покрывает! Расстояние {$distance} ходов <= лимит {$maxCoverage}."
            : "Расстояние {$distance} ходов > лимита {$maxCoverage}. Вы вне зоны связи.";

        return [
            'hasTower'      => true,
            'towerLevel'    => $towerLevel,
            'distanceToBase'=> $distance,
            'maxCoverage'   => $maxCoverage,
            'isCovered'     => $covered,
            'message'       => $msg,
        ];
    }
}
