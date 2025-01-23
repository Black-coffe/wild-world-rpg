<?php

namespace App\Services\World;

use App\Models\MapModel;
use App\Models\ExploredCellsModel;
use App\Models\CharacterModel;
use App\Models\ClaimedCellModel;

/**
 * Пример: мы разделяем генерацию карты/легенды/строки расстояния
 * на 3 разные методы.
 */
class TextMapService
{
    protected MapModel $mapModel;
    protected ExploredCellsModel $exploredCellsModel;
    protected CharacterModel $characterModel;
    protected ClaimedCellModel $claimedCellModel;

    /**
     * biome_id -> эмодзи
     */
    protected array $biomeEmojis = [
        1 => "🌲",
        2 => "⛰️",
        3 => "❄️",
        4 => "🌊",
        5 => "🌴",
        6 => "🌾",
        7 => "🕳️",
        8 => "🌋",
        9 => "🏜️",
        0 => "❓",
    ];

    public function __construct()
    {
        $this->mapModel           = new MapModel();
        $this->exploredCellsModel = new ExploredCellsModel();
        $this->characterModel     = new CharacterModel();
        $this->claimedCellModel   = new ClaimedCellModel();
    }

    /**
     * Возвращает ТОЛЬКО 12x12 символов карты.
     *
     * @param array $characterRow
     * @return string
     */
    public function buildMapOnly(array $characterRow): string
    {
        // 1) Проверяем cell_number
        $cellNumber = $characterRow['cell_number'] ?? 0;
        if (!$cellNumber) {
            return "Нет cell_number у персонажа";
        }

        // Находим координаты
        $mapRow = $this->mapModel->where('cell_number', $cellNumber)->first();
        if (!$mapRow) {
            return "Map не найдена для cell_number={$cellNumber}";
        }

        $pX = (int)$mapRow['coordinate_x'];
        $pY = (int)$mapRow['coordinate_y'];

        // Границы для 12x12
        $offset = 6;
        $width  = 12;
        $height = 12;

        $xMin = $pX - $offset;
        $xMax = $pX + ($offset - 1);
        $yMin = $pY - $offset;
        $yMax = $pY + ($offset - 1);

        // Собираем ID изученных ячеек
        $exploredRows = $this->exploredCellsModel
            ->select('map_cell_id')
            ->where('character_id', $characterRow['id'])
            ->whereIn('map_cell_id', function($builder) use($xMin, $xMax, $yMin, $yMax) {
                $builder->select('cell_number')
                    ->from('map')
                    ->where("coordinate_x >= {$xMin}")
                    ->where("coordinate_x <= {$xMax}")
                    ->where("coordinate_y >= {$yMin}")
                    ->where("coordinate_y <= {$yMax}");
            })
            ->findAll();
        $exploredSet = [];
        foreach ($exploredRows as $er) {
            $exploredSet[$er['map_cell_id']] = true;
        }

        // Достаём инфу о ячейках
        $mapData = $this->mapModel
            ->select('cell_number, biome_id, coordinate_x, coordinate_y')
            ->where('coordinate_x >=', $xMin)
            ->where('coordinate_x <=', $xMax)
            ->where('coordinate_y >=', $yMin)
            ->where('coordinate_y <=', $yMax)
            ->findAll();

        $cells = [];
        foreach ($mapData as $row) {
            $xx = (int)$row['coordinate_x'];
            $yy = (int)$row['coordinate_y'];
            $cells["{$xx}_{$yy}"] = [
                'biome_id'    => (int)$row['biome_id'],
                'cell_number' => (int)$row['cell_number'],
            ];
        }

        // Смотрим, есть ли у персонажа своя база
        $baseX = null;
        $baseY = null;
        $claimedRow = $this->claimedCellModel
            ->where('character_id', $characterRow['id'])
            ->where('status', 'active')
            ->first();
        if ($claimedRow) {
            $baseMapRow = $this->mapModel->find($claimedRow['map_cell_id']);
            if ($baseMapRow) {
                $baseX = (int)$baseMapRow['coordinate_x'];
                $baseY = (int)$baseMapRow['coordinate_y'];
            }
        }

        // Собираем чужие базы
        $otherBases = [];
        $claimedInArea = $this->claimedCellModel
            ->select('claimed_cells.character_id, claimed_cells.map_cell_id, map.coordinate_x, map.coordinate_y')
            ->join('map', 'map.id = claimed_cells.map_cell_id', 'left')
            ->where('claimed_cells.status', 'active')
            ->where('coordinate_x >=', $xMin)
            ->where('coordinate_x <=', $xMax)
            ->where('coordinate_y >=', $yMin)
            ->where('coordinate_y <=', $yMax)
            ->findAll();

        foreach ($claimedInArea as $cRow) {
            if ((int)$cRow['character_id'] !== (int)$characterRow['id']) {
                $xx = (int)$cRow['coordinate_x'];
                $yy = (int)$cRow['coordinate_y'];
                $key = "{$xx}_{$yy}";
                $otherBases[$key] = true;
            }
        }

        // Генерация 12 строк
        $mapText = "";
        for ($localY = 0; $localY < $height; $localY++) {
            $worldY = $yMin + $localY;

            for ($localX = 0; $localX < $width; $localX++) {
                $worldX = $xMin + $localX;

                // Пределы карты
                if ($worldX < 1 || $worldX > 1000 || $worldY < 1 || $worldY > 1000) {
                    $mapText .= "⬜";
                    continue;
                }

                // Если это игрок
                if ($worldX === $pX && $worldY === $pY) {
                    $mapText .= "🙎‍♂️";
                    continue;
                }

                // Если своя база
                if ($baseX !== null && $baseY !== null) {
                    if ($worldX === $baseX && $worldY === $baseY) {
                        $mapText .= "🏕";
                        continue;
                    }
                }

                $cellKey = "{$worldX}_{$worldY}";
                // Чужая база?
                $isForeignBase = isset($otherBases[$cellKey]);

                // Нет данных — чёрный
                if (!isset($cells[$cellKey])) {
                    $mapText .= "⬛️";
                    continue;
                }

                $biomeId = $cells[$cellKey]['biome_id'];
                $cellNum = $cells[$cellKey]['cell_number'];

                // Не изучено?
                if (!isset($exploredSet[$cellNum])) {
                    $mapText .= "⬛️";
                } else {
                    if ($isForeignBase) {
                        $mapText .= "🚫";
                    } else {
                        $emoji = $this->biomeEmojis[$biomeId] ?? $this->biomeEmojis[0];
                        $mapText .= $emoji;
                    }
                }
            }
            $mapText .= "\n";
        }

        return $mapText;
    }

