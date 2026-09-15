<?php

namespace App\Services\Coverage;

use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\CharacterModel;
use App\Models\ClaimedCellModel;
use App\Models\MapModel;
use App\Services\GameSettings\GameSettingsService;

/**
 * Class CommunicationTowerCoverageService
 *
 * Сервис, рассчитывающий, "покрывает" ли Вышка связи (CommunicationTower)
 * текущее положение игрока, исходя из расстояния между игроком и базой.
 *
 * story multibase-picker-01: покрытие считается по КАЖДОЙ активной базе персонажа
 * (`claimed_cells.id` ASC) — Вышка базы покрывает только от своей базы
 * (`character_buildings.map_cell_id` = клетка базы). Раньше брались одна
 * `first()`-база без `orderBy` и любая Вышка персонажа — на двух базах результат
 * зависел от порядка строк в БД.
 *
 * Формула: макс. дистанция = уровень Вышки × `communication_tower.coverage_per_level`
 * (GameSettings, дефолт 100 = прежнее зашитое `level * 100`), метрика Чебышёва.
 */
class CommunicationTowerCoverageService
{
    public const SETTING_COVERAGE_PER_LEVEL = 'communication_tower.coverage_per_level';
    public const DEFAULT_COVERAGE_PER_LEVEL = 100;

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

    protected ?GameSettingsService $settings = null;

    public function __construct(?GameSettingsService $settings = null)
    {
        $this->buildingModel          = new BuildingModel();
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->characterModel         = new CharacterModel();
        $this->claimedCellModel       = new ClaimedCellModel();
        $this->mapModel               = new MapModel();
        $this->settings               = $settings;
    }

    /**
     * Покрытие по каждой активной базе персонажа, по `claimed_cells.id` ASC.
     * База без Вышки → `towerLevel=0`, `maxCoverage=0`, `isCovered=false`.
     * Нет карты для клетки игрока или базы → `distance=-1`, `isCovered=false`.
     * `name` — сырое `claimed_cells.camp_name` ('' если не задано); экранировать
     * в тексте через `MarkdownSafe::name($name, 'База')`.
     *
     * @return list<array{base_id:int, cell:int, name:string, x:int, y:int, towerLevel:int, distance:int, maxCoverage:int, isCovered:bool}>
     */
    public function coverageByBase(int $characterId, int $playerCell): array
    {
        $out = [];
        foreach ($this->buildRows($characterId, $playerCell, $this->towerBuildingId()) as $row) {
            $out[] = [
                'base_id'     => $row['base_id'],
                'cell'        => $row['cell'],
                'name'        => $row['name'],
                'x'           => $row['x'],
                'y'           => $row['y'],
                'towerLevel'  => $row['towerLevel'],
                'distance'    => $row['distance'] ?? -1,
                'maxCoverage' => $row['maxCoverage'],
                'isCovered'   => $row['isCovered'],
            ];
        }

        return $out;
    }

    /**
     * Проверяет, распространяется ли сигнал Вышки связи на текущую позицию игрока.
     * Детерминированно: из баз с Вышкой берётся первая (по id) покрывающая игрока,
     * иначе первая (по id) база с Вышкой.
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
        if ($this->claimedCellModel->findAllActiveCells($characterId) === []) {
            return $this->noTower('У игрока нет базы, следовательно нет вышки связи.');
        }

        // 2) Есть ли в таблице buildings вообще CommunicationTower
        $towerBuildingId = $this->towerBuildingId();
        if ($towerBuildingId === null) {
            return $this->noTower('Building "CommunicationTower" не найдено в БД.');
        }

        // CharacterModel отдаёт CharacterEntity (ArrayAccess), не массив.
        $characterRow   = $this->characterModel->find($characterId);
        $characterFound = is_array($characterRow) || $characterRow instanceof \ArrayAccess;
        $cellRaw        = $characterFound ? ($characterRow['cell_number'] ?? null) : null;
        $playerCell     = is_numeric($cellRaw) ? (int) $cellRaw : 0;

        $withTower = array_values(array_filter(
            $this->buildRows($characterId, $playerCell, $towerBuildingId),
            static fn (array $r): bool => $r['towerLevel'] > 0
        ));
        if ($withTower === []) {
            return $this->noTower('У игрока нет здания "Вышка связи".');
        }

        $pick = $withTower[0];
        foreach ($withTower as $row) {
            if ($row['isCovered']) {
                $pick = $row;
                break;
            }
        }

        $towerLevel  = $pick['towerLevel'];
        $maxCoverage = $pick['maxCoverage'];

        if (! $characterFound) {
            return $this->towerError($towerLevel, $maxCoverage, 'Персонаж не найден. Ошибка.');
        }
        if ($pick['error'] === 'player_map') {
            return $this->towerError($towerLevel, $maxCoverage, 'Карта для игрока не найдена.');
        }
        if ($pick['error'] === 'base_map' || $pick['distance'] === null) {
            return $this->towerError($towerLevel, $maxCoverage, 'Карта для базы не найдена. Ошибка данных.');
        }

        $distance = $pick['distance'];
        $covered  = $pick['isCovered'];

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

    /** Радиус покрытия на один уровень Вышки (GameSettings, дефолт — прежние 100). */
    protected function coveragePerLevel(): int
    {
        $this->settings ??= new GameSettingsService();
        $raw = $this->settings->get(self::SETTING_COVERAGE_PER_LEVEL, self::DEFAULT_COVERAGE_PER_LEVEL);

        return is_numeric($raw) ? (int) $raw : self::DEFAULT_COVERAGE_PER_LEVEL;
    }

