<?php

namespace App\Services\World;

use App\Models\MapModel;
use App\Models\ExploredCellsModel;
use App\Models\CharacterModel;

/**
 * Сервис для текстовой мини‐карты 12x12 клеток в псевдографике (эмодзи).
 * Игрок по "центру" (строго говоря, row=6, col=6),
 * не изученные = чёрный квадрат (⬛️), изученные = эмодзи биома,
 * игрок = 🙎‍♂️
 */
class TextMapService
{
    protected MapModel $mapModel;
    protected ExploredCellsModel $exploredCellsModel;
    protected CharacterModel $characterModel;

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
    }

    /**
     * Формирует строку 12x12 псевдографики.
     * Игрок ("🙎‍♂️") в центре, не изучено -> "⬛️", изучено -> эмодзи по biome_id.
     *
     * @param array $characterRow — данные персонажа (должны содержать cell_number)
     * @return string Многострочный текст карты + легенда
     */
    public function build12x12Map(array $characterRow): string
    {
        // 1) Проверяем cell_number
        $cellNumber = $characterRow['cell_number'] ?? 0;
        if (!$cellNumber) {
            return "Нет cell_number у персонажа";
        }

        // Извлекаем координаты игрока из таблицы map
        $mapRow = $this->mapModel->where('cell_number', $cellNumber)->first();
        if (!$mapRow) {
            return "Не найдена запись в map для cell_number={$cellNumber}";
        }

        $pX = (int)$mapRow['coordinate_x'];
        $pY = (int)$mapRow['coordinate_y'];

        // 2) Нам нужно 12x12, значит offset=6 (центр в [6,6])
        $offset = 6;     // ±6 => итого 12
        $width  = 12;
        $height = 12;

        // Границы по X, Y
        $xMin = $pX - $offset;      // pX-6
        $xMax = $pX + ($offset - 1); // pX+5
        $yMin = $pY - $offset;
        $yMax = $pY + ($offset - 1);

        // 3) Собираем "изученные" ячейки
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

        // 4) Достаём информацию о ячейках в этом диапазоне
        $mapData = $this->mapModel
            ->select('cell_number, biome_id, coordinate_x, coordinate_y')
            ->where('coordinate_x >=', $xMin)
            ->where('coordinate_x <=', $xMax)
            ->where('coordinate_y >=', $yMin)
            ->where('coordinate_y <=', $yMax)
            ->findAll();

        // Кеш: "x_y" -> [biome_id, cell_number]
        $cells = [];
        foreach ($mapData as $row) {
            $xx = (int)$row['coordinate_x'];
            $yy = (int)$row['coordinate_y'];
            $cells["{$xx}_{$yy}"] = [
                'biome_id'    => (int)$row['biome_id'],
                'cell_number' => (int)$row['cell_number'],
            ];
        }

        // 5) Генерируем текст построчно
        $mapText = "Мини‐карта (12x12). Игрок по центру (X={$pX}, Y={$pY}).\n";

        for ($localY = 0; $localY < $height; $localY++) {
            $worldY = $yMin + $localY;

            for ($localX = 0; $localX < $width; $localX++) {
                $worldX = $xMin + $localX;

                // Если клетка игрока?
                if ($worldX === $pX && $worldY === $pY) {
                    $mapText .= "🙎‍♂️";
                    continue;
                }

                // За границами мира 1..1000?
                if ($worldX < 1 || $worldX > 1000 || $worldY < 1 || $worldY > 1000) {
                    // белый/пробел/что-то
                    $mapText .= "⬜";
                    continue;
                }

                // Ищем данные
                $key = "{$worldX}_{$worldY}";
                if (!isset($cells[$key])) {
                    // Нет записи => чёрный
                    $mapText .= "⬛️";
                    continue;
                }

                $biomeId = $cells[$key]['biome_id'];
                $cellNum = $cells[$key]['cell_number'];

                // Если не изучено => чёрный
                if (!isset($exploredSet[$cellNum])) {
                    $mapText .= "⬛️";
                } else {
                    // Изучено => берём эмодзи
                    $emoji = $this->biomeEmojis[$biomeId] ?? $this->biomeEmojis[0];
                    $mapText .= $emoji;
                }
            }
            $mapText .= "\n"; // конец строки
        }

        // 6) Добавим легенду
        $mapText .= "\nЛегенда:\n";
        $mapText .= "🙎‍♂️ — игрок\n";
        $mapText .= "⬛️ — не изучено (или нет данных)\n";
        $mapText .= "⬜ — за пределами мира\n\n";

        // 9 биомов:
        $mapText .= "1) 🌲 — Лес\n";
        $mapText .= "2) ⛰️ — Горы\n";
        $mapText .= "3) ❄️ — Тундра / Лед. пустоши\n";
        $mapText .= "4) 🌊 — Реки\n";
        $mapText .= "5) 🌴 — Троп. джунгли\n";
        $mapText .= "6) 🌾 — Поля\n";
        $mapText .= "7) 🕳️ — Пещеры / подзем.\n";
        $mapText .= "8) 🌋 — Вулкан\n";
        $mapText .= "9) 🏜️ — Пустыни\n";

        return $mapText;
    }
}
