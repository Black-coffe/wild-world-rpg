<?php

namespace App\Services\World;

use App\Models\MapModel;
use App\Models\ExploredCellsModel;
use App\Models\CharacterModel;
use App\Models\ClaimedCellModel;

/**
 * Сервис для текстовой мини‐карты 12x12 клеток в псевдографике (эмодзи).
 * Игрок — 🙎‍♂️ по центру (row=6, col=6).
 * База — 🏕, если в пределах отображаемой зоны.
 * Не изученные ячейки — ⬛️, изученные — эмодзи соответствующего биома.
 */
class TextMapService
{
    protected MapModel $mapModel;
    protected ExploredCellsModel $exploredCellsModel;
    protected CharacterModel $characterModel;
    protected ClaimedCellModel $claimedCellModel;

    /**
     * Сопоставление biome_id -> эмодзи
     */
    protected array $biomeEmojis = [
        1 => "🌲", // Лес
        2 => "⛰️", // Горы
        3 => "❄️", // Тундра / лед. пустоши
        4 => "🌊", // Реки
        5 => "🌴", // Троп. джунгли
        6 => "🌾", // Поля
        7 => "🕳️", // Пещеры / подземелья
        8 => "🌋", // Вулкан
        9 => "🏜️", // Пустыни
        // fallback
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
     * Формирует строку 12x12 псевдографики.
     *
     * @param array $characterRow — данные персонажа (должны содержать cell_number, id и т.д.).
     * @return string Многострочный текст карты + легенда
     */
    public function build12x12Map(array $characterRow): string
    {
        // 1) Проверяем cell_number
        $cellNumber = $characterRow['cell_number'] ?? 0;
        if (!$cellNumber) {
            return "Нет cell_number у персонажа";
        }

        // Находим координаты персонажа
        $mapRow = $this->mapModel->where('cell_number', $cellNumber)->first();
        if (!$mapRow) {
            return "Не найдена запись в map для cell_number={$cellNumber}";
        }

        $pX = (int)$mapRow['coordinate_x'];
        $pY = (int)$mapRow['coordinate_y'];

        // 2) Диапазон (12x12, игрок в центре)
        $offset = 6;   // «радиус»
        $width  = 12;
        $height = 12;

        $xMin = $pX - $offset;
        $xMax = $pX + ($offset - 1);
        $yMin = $pY - $offset;
        $yMax = $pY + ($offset - 1);

        // 3) Собираем «изученные» ячейки
        $exploredRows = $this->exploredCellsModel
            ->select('map_cell_id')
            ->where('character_id', $characterRow['id'])
            ->whereIn('map_cell_id', function($builder) use ($xMin, $xMax, $yMin, $yMax) {
                // Подзапрос для ограничений по X и Y
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

        // 4) Достаём информацию о ячейках в данном диапазоне
        $mapData = $this->mapModel
            ->select('cell_number, biome_id, coordinate_x, coordinate_y')
            ->where('coordinate_x >=', $xMin)
            ->where('coordinate_x <=', $xMax)
            ->where('coordinate_y >=', $yMin)
            ->where('coordinate_y <=', $yMax)
            ->findAll();

        // key = "x_y" -> [biome_id, cell_number]
        $cells = [];
        foreach ($mapData as $row) {
            $xx = (int)$row['coordinate_x'];
            $yy = (int)$row['coordinate_y'];
            $cells["{$xx}_{$yy}"] = [
                'biome_id'    => (int)$row['biome_id'],
                'cell_number' => (int)$row['cell_number'],
            ];
        }

        // 5) Определяем координаты базы, если она активна
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

        // 6) Генерируем карту построчно
        $mapText = "Мини‐карта (12x12). Игрок по центру: X={$pX}, Y={$pY}\n";

        for ($localY = 0; $localY < $height; $localY++) {
            $worldY = $yMin + $localY;

            for ($localX = 0; $localX < $width; $localX++) {
                $worldX = $xMin + $localX;

                // Сначала проверяем, не вышли ли за границы «мира» (допустим, 1..1000)
                if ($worldX < 1 || $worldX > 1000 || $worldY < 1 || $worldY > 1000) {
                    $mapText .= "⬜"; // за пределами карты
                    continue;
                }

                // Если клетка соответствует координатам игрока:
                if ($worldX === $pX && $worldY === $pY) {
                    $mapText .= "🙎‍♂️";
                    continue;
                }

                // Если у нас есть база в пределах отображения
                if ($baseX !== null && $baseY !== null) {
                    // Если это координата базы (и не совпадает с игроком)
                    if ($worldX === $baseX && $worldY === $baseY) {
                        $mapText .= "🏕";
                        continue;
                    }
                }

                // Ищем ключ "x_y" в $cells
                $key = "{$worldX}_{$worldY}";
                if (!isset($cells[$key])) {
                    // Нет такой ячейки => чёрный квадрат
                    $mapText .= "⬛️";
                    continue;
                }

                $biomeId  = $cells[$key]['biome_id'];
                $cellNum  = $cells[$key]['cell_number'];

                // Проверяем, изучено ли
                if (!isset($exploredSet[$cellNum])) {
                    // Не изучено
                    $mapText .= "⬛️";
                } else {
                    // Изучено → эмодзи биома
                    $emoji = $this->biomeEmojis[$biomeId] ?? $this->biomeEmojis[0];
                    $mapText .= $emoji;
                }
            }
            $mapText .= "\n"; // конец строки
        }

        // 7) Добавляем легенду
        $mapText .= "\nЛегенда:\n";
        $mapText .= "🙎‍♂️ — игрок\n";
        $mapText .= "🏕 — база игрока\n";
        $mapText .= "⬛️ — не изучено (или нет данных)\n";
        $mapText .= "⬜ — за пределами мира\n\n";

        // Биомы:
        $mapText .= "1) 🌲 — Лес\n";
        $mapText .= "2) ⛰️ — Горы\n";
        $mapText .= "3) ❄️ — Тундра / Лед. пустоши\n";
        $mapText .= "4) 🌊 — Реки\n";
        $mapText .= "5) 🌴 — Троп. джунгли\n";
        $mapText .= "6) 🌾 — Поля\n";
        $mapText .= "7) 🕳️ — Пещеры / подзем.\n";
        $mapText .= "8) 🌋 — Вулкан\n";
        $mapText .= "9) 🏜️ — Пустыни\n";

        // 8) Добавим строку с информацией о расстоянии (если база существует)
        if ($baseX !== null && $baseY !== null) {
            // Расстояние по метрике Чебышёва (если разрешено движение по диагонали без препятствий)
            $deltaX = abs($pX - $baseX);
            $deltaY = abs($pY - $baseY);
            $distance = max($deltaX, $deltaY);

            $mapText .= "\nОт 🙎‍♂️ до 🏕 = {$distance} ходов\n";
        }

        return $mapText;
    }
}
