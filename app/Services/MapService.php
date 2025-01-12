<?php

namespace App\Services;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Models\MapModel;            // нужно, чтобы найти (x,y) персонажа
use CodeIgniter\Config\Services;    // если нужно что-то из CodeIgniter

class MapService
{
    /** @var MapModel */
    protected $mapModel;

    /**
     * В конструкторе подключим нужные модели.
     */
    public function __construct()
    {
        $this->mapModel = new MapModel();
    }

    /**
     * Главный метод: берёт данные о персонаже, ищет его (x,y),
     * рисует перекрестие и шлёт обратно в Telegram.
     *
     * @param int   $chatId       Куда шлём ответ
     * @param array $characterRow  Строка персонажа из БД (с ключом 'cell_number')
     */
    public function showMapWithPlayer(int $chatId, array $characterRow): ServerResponse
    {
        // 1. Ищем ячейку в таблице map (чтобы получить coordinate_x, coordinate_y)
        $cellNumber = $characterRow['cell_number'] ?? 0;
        $mapRow = $this->mapModel->where('cell_number', $cellNumber)->first();
        if (!$mapRow) {
            // Если вдруг нет записи в таблице map
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "Карта: Ячейка с номером {$cellNumber} не найдена.",
            ]);
        }

        // Извлекаем X, Y (1..1000)
        $x = (int) $mapRow['coordinate_x'];
        $y = (int) $mapRow['coordinate_y'];

        // Проверка на выход за пределы 1000×1000
        if ($x < 1) {
            $x = 1;
        } elseif ($x > 1000) {
            $x = 1000;
        }
        if ($y < 1) {
            $y = 1;
        } elseif ($y > 1000) {
            $y = 1000;
        }

        // 2. Путь к исходной карте
        $baseMapPath = FCPATH . 'uploads/telegram/character/world_map_1000x1000.png';

        if (!file_exists($baseMapPath)) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "Файл карты не найден: {$baseMapPath}",
            ]);
        }

        // 3. Загружаем PNG-картинку через GD
        $im = @imagecreatefrompng($baseMapPath);
        if (!$im) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Ошибка GD: не могу открыть исходный PNG.',
            ]);
        }

        // 4. Рисуем перекрестие
        // Переносим координаты в «0-индекс» (GD)
        $gdX = $x - 1;
        $gdY = $y - 1;

        // Красный цвет
        $color = imagecolorallocate($im, 255, 0, 0);

        // Толщина линий = 2 px
        imagesetthickness($im, 2);

        // Рисуем линию по горизонтали 100 px (по 50 px влево и вправо от центра)
        imageline($im, $gdX - 50, $gdY, $gdX + 50, $gdY, $color);

        // Рисуем линию по вертикали 100 px (по 50 px вверх и вниз от центра)
        imageline($im, $gdX, $gdY - 50, $gdX, $gdY + 50, $color);

        // 5. Пишем надпись вида "(123, 456)" шрифтом 5 (самый крупный «встроенный»)
        $coordText = "({$x}, {$y})";
        // Смещаем чуть правее и выше основной точки
        imagestring($im, 5, $gdX + 6, $gdY - 20, $coordText, $color);

        // 6. Сохраняем во временный файл PNG
        $tempPath = FCPATH . 'uploads/tmp';
        if (! is_dir($tempPath)) {
            mkdir($tempPath, 0777, true);
        }
        $tempFile = $tempPath . '/tmp_map_' . uniqid() . '.png';

        imagepng($im, $tempFile);
        imagedestroy($im);

        // 7. Отправляем готовое изображение в Telegram
        $caption = "Текущая локация: X={$x}, Y={$y}\n\n"
            . "Ячейка #{$cellNumber}";

        $response = Request::sendPhoto([
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile($tempFile),
            'caption'    => $caption,
            'parse_mode' => 'Markdown',
        ]);

        // 8. Удаляем временный файл
        @unlink($tempFile);

        return $response;
    }
}
