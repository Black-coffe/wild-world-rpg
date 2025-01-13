<?php

namespace App\Controllers\Telegram\Commands\Actions;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Models\CharacterModel;
use App\Models\MapModel;
use App\Models\TelegramUserModel;
use App\Models\CharacterTaskModel;
use App\Models\TaskModel;
use App\Models\ExploredCellsModel;
use App\Models\BiomeModel;

/**
 * Класс, отвечающий за отображение возможностей переезда персонажа и
 * переход в соседние (исследованные) локации. Упрощённый радиус = 3.
 */
class MoveCharacterAction
{
    protected $callbackQuery;
    protected $characterModel;
    protected $mapModel;
    protected $characterTaskModel;
    protected $taskModel;
    protected $telegramUserModel;
    protected $exploredCellsModel;
    protected $biomeModel;

    // Урезанный радиус: теперь только 3 клетки «вглубь» вокруг персонажа
    protected $visionRadius = 5;

    public function __construct(CallbackQuery $callbackQuery)
    {
        $this->callbackQuery      = $callbackQuery;
        $this->characterModel     = new CharacterModel();
        $this->mapModel           = new MapModel();
        $this->characterTaskModel = new CharacterTaskModel();
        $this->taskModel          = new TaskModel();
        $this->telegramUserModel  = new TelegramUserModel();
        $this->exploredCellsModel = new ExploredCellsModel();
        $this->biomeModel         = new BiomeModel();
    }

