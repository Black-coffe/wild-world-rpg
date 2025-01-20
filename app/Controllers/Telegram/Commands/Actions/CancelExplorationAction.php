<?php

namespace App\Controllers\Telegram\Commands\Actions;

use App\Models\TelegramUserModel;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Models\CharacterTaskModel;
use App\Models\CharacterModel;
use App\Models\ExploredCellsModel;
use App\Models\MapModel;
use App\Models\BiomeModel;
use App\Models\TaskModel;  // <-- ВАЖНО: подключаем TaskModel
use DateTime;

class CancelExplorationAction
{
    protected $callbackQuery;
    protected $characterTaskModel;
    protected $characterModel;
    protected $mapModel;
    protected $biomeModel;
    protected $exploredCellsModel;
    protected $taskModel; // <-- ВАЖНО: свойство для TaskModel

    public function __construct(CallbackQuery $callbackQuery)
    {
        $this->callbackQuery = $callbackQuery;
        $this->characterTaskModel = new CharacterTaskModel();
        $this->characterModel = new CharacterModel();
        $this->mapModel = new MapModel();
        $this->biomeModel = new BiomeModel();
        $this->exploredCellsModel = new ExploredCellsModel();
        $this->taskModel = new TaskModel(); // <-- ВАЖНО: инициализация TaskModel
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        $telegramUserId = $this->callbackQuery->getFrom()->getId();

        $telegramUserModel = new TelegramUserModel();
        $user = $telegramUserModel->where('telegram_id', $telegramUserId)->first();

        if (!$user) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Пользователь не найден в базе данных.',
            ]);
        }

        $character = $this->characterModel->where('telegram_user_id', $user['id'])->first();

        if (!$character || !$character['cell_number']) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Персонаж не найден или не имеет локации.',
            ]);
        }

        // 1. Ищем запись в таблице tasks, где name='ExploreTheArea'
        $exploreTask = $this->taskModel->where('name', 'ExploreTheArea')->first(); // <-- ВАЖНО

        if (!$exploreTask) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Задача ExploreTheArea не найдена в таблице tasks.',
            ]);
        }

        // 2. В character_tasks ищем конкретную строку для этого персонажа + task_id + статус 'in_work'
        $task = $this->characterTaskModel
            ->where('character_id', $character['id'])
            ->where('task_id', $exploreTask['id']) // <-- ВАЖНО: проверяем соответствие задачи
            ->where('status', 'in_work')
            ->first();

        if (!$task) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Задача изучения местности не найдена или уже завершена.',
                'parse_mode' => 'Markdown',
            ]);
        }

        // 3. Прерываем задачу: меняем статус на 'interrupted'
        $this->characterTaskModel->update($task['id'], ['status' => 'interrupted']);

        // Подсчёт, сколько времени прошло с момента начала
        $startTime = new DateTime($task['start_time']);
        $now = new DateTime();
        $interval = $now->diff($startTime);
        $minutesPassed = ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;

        // 4. Если прошло более 5 минут, даём частичную награду / записываем клетки
        if ($minutesPassed >= 5) {
            $currentCell = $this->mapModel->where('cell_number', $character['cell_number'])->first();
            $surroundingCells = $this->getSurroundingCells($currentCell, $minutesPassed);

            // Запись в таблицу explored_cells
            foreach ($surroundingCells as $cell) {
                $alreadyExplored = $this->exploredCellsModel
                    ->where('character_id', $character['id'])
                    ->where('map_cell_id', $cell['cell_number'])
                    ->countAllResults();

                if ($alreadyExplored == 0) {
                    $this->exploredCellsModel->insert([
                        'character_id' => $character['id'],
                        'telegram_user_id' => $task['telegram_user_id'],
                        'map_cell_id' => $cell['cell_number'],
                        'biome_id' => $cell['biome_id'],
                        'character_level' => $character['level'],
                    ]);
                }
            }
            $text = $this->formatResultMessage($surroundingCells, true);
        } else {
            // Если меньше 5 минут — ничего не открываем
            $text = $this->formatResultMessage([], false);
            // Штрафуем опыт (пример из вашего кода)
            $this->characterModel->update($character['id'], [
                'experience' => $character['experience'] - 0.01,
            ]);
        }

        // Клавиатура для возврата к действиям
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions']
                ],
                [
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ['text' => '🎉 События', 'callback_data' => 'events']
                ]
            ]
        ];
        $encodedKeyboard = json_encode($keyboard);

        $imagePath = base_url('uploads/telegram/character_rushes_back_to_his_base.png');

        // Ответ на колбэк и отправка фото со сообщением
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
        ]);

        return Request::sendPhoto([
            'chat_id' => $chatId,
            'photo'   => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => $encodedKeyboard
        ]);
    }

    private function getSurroundingCells($currentCell, $minutesPassed)
    {
        $cellsToExplore = min(floor($minutesPassed / 5) * 2, 8);
        $x = $currentCell['coordinate_x'];
        $y = $currentCell['coordinate_y'];

        $neighboringPositions = [
            ['x' => $x - 1, 'y' => $y - 1],
            ['x' => $x,     'y' => $y - 1],
            ['x' => $x + 1, 'y' => $y - 1],
            ['x' => $x - 1, 'y' => $y],
            ['x' => $x + 1, 'y' => $y],
            ['x' => $x - 1, 'y' => $y + 1],
            ['x' => $x,     'y' => $y + 1],
            ['x' => $x + 1, 'y' => $y + 1],
        ];

        $surroundingCellsInfo = [];

        for ($i = 0; $i < $cellsToExplore; $i++) {
            if (!isset($neighboringPositions[$i])) break;
            $position = $neighboringPositions[$i];

            $cell = $this->mapModel->where('coordinate_x', $position['x'])
                ->where('coordinate_y', $position['y'])
                ->first();

            if ($cell) {
                $surroundingCellsInfo[] = [
                    'cell_number' => $cell['cell_number'],
                    'biome_id'    => $cell['biome_id'],
                ];
            }
        }

        return $surroundingCellsInfo;
    }

    protected function formatResultMessage($exploredCells, $explorationCompleted)
    {
        $messageText = "*Пришлось прерваться!* 😥\n\n"
            . "Неотложные дела заставили меня 🏃‍♂️ вернуться.\n\n";

        if ($explorationCompleted && !empty($exploredCells)) {
            $messageText .= "Но я успел осмотреть несколько ячеек!\n";
            $messageText .= "При следующей вылазке продолжу исследовать остров. 🏝️\n\n";
        } else {
            $messageText .= "К сожалению, я почти ничего не успел разведать.\n\n";
            $messageText .= "Получил небольшой штраф к опыту, но главное — я жив! 👍\n\n";
        }

        $messageText .= "💪 *Не унывай!* Впереди нас ждут новые открытия!\n";
        return $messageText;
    }
}
