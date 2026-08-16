<?php

namespace App\Services\World;

use App\Models\MapModel;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

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
    public function showMapWithPlayer(int $chatId, array|\App\Entities\CharacterEntity $characterRow, ?string $replyMarkup = null): ServerResponse
    {
        // Проверяем поле preferred_map_type
        $mapType = $characterRow['preferred_map_type'] ?? null;

        if ($mapType === null) {
            // Ещё не выбран вид карты → просим выбрать КНОПКАМИ. Раньше здесь просили набрать
            // `accurate_map`/`beautiful_map` руками, и это было единственное место в игре, где
            // игрок вообще узнавал о таких командах: после первого выбора экран не показывался
            // никогда, а тумблера не существовало → сменить вид было нечем (сигнал из чата 21.07.2026).
            $text = "*Не выбран вид карты*\n\n"
                . "🗺 *Точная* — пиксель в пиксель, удобно считать координаты.\n"
                . "🎨 *Художественная* — живописнее, но менее точная.\n\n"
                . "_Выбери кнопкой ниже и открой «🗺 Обзор» ещё раз. "
                . "Поменять вид потом можно в любой момент: «⚙️ Ещё» → «⚙️ Настройки»._";

            $keyboard = ['inline_keyboard' => [[
                ['text' => '🗺 Точная карта',         'callback_data' => 'mapAccurate'],
                ['text' => '🎨 Художественная карта', 'callback_data' => 'mapBeautiful'],
            ]]];

            return Request::sendMessage([
                'chat_id'      => $chatId,
                'text'         => $text,
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode($keyboard) ?: '{}',
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

        // S4 (ROADMAP-RETENTION-10) — «полярная звезда»: текущая цель онбординг-цепочки в
        // подписи карты (новичок принимает решение «куда идти» именно здесь). caption несёт
        // весь смысл текстом → media-off безопасно (MediaSender подставит caption как text).
        $polarLine = (new \App\Services\Onboarding\PolarStarService())->line((int) ($characterRow['id'] ?? 0));
        if ($polarLine !== null) {
            $caption .= "\n\n" . $polarLine;
        }

        // ADR-150 Слайс 1: «🗺 Обзор» несёт кнопку возврата «🧭 Идти» (callback move) —
        // чтобы фото-карта не была тупиком. reply_markup опционален: OFF-путь (нижняя
        // кнопка «Карта» при world_hub OFF) передаёт null → byte-identical.
        $photoParams = [
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile($tempFile),
            'caption'    => $caption,
            'parse_mode' => 'Markdown',
        ];
        if ($replyMarkup !== null) {
            $photoParams['reply_markup'] = $replyMarkup;
        }
        $response = \App\Services\Notifications\MediaSender::sendPhotoOrText($photoParams);

        // 7. Удаляем временный файл
        @unlink($tempFile);

        return $response;
    }
}
