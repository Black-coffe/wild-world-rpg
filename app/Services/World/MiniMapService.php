<?php

namespace App\Services\World;

use App\Models\MapModel;
use App\Models\BiomeModel;
use App\Models\ExploredCellsModel;
use App\Models\CharacterModel;

/**
 * Сервис для отрисовки локальной мини‐карты (37x37 клеток, 30x30 px каждая).
 * Возвращает путь к временному PNG или null.
 */
class MiniMapService
{
    protected MapModel $mapModel;
    protected BiomeModel $biomeModel;
    protected ExploredCellsModel $exploredCellsModel;
    protected CharacterModel $characterModel;

    /**
     * Соответствие "ID биома" → цвет (R,G,B).
     */
    protected array $biomeColors = [
        1 => [0x00, 0x88, 0x74], // #008874  (Лес)
        2 => [0x00, 0x32, 0x39], // #003239  (Горы)
        3 => [0xFF, 0xFF, 0xFF], // #ffffff  (Тундра)
        4 => [0x39, 0xDB, 0x97], // #39db97  (Реки)
        5 => [0xD9, 0xD2, 0x29], // #d9d229  (Троп. джунгли)
        6 => [0xDA, 0xC9, 0x9D], // #dac99d  (Поля)
        7 => [0x4E, 0x42, 0x11], // #4e4211  (Пещеры)
        8 => [0xCC, 0x00, 0x00], // #cc0000  (Вулкан) — Идея #8: чёрный сливался с границами, заменён лавово-красным
        9 => [0x82, 0x64, 0x2B], // #82642b  (Пустыни)
    ];

    public function __construct()
    {
        $this->mapModel           = new MapModel();
        $this->biomeModel         = new BiomeModel();
        $this->exploredCellsModel = new ExploredCellsModel();
        $this->characterModel     = new CharacterModel();
    }