    public function handle(): ServerResponse
    {
        $chatId         = $this->callbackQuery->getMessage()->getChat()->getId();
        $telegramUserId = $this->callbackQuery->getFrom()->getId();

        // 1. Проверяем пользователя
        $user = $this->telegramUserModel->where('telegram_id', $telegramUserId)->first();
        if (!$user) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден в базе данных.'
            ]);
        }

        // 2. Проверяем персонажа
        $character = $this->characterModel->where('telegram_user_id', $user['id'])->first();
        if (!$character || !$character['cell_number']) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Персонаж не найден или не имеет локации.'
            ]);
        }

        // 3. Проверка здоровья и усталости
        if ($character['health'] <= 5 || $character['tired'] <= 10) {
            $text = "🚑 *Ваш персонаж не выдержит больше физических нагрузок.*\n\n"
                . "*Необходимо восстановить здоровье или выносливость*\n\n"
                . "*💖 здоровье: {$character['health']} %*\n"
                . "*🥱 выносливость: {$character['tired']} %*\n\n"
                . "*Отлежитесь или используйте аптечку* 👇\n\n";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '💊 Аптечка', 'callback_data' => 'pharmacy'],
                    ],
                ]
            ];

            $imagePath = base_url('uploads/telegram/character_exhausted_need_treatment.png');
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

            return Request::sendPhoto([
                'chat_id'    => $chatId,
                'photo'      => Request::encodeFile($imagePath),
                'caption'    => $text,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        }

        // 4. Проверяем параллельные задачи (непараллельные блокируют переезд)
        $activeTasks = $this->characterTaskModel
            ->where('character_id', $character['id'])
            ->where('telegram_user_id', $user['id'])
            ->where('status', 'in_work')
            ->findAll();

        // Ищем непараллельные задачи
        $nonParallelTasks = [];
        foreach ($activeTasks as $task) {
            $taskDetails = $this->taskModel->find($task['task_id']);
            if ($taskDetails && $taskDetails['parallel_execution_allowed'] == 0) {
                $nonParallelTasks[] = $task;
            }
        }

        if (!empty($nonParallelTasks)) {
            // Если есть задача, которая не допускает параллельного выполнения
            $task        = $nonParallelTasks[0];
            $taskDetails = $this->taskModel->find($task['task_id']);

            $endTime     = new \DateTime($task['end_time']);
            $now         = new \DateTime();
            $timeLeft    = $now > $endTime ? 0 : $now->diff($endTime);
            $minutesLeft = $now > $endTime ? 0 : ($timeLeft->days * 24 * 60 + $timeLeft->h * 60 + $timeLeft->i);
            $timeLeftText = $now > $endTime ? "00" : $minutesLeft;

            $text = "*Ой! Не получается начать переезд!* 😥\n\n"
                . "*Вы заняты выполнением другой задачи:* \n\n"
                . "👉 *{$taskDetails['name_rus']}* 👈\n"
                . "⌛️ До конца ещё: *{$timeLeftText}* минут!\n\n"
                . "**😔 Пожалуйста, завершите текущую задачу или дождитесь окончания, прежде чем начинать новую.**\n\n";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions']
                    ],
                ]
            ];

            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id'      => $chatId,
                'text'         => $text,
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode($keyboard)
            ]);
        }

        // 5. Текущая локация
        $currentCell = $this->mapModel->where('cell_number', $character['cell_number'])->first();
        if (!$currentCell) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Локация персонажа не найдена.'
            ]);
        }

        // --- НИЖЕ: РЕАЛИЗАЦИЯ ОПТИМИЗИРОВАННОЙ ЗАГРУЗКИ ДАННЫХ ---
        // 6. Собираем все координаты в квадрате ± visionRadius (3)
        $x = $currentCell['coordinate_x'];
        $y = $currentCell['coordinate_y'];

        $coords = [];
        for ($dx = -$this->visionRadius; $dx <= $this->visionRadius; $dx++) {
            for ($dy = -$this->visionRadius; $dy <= $this->visionRadius; $dy++) {
                $nx = $x + $dx;
                $ny = $y + $dy;
                // Проверяем, что это в пределах (например, если карта 1..1000)
                if ($nx >= 1 && $nx <= 1000 && $ny >= 1 && $ny <= 1000) {
                    $coords[] = ['x' => $nx, 'y' => $ny];
                }
            }
        }

        // 7. Одним запросом получаем все ячейки map, подходящие под эти координаты
        $xValues = array_column($coords, 'x');
        $yValues = array_column($coords, 'y');

        // Поскольку inWhere('coordinate_x', $xValues) И inWhere('coordinate_y', $yValues)
        // может работать не так, как нужно (это логика "coordinate_x IN (...)" AND "coordinate_y IN (...)"
        // — а нам нужен набор пар), в CodeIgniter нет стандартной возможности "парного" IN.
        // Но для простоты используем whereIn для X, whereIn для Y.
        // (Допущение: false positives маловероятны, ибо пересечение - узкий набор).
        $cells = $this->mapModel
            ->whereIn('coordinate_x', $xValues)
            ->whereIn('coordinate_y', $yValues)
            ->findAll();

        // 7.2 формируем mapCellsById и массив Id
        $mapCellsById = [];
        $cellIds = [];
        foreach ($cells as $c) {
            $mapCellsById[$c['id']] = $c;
            $cellIds[] = $c['id'];
        }

        // 8. Одним запросом получаем все exploredCells для данного персонажа и этих cellIds
        $exploredRows = $this->exploredCellsModel
            ->where('character_id', $character['id'])
            ->whereIn('map_cell_id', $cellIds)
            ->findAll();

        // 8.2 флаговый массив
        $exploredMap = [];
        foreach ($exploredRows as $er) {
            $exploredMap[$er['map_cell_id']] = true;
        }

        // 9. Подсчитываем, сколько из них исследовано
        $totalExploredInRadius = 0;
        foreach ($cellIds as $cid) {
            if (isset($exploredMap[$cid])) {
                $totalExploredInRadius++;
            }
        }

        // 10. Теперь сформируем инфо о 8 соседях:
        //    instead of doing getSurroundingCellsInfo() мелкими запросами,
        //    используем уже загруженные mapCellsById / exploredMap
        $surroundingCellsInfo = $this->buildSurroundingCellsInfo(
            $x,
            $y,
            $mapCellsById,
            $exploredMap
        );

        // 11. Генерируем клавиатуру
        $keyboardButtons = $this->generateKeyboard($surroundingCellsInfo, $mapCellsById, $exploredMap, $character['id']);

        if (empty($keyboardButtons)) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Нет доступных направлений для переезда. Возможно, стоит исследовать окрестности?'
            ]);
        }

        $keyboard = ['inline_keyboard' => $keyboardButtons];

        // 12. Формируем текст
        $text = "👋 Привет, герой! 🙋‍♂️\n\n"
            . "🚜 Ты готов к переезду? 🏡\n\n"
            . "🗺️ Доступные направления: выбирай, куда двинуться.\n\n"
            . "👀 Радиус обзора: *{$totalExploredInRadius}* ячеек\n\n"
            . "🍀 Удачи в пути! 🍀";

        $imagePath = base_url('uploads/telegram/moves_to_another_territory.png');

        // Ответ на callback
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // Итоговое сообщение
        return Request::sendPhoto([
            'chat_id'      => $chatId,
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Формируем массив c данными: "direction => cellId" для соседних клеток.
     */
    private function buildSurroundingCellsInfo(int $x, int $y, array $mapCellsById, array $exploredMap): array
    {
        $neighboringPositions = [
            'northwest' => [$x - 1, $y - 1],
            'north'     => [$x,     $y - 1],
            'northeast' => [$x + 1, $y - 1],
            'west'      => [$x - 1, $y],
            'east'      => [$x + 1, $y],
            'southwest' => [$x - 1, $y + 1],
            'south'     => [$x,     $y + 1],
            'southeast' => [$x + 1, $y + 1],
        ];

        $info = [];
        // Для быстрого поиска: mapCellsByCoord: [x,y] => id
        // Но у нас уже mapCellsById. Превратим его во временный массив "[$cell['coordinate_x'],$cell['coordinate_y']] => cellId".
        $mapCellsByCoord = [];
        foreach ($mapCellsById as $cid => $cell) {
            $xx = $cell['coordinate_x'];
            $yy = $cell['coordinate_y'];
            $mapCellsByCoord["{$xx}_{$yy}"] = $cid;
        }

        foreach ($neighboringPositions as $direction => [$nx, $ny]) {
            $key = "{$nx}_{$ny}";
            if (isset($mapCellsByCoord[$key])) {
                $cellId = $mapCellsByCoord[$key];
                // Проверяем, исследована ли
                if (isset($exploredMap[$cellId])) {
                    $info[$direction] = $cellId;
                }
            }
        }
        return $info;
    }

    /**
     * Генерируем клавиатуру с учётом количества исследованных ячеек (не более 10)
     * по направлению. Для этого смотрим "шаги" в цикле.
     */
    private function generateKeyboard(array $surroundingCellsInfo, array $mapCellsById, array $exploredMap, int $characterId): array
    {
        $directions = [
            ['northwest' => '↖️ Северо-запад', 'northeast' => '↗️ Северо-восток'],
            ['north' => '⬆️ Север',           'south' => '⬇️ Юг'],
            ['west' => '⬅️ Запад',            'east' => '➡️ Восток'],
            ['southwest' => '↙️ Юго-запад',    'southeast' => '↘️ Юго-восток'],
        ];

        $keyboard = [];
        foreach ($directions as $pair) {
            $row = [];
            foreach ($pair as $dir => $label) {
                if (isset($surroundingCellsInfo[$dir])) {
                    $startCellId = $surroundingCellsInfo[$dir];
                    // Сколько ещё исследованных клеток по направлению?
                    $countInDirection = $this->countExploredInSingleDirection(
                        $startCellId,
                        $dir,
                        $mapCellsById,
                        $exploredMap
                    );
                    $countLabel = min($countInDirection, 10);

                    $row[] = [
                        'text'          => "{$label} ({$countLabel})",
                        'callback_data' => "moveto{$dir}"
                    ];
                }
            }
            if (!empty($row)) {
                $keyboard[] = $row;
            }
        }
        return $keyboard;
    }

    /**
     * Сколько исследованных ячеек есть в направлении $dir, начиная от $startCellId, до 10.
     * Вместо отдельных запросов:
     *  - мы идём шагами (dx, dy)
     *  - на каждом шаге проверяем, есть ли такая ячейка в mapCellsById
     *  - и проверяем exploredMap[$cellId]
     */
    private function countExploredInSingleDirection(
        int $startCellId,
        string $dir,
        array $mapCellsById,
        array $exploredMap
    ): int {
        // Векторы смещения по направлениям
        $directionVectors = [
            'north'     => [ 0, -1 ],
            'south'     => [ 0,  1 ],
            'west'      => [-1,  0 ],
            'east'      => [ 1,  0 ],
            'northwest' => [-1, -1 ],
            'northeast' => [ 1, -1 ],
            'southwest' => [-1,  1 ],
            'southeast' => [ 1,  1 ],
        ];

        // Если направление некорректно, сразу 0
        if (!isset($directionVectors[$dir])) {
            return 0;
        }
        [$dx, $dy] = $directionVectors[$dir];

        // Если стартовой ячейки нет в массиве — 0
        if (!isset($mapCellsById[$startCellId])) {
            return 0;
        }

        // Проверим, исследована ли сама стартовая клетка
        // Если да — начинаем с 1, иначе с 0.
        $count = isset($exploredMap[$startCellId]) ? 1 : 0;

        // Координаты стартовой ячейки
        $cx = $mapCellsById[$startCellId]['coordinate_x'];
        $cy = $mapCellsById[$startCellId]['coordinate_y'];

        // Готовим удобный массив "коорд => cellId", чтобы быстро находить ячейку
        // по (x,y) без дополнительных запросов.
        $mapCellsByCoord = [];
        foreach ($mapCellsById as $cid => $cell) {
            $xx = $cell['coordinate_x'];
            $yy = $cell['coordinate_y'];
            $mapCellsByCoord["{$xx}_{$yy}"] = $cid;
        }

        // Делаем максимум 10 шагов в данном направлении
        for ($i = 0; $i < 10; $i++) {
            $cx += $dx;
            $cy += $dy;
            $key = "{$cx}_{$cy}";

            // Если такой ячейки вообще нет в загруженном наборе
            if (!isset($mapCellsByCoord[$key])) {
                break;
            }

            $cid = $mapCellsByCoord[$key];
            // Если эта клетка исследована, прибавим к счётчику
            if (isset($exploredMap[$cid])) {
                $count++;
            } else {
                // Как только упираемся в неисследованную — можно прервать,
                // если по вашей логике дальше тоже идти смысла нет.
                // break;
            }
        }

        return $count;
    }
}
