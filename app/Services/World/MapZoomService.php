<?php

namespace App\Services\World;

/**
 * Сервис для «зумирования» карты при переезде персонажа в новую локацию.
 */
class MapZoomService
{
    /**
     * Создаёт временный PNG-файл с «зумированным» участком карты.
     *
     * @param string $mapType  'accurate' или 'beautiful'
     * @param int    $x        Коорд X игрока (1..1000)
     * @param int    $y        Коорд Y игрока (1..1000)
     * @return string|null      Путь к PNG-файлу (или null при ошибке)
     */
    public function createZoomedMap(string $mapType, int $x, int $y): ?string
    {
        // 1) Определяем базовую картинку
        $baseMapPath = FCPATH . 'uploads/telegram/character/world_map_1000x1000.png';
        if ($mapType === 'beautiful') {
            $baseMapPath = FCPATH . 'uploads/telegram/character/beautiful_map.png';
        }

        if (!file_exists($baseMapPath)) {
            log_message('error', "MapZoomService: файл карты не найден: {$baseMapPath}");
            return null;
        }

        // 2) Открываем bigMap (1000×1000)
        $bigIm = @imagecreatefrompng($baseMapPath);
        if (!$bigIm) {
            log_message('error', "MapZoomService: ошибка GD при открытии PNG: {$baseMapPath}");
            return null;
        }

        $mapW = 1000;
        $mapH = 1000;

        // Ширина/высота квадрата, который мы вырежем
        $cropSize = 100;

        // Расчитываем левый верхний угол (чтобы (x,y) был примерно в центре)
        $half = (int) floor($cropSize / 2);

        // Коорд левого верхнего угла
        $srcX = max(0, $x - $half);
        $srcY = max(0, $y - $half);

        // Не выходим за границы
        if ($srcX + $cropSize > $mapW) {
            $srcX = $mapW - $cropSize;
        }
        if ($srcY + $cropSize > $mapH) {
            $srcY = $mapH - $cropSize;
        }

        // 3) Создаём холст для вырезки 100×100
        $cropIm = imagecreatetruecolor($cropSize, $cropSize);

        // Копируем кусок из $bigIm → $cropIm
        imagecopy(
            $cropIm,
            $bigIm,
            0, 0,
            $srcX, $srcY,
            $cropSize, $cropSize
        );

        // 4) Масштабируем вырезку → 1000×1000
        $zoomedSize = 1000;
        $zoomIm = imagecreatetruecolor($zoomedSize, $zoomedSize);

        imagecopyresampled(
            $zoomIm,           // dest
            $cropIm,           // src
            0, 0,              // destX,destY
            0, 0,              // srcX, srcY
            $zoomedSize,       // destWidth
            $zoomedSize,       // destHeight
            $cropSize,         // srcWidth
            $cropSize          // srcHeight
        );

        // Дополнительно нарисуем перекрестие в центре (500,500)
        $red = imagecolorallocate($zoomIm, 255, 0, 0);
        imagesetthickness($zoomIm, 3);
        $center = (int) floor($zoomedSize / 2); // 500
        $line   = 20;
        // Горизонтальная
        imageline($zoomIm, $center - $line, $center, $center + $line, $center, $red);
        // Вертикальная
        imageline($zoomIm, $center, $center - $line, $center, $center + $line, $red);

        // 5) Сохраняем во временный файл
        $tempDir = FCPATH . 'uploads/tmp';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0777, true);
        }
        $tempFile = $tempDir . '/map_zoom_' . uniqid() . '.png';

        imagepng($zoomIm, $tempFile);

        imagedestroy($cropIm);
        imagedestroy($zoomIm);
        imagedestroy($bigIm);

        return $tempFile;
    }
}