    /**
     * Генерирует карту 37x37 клеток, от игрока ±18, каждая ячейка 30x30 px.
     * Возвращает путь к PNG или null, если не удалось создать.
     */
    public function generateLocalMiniMap(array|\App\Entities\CharacterEntity $characterRow): ?string
    {
        // 1) Ищем cell_number
        $cellNumber = $characterRow['cell_number'] ?? 0;
        if (!$cellNumber) {
            return null;
        }

        // Координаты игрока
        $mapRow = $this->mapModel->where('cell_number', $cellNumber)->first();
        if (!$mapRow) {
            return null;
        }
        $pX = (int) $mapRow['coordinate_x'];
        $pY = (int) $mapRow['coordinate_y'];

        // 2) Параметры
        $offset     = 18;                 // ±18 клеток => 37 всего
        $cellsCount = $offset * 2 + 1;    // 37
        $cellSize   = 30;                 // 30x30 пикселей на ячейку
        $imgSize    = $cellsCount * $cellSize; // 1110x1110

        // Создаём холст
        $im = @imagecreatetruecolor($imgSize, $imgSize);
        if (!$im) {
            return null;
        }

        // Цвета
        $white       = imagecolorallocate($im, 255, 255, 255);  // фон
        $gray        = imagecolorallocate($im, 128, 128, 128);  // не изученные бордеры, пустая зона и fallback
        $redCell     = imagecolorallocate($im, 255, 0, 0);      // клетка игрока
        $textCol     = imagecolorallocate($im, 128, 128, 128);  // цвет текста (серый, можно поменять)
        $borderRed   = imagecolorallocate($im, 255, 0, 0);      // красная рамка для изученных
        $borderGray  = $gray;                                   // серая рамка
        // Заполняем холст белым
        imagefilledrectangle($im, 0, 0, $imgSize - 1, $imgSize - 1, $white);

        // 3) Диапазон ячеек
        $xMin = $pX - $offset;
        $xMax = $pX + $offset;
        $yMin = $pY - $offset;
        $yMax = $pY + $offset;

        // Список изученных ячеек
        $explored = $this->exploredCellsModel
            ->select('map_cell_id')
            ->where('character_id', $characterRow['id'])
            ->whereIn('map_cell_id', function($builder) use($xMin, $xMax, $yMin, $yMax){
                $builder->select('cell_number')
                    ->from('map')
                    ->where("coordinate_x >= {$xMin}")
                    ->where("coordinate_x <= {$xMax}")
                    ->where("coordinate_y >= {$yMin}")
                    ->where("coordinate_y <= {$yMax}");
            })
            ->findAll();
        // Превращаем в Set
        $exploredSet = [];
        foreach ($explored as $row) {
            $exploredSet[$row['map_cell_id']] = true;
        }

        // Выбираем mapSlice
        $mapSlice = $this->mapModel
            ->select('cell_number, biome_id, coordinate_x, coordinate_y')
            ->where('coordinate_x >=', $xMin)
            ->where('coordinate_x <=', $xMax)
            ->where('coordinate_y >=', $yMin)
            ->where('coordinate_y <=', $yMax)
            ->findAll();

        // x_y -> [cell_number, biome_id]
        $cellsData = [];
        foreach ($mapSlice as $m) {
            $xx = (int)$m['coordinate_x'];
            $yy = (int)$m['coordinate_y'];
            $cNum = (int)$m['cell_number'];
            $biId = (int)$m['biome_id'];

            $cellsData["{$xx}_{$yy}"] = [
                'cell_number' => $cNum,
                'biome_id'    => $biId,
            ];
        }

        // 4) Рендер каждой ячейки
        for ($localY = 0; $localY < $cellsCount; $localY++) {
            for ($localX = 0; $localX < $cellsCount; $localX++) {
                $worldX = $xMin + $localX;
                $worldY = $yMin + $localY;

                $pxX = $localX * $cellSize;
                $pxY = $localY * $cellSize;

                // Если за границами 1..1000 => серый
                if ($worldX < 1 || $worldX > 1000 || $worldY < 1 || $worldY > 1000) {
                    imagefilledrectangle($im, $pxX, $pxY, $pxX+$cellSize-1, $pxY+$cellSize-1, $gray);
                    // бордер (серый)
                    imagerectangle($im, $pxX, $pxY, $pxX+$cellSize-1, $pxY+$cellSize-1, $borderGray);
                    continue;
                }

                // Ячейка игрока?
                if ($worldX === $pX && $worldY === $pY) {
                    // заливаем красным
                    imagefilledrectangle($im, $pxX, $pxY, $pxX+$cellSize-1, $pxY+$cellSize-1, $redCell);
                    // бордер (оставим серым или можно сделать чёрным)
                    imagerectangle($im, $pxX, $pxY, $pxX+$cellSize-1, $pxY+$cellSize-1, $borderGray);
                    continue;
                }

                // Поиск в $cellsData
                $key = "{$worldX}_{$worldY}";
                if (!isset($cellsData[$key])) {
                    // серый
                    imagefilledrectangle($im, $pxX, $pxY, $pxX+$cellSize-1, $pxY+$cellSize-1, $gray);
                    imagerectangle($im, $pxX, $pxY, $pxX+$cellSize-1, $pxY+$cellSize-1, $borderGray);
                    continue;
                }

                $cellNum = $cellsData[$key]['cell_number'];
                $biomeId = $cellsData[$key]['biome_id'];

                // Заливка биом‐цветом
                $cellColor = $this->getBiomeColor($biomeId, $im);
                imagefilledrectangle($im, $pxX, $pxY, $pxX+$cellSize-1, $pxY+$cellSize-1, $cellColor);

                // Определяем цвет рамки (красный, если изучена; иначе серый)
                $borderColor = isset($exploredSet[$cellNum]) ? $borderRed : $borderGray;
                imagerectangle($im, $pxX, $pxY, $pxX+$cellSize-1, $pxY+$cellSize-1, $borderColor);

                // Текст = просто номер биома (без +)
                $text = (string)$biomeId;

                // Рисуем текст
                $font = 5; // встроенный шрифт (размер)
                $txtWidth  = imagefontwidth($font) * strlen($text);
                $txtHeight = imagefontheight($font);

                $centerX = (int)($pxX + ($cellSize - $txtWidth)/2);
                $centerY = (int)($pxY + ($cellSize - $txtHeight)/2);

                imagestring($im, $font, $centerX, $centerY, $text, $textCol);
            }
        }

        // 5) Сохраняем во временный файл
        $tempDir = WRITEPATH . 'tmp_map/';
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0777, true);
        }
        $tempFile = $tempDir . 'minimap_' . uniqid() . '.png';

        imagepng($im, $tempFile);
        imagedestroy($im);

        return $tempFile;
    }

    /**
     * Получить цвет по biomeId или серый, если нет в массиве.
     */
    protected function getBiomeColor(int $biomeId, $im)
    {
        if (isset($this->biomeColors[$biomeId])) {
            [$r, $g, $b] = $this->biomeColors[$biomeId];
            return imagecolorallocate($im, $r, $g, $b);
        }
        // fallback - серый
        return imagecolorallocate($im, 128, 128, 128);
    }
}