    private function towerBuildingId(): ?int
    {
        $row = $this->buildingModel
            ->where('name_en', 'CommunicationTower')
            ->orderBy('id', 'ASC')
            ->first();

        return is_array($row) && is_numeric($row['id'] ?? null) ? (int) $row['id'] : null;
    }

    /**
     * @return list<array{base_id:int, cell:int, name:string, x:int, y:int, towerLevel:int, distance:int|null, maxCoverage:int, isCovered:bool, error:string|null}>
     */
    private function buildRows(int $characterId, int $playerCell, ?int $towerBuildingId): array
    {
        $bases = $this->claimedCellModel->findAllActiveCells($characterId);
        if ($bases === []) {
            return [];
        }

        $perLevel     = $this->coveragePerLevel();
        $playerCoords = $this->coordsOfCell($playerCell);

        $rows = [];
        foreach ($bases as $base) {
            $cell     = is_numeric($base['map_cell_id'] ?? null) ? (int) $base['map_cell_id'] : 0;
            $nameRaw  = $base['camp_name'] ?? null;
            $baseMap  = $this->mapModel->find($cell);
            $baseX    = is_array($baseMap) && is_numeric($baseMap['coordinate_x'] ?? null) ? (int) $baseMap['coordinate_x'] : 0;
            $baseY    = is_array($baseMap) && is_numeric($baseMap['coordinate_y'] ?? null) ? (int) $baseMap['coordinate_y'] : 0;

            $towerLevel = $towerBuildingId === null ? 0 : $this->towerLevelAt($characterId, $towerBuildingId, $cell);
            $maxCoverage = $towerLevel * $perLevel;

            $error    = null;
            $distance = null;
            if ($playerCoords === null) {
                $error = 'player_map';
            } elseif (! is_array($baseMap)) {
                $error = 'base_map';
            } else {
                $distance = max(abs($playerCoords[0] - $baseX), abs($playerCoords[1] - $baseY));
            }

            $rows[] = [
                'base_id'     => is_numeric($base['id'] ?? null) ? (int) $base['id'] : 0,
                'cell'        => $cell,
                'name'        => is_string($nameRaw) ? $nameRaw : '',
                'x'           => $baseX,
                'y'           => $baseY,
                'towerLevel'  => $towerLevel,
                'distance'    => $distance,
                'maxCoverage' => $maxCoverage,
                'isCovered'   => $towerLevel > 0 && $distance !== null && $distance <= $maxCoverage,
                'error'       => $error,
            ];
        }

        return $rows;
    }

    /** Уровень Вышки на клетке базы; 0 — Вышки нет. Строка с level<1 считается уровнем 1 (как раньше). */
    private function towerLevelAt(int $characterId, int $towerBuildingId, int $cell): int
    {
        $row = (new CharacterBuildingModel())
            ->where('character_id', $characterId)
            ->where('building_id', $towerBuildingId)
            ->where('map_cell_id', $cell)
            ->orderBy('level', 'DESC')
            ->orderBy('id', 'ASC')
            ->first();
        if (! is_array($row)) {
            return 0;
        }
        $level = is_numeric($row['level'] ?? null) ? (int) $row['level'] : 1;

        return max(1, $level);
    }

    /** @return array{0:int, 1:int}|null */
    private function coordsOfCell(int $cell): ?array
    {
        $row = (new MapModel())->where('cell_number', $cell)->first();
        if (! is_array($row)) {
            return null;
        }

        return [
            is_numeric($row['coordinate_x'] ?? null) ? (int) $row['coordinate_x'] : 0,
            is_numeric($row['coordinate_y'] ?? null) ? (int) $row['coordinate_y'] : 0,
        ];
    }

    /** @return array{hasTower: false, towerLevel: null, distanceToBase: null, maxCoverage: null, isCovered: false, message: string} */
    private function noTower(string $message): array
    {
        return [
            'hasTower'      => false,
            'towerLevel'    => null,
            'distanceToBase'=> null,
            'maxCoverage'   => null,
            'isCovered'     => false,
            'message'       => $message,
        ];
    }

    /** @return array{hasTower: true, towerLevel: int, distanceToBase: null, maxCoverage: int, isCovered: false, message: string} */
    private function towerError(int $towerLevel, int $maxCoverage, string $message): array
    {
        return [
            'hasTower'      => true,
            'towerLevel'    => $towerLevel,
            'distanceToBase'=> null,
            'maxCoverage'   => $maxCoverage,
            'isCovered'     => false,
            'message'       => $message,
        ];
    }
}
