<?php

namespace App\Services\World;

use App\Models\MapModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

// нужно, чтобы найти (x,y) персонажа
// если нужно что-то из CodeIgniter

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
     * Метод: берёт данные о персонаже, проверяет его предпочтения к карте,
     * если не выбрано — просит игрока выбрать.
     * Если выбрано, рисует нужную карту (учитывая масштаб 2px=1 coord).
     *
     * @param int   $chatId       Куда шлём ответ
     * @param array|\App\Entities\CharacterEntity $characterRow  Строка персонажа из БД
     */
    public function showMapWithPlayer(int $chatId, array|\App\Entities\CharacterEntity $characterRow): ServerResponse
    {
        // Проверяем поле preferred_map_type
        $mapType = $characterRow['preferred_map_type'] ?? null;

        if ($mapType === null) {
            // Ещё не выбрана карта → возвращаем просьбу выбрать
            $text = "*У вас не выбран тип карты!*\n\n"
                . "Доступно 2 варианта:\n"
                . "1. Точная карта (пиксель в пиксель):\n"
                . "   - введите команду: `accurate_map`\n"
                . "2. Художественная карта (более красивая, но менее точная):\n"
                . "   - введите команду: `beautiful_map`\n\n"
                . "_Скопируйте нужную команду и отправьте в этот же чат._\n";

            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => $text,
                'parse_mode' => 'Markdown',
            ]);
        }

        // Выбираем файл карты
        if ($mapType === 'accurate') {
            $baseMapPath = FCPATH . 'uploads/telegram/character/world_map_1000x1000.png';
        } else {
            $baseMapPath = FCPATH . 'uploads/telegram/character/beautiful_map.png';
        }

        // 1. Ищем ячейку персонажа
        $cellNumber = $characterRow['cell_number'] ?? 0;
        $mapRow = $this->mapModel->where('cell_number', $cellNumber)->first();
        if (!$mapRow) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "Карта: Ячейка с номером {$cellNumber} не найдена.",
            ]);
        }

        // Извлекаем X, Y
        $x = (int) $mapRow['coordinate_x'];
        $y = (int) $mapRow['coordinate_y'];

        // Граничные проверки (1..1000)
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

        // 2. Проверяем, существует ли файл
        if (!file_exists($baseMapPath)) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "Файл карты не найден: {$baseMapPath}",
            ]);
        }

        // 3. Загружаем картинку
        $im = @imagecreatefrompng($baseMapPath);
        if (!$im) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Ошибка GD: не могу открыть PNG.',
            ]);
        }

        // 4. Масштабируем логические координаты (1..1000) в пиксели (0..2000)
        // 2 px = 1 координата
        $gdX = 2 * ($x - 1);
        $gdY = 2 * ($y - 1);

        // Красный цвет для перекрестия
        $color = imagecolorallocate($im, 255, 0, 0);

        // Толщина линий = 2 px
        imagesetthickness($im, 2);

        // Горизонтальная линия: ±50 px от центра
        // (если хотите, чтобы она была "±50 клеток", то умножайте на 2 дополнительно)
        $halfLine = 50;
        imageline($im, $gdX - $halfLine, $gdY, $gdX + $halfLine, $gdY, $color);

        // Вертикальная линия: ±50 px от центра
        imageline($im, $gdX, $gdY - $halfLine, $gdX, $gdY + $halfLine, $color);

        // Надпись вида "(123, 456)"
        $coordText = "({$x}, {$y})";
        imagestring($im, 5, $gdX + 6, $gdY - 20, $coordText, $color);

        // 5. Сохраняем во временный файл
        $tempPath = FCPATH . 'uploads/tmp';
        if (!is_dir($tempPath)) {
            mkdir($tempPath, 0777, true);
        }
        $tempFile = $tempPath . '/tmp_map_' . uniqid() . '.png';

        imagepng($im, $tempFile);
        imagedestroy($im);

        // 6. Отправляем изображение в Telegram
        $caption = "Текущая локация: X={$x}, Y={$y}\n\n"
            . "Ячейка #{$cellNumber}\n"
            . "Вы используете карту: *{$mapType}* (масштаб 2px=1coord)";

        $response = Request::sendPhoto([
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile($tempFile),
            'caption'    => $caption,
            'parse_mode' => 'Markdown',
        ]);

        // 7. Удаляем временный файл
        @unlink($tempFile);

        return $response;
    }
}
