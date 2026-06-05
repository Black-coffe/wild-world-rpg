<?php

namespace App\Services\World;

use App\Models\MapModel;
use App\Models\ExploredCellsModel;
use App\Models\CharacterModel;
use App\Models\ClaimedCellModel;
use App\Models\NpcSpawnModel;

/**
 * Сервис для отрисовки 12×12 карты вокруг игрока (или другого персонажа).
 * Включает:
 *  - биомы (emoji)
 *  - положение игрока
 *  - свою и чужую базу
 *  - NPC (рейдеры и т.п.) в радиусе отображения
 *  - легенду и строку расстояния до базы
 */
class TextMapService
{
    protected MapModel $mapModel;
    protected ExploredCellsModel $exploredCellsModel;
    protected CharacterModel $characterModel;
    protected ClaimedCellModel $claimedCellModel;
    protected NpcSpawnModel $npcSpawnModel;

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
        $this->npcSpawnModel      = new NpcSpawnModel();
    }

    /**
     * Генерация 12×12 карты (эмоджи) вокруг персонажа.
     *
     * @param array|\App\Entities\CharacterEntity $characterRow Информация о персонаже (из CharacterModel)
     * @return string Текстовая карта
     */
    public function buildMapOnly(array|\App\Entities\CharacterEntity $characterRow): string
    {
        // 1) Проверяем cell_number
        $cellNumber = $characterRow['cell_number'] ?? 0;
        if (!$cellNumber) {
            return "Нет cell_number у персонажа";
        }

        // Находим координаты игрока
        $mapRow = $this->mapModel->where('cell_number', $cellNumber)->first();
        if (!$mapRow) {
            return "Map не найдена для cell_number={$cellNumber}";
        }

        $pX = (int)$mapRow['coordinate_x'];
        $pY = (int)$mapRow['coordinate_y'];

        // Границы отображаемого участка (12×12)
        $offset = 6;
        $width  = 12;
        $height = 12;

        $xMin = $pX - $offset;
        $xMax = $pX + ($offset - 1);
        $yMin = $pY - $offset;
        $yMax = $pY + ($offset - 1);

        // Собираем ячейки, которые персонаж «изучил»
        $exploredRows = $this->exploredCellsModel
            ->select('map_cell_id')
            ->where('character_id', $characterRow['id'])
            ->whereIn('map_cell_id', function($builder) use ($xMin, $xMax, $yMin, $yMax) {
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

        // Достаём данные о ячейках (биомы, и т.д.) в этом диапазоне
        $mapData = $this->mapModel
            ->select('cell_number, biome_id, coordinate_x, coordinate_y')
            ->where('coordinate_x >=', $xMin)
            ->where('coordinate_x <=', $xMax)
            ->where('coordinate_y >=', $yMin)
            ->where('coordinate_y <=', $yMax)
            ->findAll();

        $cells = [];
        foreach ($mapData as $row) {
            $xx = (int) $row['coordinate_x'];
            $yy = (int) $row['coordinate_y'];
            $cells["{$xx}_{$yy}"] = [
                'biome_id'    => (int) $row['biome_id'],
                'cell_number' => (int) $row['cell_number'],
            ];
        }

        // Проверяем, есть ли у персонажа своя база
        $baseX = null;
        $baseY = null;
        $claimedRow = $this->claimedCellModel
            ->where('character_id', $characterRow['id'])
            ->where('status', 'active')
            ->first();
        if ($claimedRow) {
            $baseMapRow = $this->mapModel->find($claimedRow['map_cell_id']);
            if ($baseMapRow) {
                $baseX = (int) $baseMapRow['coordinate_x'];
                $baseY = (int) $baseMapRow['coordinate_y'];
            }
        }

        // Смотрим чужие базы в этом регионе
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
                $xx = (int) $cRow['coordinate_x'];
                $yy = (int) $cRow['coordinate_y'];
                $key = "{$xx}_{$yy}";
                $otherBases[$key] = true;
            }
        }

        // ADR-101 — активные поселения в видимой области (маркер-ландмарк для discoverability,
        // gated killswitch settlements.enabled).
        $settlementCells = [];
        $settleZone      = new \App\Services\Settlement\SettlementZoneService();
        if ($settleZone->layerEnabled()) {
            foreach ((new \App\Models\SettlementModel())->allActive() as $st) {
                $sx = is_numeric($st['coordinate_x'] ?? null) ? (int) $st['coordinate_x'] : null;
                $sy = is_numeric($st['coordinate_y'] ?? null) ? (int) $st['coordinate_y'] : null;
                if ($sx === null || $sy === null) {
                    continue;
                }
                if ($sx >= $xMin && $sx <= $xMax && $sy >= $yMin && $sy <= $yMax) {
                    $settlementCells["{$sx}_{$sy}"] = is_string($st['icon'] ?? null) && $st['icon'] !== '' ? $st['icon'] : '🏚';
                }
            }
        }

        // Получаем NPC вокруг игрока (в 12×12)
        $npcsInArea = $this->getNpcsInArea($pX, $pY);

        // Генерация строк карты
        $mapText = "";
        for ($localY = 0; $localY < $height; $localY++) {
            $worldY = $yMin + $localY;

            for ($localX = 0; $localX < $width; $localX++) {
                $worldX = $xMin + $localX;

                // Если вышли за «границы» глобальной карты
                if ($worldX < 0 || $worldX > 999 || $worldY < 0 || $worldY > 999) {
                    // Условно ставим «⬜» (за пределами)
                    $mapText .= "⬜";
                    continue;
                }

                // Если это точка, где стоит игрок
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

                // Собираем ключ
                $cellKey = "{$worldX}_{$worldY}";

                // ADR-101 — поселение-ландмарк приоритетнее иконки жителей-NPC.
                if (isset($settlementCells[$cellKey])) {
                    $mapText .= $settlementCells[$cellKey];
                    continue;
                }

                // Если есть живой NPC
                if (isset($npcsInArea[$cellKey])) {
                    // Покажем иконку ниндзя (или любую другую)
                    $mapText .= "🥷";
                    continue;
                }

                // Чужая база?
                $isForeignBase = isset($otherBases[$cellKey]);

                // Если в cells нет информации — значит не изучено
                if (!isset($cells[$cellKey])) {
                    // Неизвестная территория, рисуем чёрный квадрат
                    $mapText .= "⬛️";
                    continue;
                }

                // Определяем биом
                $biomeId = $cells[$cellKey]['biome_id'];
                $cellNum = $cells[$cellKey]['cell_number'];

                // Если ячейка не изучена
                if (!isset($exploredSet[$cellNum])) {
                    $mapText .= "⬛️";
                } else {
                    // Ячейка изучена
                    if ($isForeignBase) {
                        $mapText .= "🚫";
                    } else {
                        // Ставим эмоджи по biome_id
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
     * Выводит легенду без карты.
     */
    public function getLegend(): string
    {
        return
            "Легенда:\n"
            . "🙎‍♂️ — игрок\n"
            . "🏕 — ваша база\n"
            . "🚫 — чужая база\n"
            . "🏚 — поселение\n"
            . "🥷 — NPC\n"
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
     * Возвращает строку о расстоянии до базы, вида "От 🙎‍♂️ до 🏕 = N ходов."
     * Если базы нет — вернётся пустая строка.
     */
    public function getDistanceLine(array|\App\Entities\CharacterEntity $characterRow): string
    {
        // 1) Проверяем наличие базы
        $claimedRow = $this->claimedCellModel
            ->where('character_id', $characterRow['id'])
            ->where('status', 'active')
            ->first();
        if (!$claimedRow) {
            // Нет базы
            return "";
        }

        // 2) Координаты игрока
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

        // 4) Используем метрику Чебышёва (поскольку можно двигаться по диагонали)
        $deltaX = abs($pX - $bX);
        $deltaY = abs($pY - $bY);
        $distance = max($deltaX, $deltaY);

        // Идея #13 (Yupirex, 23.01.2025): emoji-стрелка направления к базе.
        $arrow = $this->compassArrow($pX, $pY, $bX, $bY);

        return "От 🙎‍♂️ до 🏕 = {$distance} ходов {$arrow}\n";
    }

    /**
     * 8-octant compass: возвращает emoji-стрелку от игрока к базе.
     * y растёт на юг (north = меньший y).
     */
    private function compassArrow(int $pX, int $pY, int $bX, int $bY): string
    {
        if ($pX === $bX && $pY === $bY) {
            return '🎯';
        }
        $dx = $bX - $pX;
        $dy = $bY - $pY;
        if ($dx === 0) {
            return $dy < 0 ? '⬆️' : '⬇️';
        }
        if ($dy === 0) {
            return $dx < 0 ? '⬅️' : '➡️';
        }
        if (abs($dx) > 2 * abs($dy)) {
            return $dx < 0 ? '⬅️' : '➡️';
        }
        if (abs($dy) > 2 * abs($dx)) {
            return $dy < 0 ? '⬆️' : '⬇️';
        }
        if ($dy < 0) {
            return $dx < 0 ? '↖️' : '↗️';
        }
        return $dx < 0 ? '↙️' : '↘️';
    }

    /**
     * Встроенная логика, ранее была в NpcLocatorService:
     * находим активных (alive) NPC только в 8 соседних клетках
     * вокруг координат (pX, pY).
     */
    protected function getNpcsInArea(int $pX, int $pY): array
    {
        // 1. Границы: pX-1..pX+1, pY-1..pY+1
        $xMin = $pX - 1;
        $xMax = $pX + 1;
        $yMin = $pY - 1;
        $yMax = $pY + 1;

        // 2. Выбираем только живых NPC (status='alive') в этих координатах
        $spawnRows = $this->npcSpawnModel
            ->where('status', 'alive')
            ->where('coordinate_x >=', $xMin)
            ->where('coordinate_x <=', $xMax)
            ->where('coordinate_y >=', $yMin)
            ->where('coordinate_y <=', $yMax)
            ->findAll();

        // 3. Исключаем NPC, если вдруг он стоит на точке самого игрока (pX, pY),
        //    чтобы мы увидели именно 8 ячеек вокруг.
        $filtered = [];
        foreach ($spawnRows as $npcRow) {
            if ($npcRow['coordinate_x'] == $pX && $npcRow['coordinate_y'] == $pY) {
                // Пропускаем NPC, оказавшихся в точке игрока
                continue;
            }
            $filtered[] = $npcRow;
        }

        // 4. Превратим в ассоциативный массив "x_y" => данные о NPC
        $result = [];
        foreach ($filtered as $row) {
            $key = "{$row['coordinate_x']}_{$row['coordinate_y']}";
            $result[$key] = $row;
        }

        return $result;
    }

}