    /**
     * Возвращает легенду (текстом), без карты
     */
    public function getLegend(): string
    {
        return
            "Легенда:\n"
            . "🙎‍♂️ — игрок\n"
            . "🏕 — ваша база\n"
            . "🚫 — чужая база\n"
            . "⬛️ — не изучено\n"
            . "⬜ — за пределами мира\n\n"
            . "1) 🌲 — Лес\n"
            . "2) ⛰️ — Горы\n"
            . "3) ❄️ — Тундра\n"
            . "4) 🌊 — Реки\n"
            . "5) 🌴 — Джунгли\n"
            . "6) 🌾 — Поля\n"
            . "7) 🕳️ — Пещеры\n"
            . "8) 🌋 — Вулкан\n"
            . "9) 🏜️ — Пустыни\n";
    }

    /**
     * Если у персонажа есть база, возвращает строку вида
     * "От 🙎‍♂️ до 🏕 = N ходов."
     */
    public function getDistanceLine(array $characterRow): string
    {
        // 1) Проверяем наличие базы
        $claimedRow = $this->claimedCellModel
            ->where('character_id', $characterRow['id'])
            ->where('status', 'active')
            ->first();
        if (!$claimedRow) {
            return ""; // нет базы
        }

        // 2) Получаем координаты игрока
        $cellNumber = $characterRow['cell_number'] ?? 0;
        if (!$cellNumber) {
            return "";
        }
        $mapRowPlayer = $this->mapModel->where('cell_number', $cellNumber)->first();
        if (!$mapRowPlayer) {
            return "";
        }
        $pX = (int)$mapRowPlayer['coordinate_x'];
        $pY = (int)$mapRowPlayer['coordinate_y'];

        // 3) Координаты базы
        $mapRowBase = $this->mapModel->find($claimedRow['map_cell_id']);
        if (!$mapRowBase) {
            return "";
        }
        $bX = (int)$mapRowBase['coordinate_x'];
        $bY = (int)$mapRowBase['coordinate_y'];

        // 4) Метрика Чебышёва
        $deltaX = abs($pX - $bX);
        $deltaY = abs($pY - $bY);
        $distance = max($deltaX, $deltaY);

        return "От 🙎‍♂️ до 🏕 = {$distance} ходов.\n";
    }
}
