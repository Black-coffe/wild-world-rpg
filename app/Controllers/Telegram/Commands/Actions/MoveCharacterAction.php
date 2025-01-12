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

    public function __construct(CallbackQuery $callbackQuery)
    {
        $this->callbackQuery = $callbackQuery;
        $this->characterModel = new CharacterModel();
        $this->mapModel = new MapModel();
        $this->characterTaskModel = new CharacterTaskModel();
        $this->taskModel = new TaskModel();
        $this->telegramUserModel = new TelegramUserModel();
        $this->exploredCellsModel = new ExploredCellsModel();
        $this->biomeModel = new BiomeModel();
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        $telegramUserId = $this->callbackQuery->getFrom()->getId();

        $user = $this->telegramUserModel->where('telegram_id', $telegramUserId)->first();
        if (!$user) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Пользователь не найден в базе данных.']);
        }

        // Получение персонажа пользователя
        $character = $this->characterModel->where('telegram_user_id', $user['id'])->first();
        if (!$character || !$character['cell_number']) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Персонаж не найден или не имеет локации.',
            ]);
        }

        // Проверка на здоровье и усталость
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
                'chat_id' => $chatId,
                'photo'   => Request::encodeFile($imagePath),
                'caption' => $text,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        }

        // Получаем активные задачи персонажа
        $activeTasks = $this->characterTaskModel
            ->where('character_id', $character['id'])
            ->where('telegram_user_id', $user['id'])
            ->where('status', 'in_work')
            ->findAll();

        // Проверка на возможность выполнения параллельных задач
        $nonParallelTasks = [];
        foreach ($activeTasks as $task) {
            $taskDetails = $this->taskModel->find($task['task_id']);
            if ($taskDetails['parallel_execution_allowed'] == 0) {
                $nonParallelTasks[] = $task;
            }
        }

        // Если есть задачи, которые не допускают параллельное выполнение, отправляем сообщение с уведомлением
        if (!empty($nonParallelTasks)) {
            $task = $nonParallelTasks[0];
            $taskDetails = $this->taskModel->find($task['task_id']);
            $endTime = new \DateTime($task['end_time']);
            $now = new \DateTime();
            $timeLeft = $now > $endTime ? 0 : $now->diff($endTime);
            $minutesLeft = $now > $endTime ? 0 : ($timeLeft->days * 24 * 60 + $timeLeft->h * 60 + $timeLeft->i);
            $timeLeftText = $now > $endTime ? "00" : $minutesLeft;

            $text = "*Ой! Не получается начать переезд!* 😥\n\n"
                . "*Вы заняты выполнением другой задачи: *\n\n"
                . "👉 *{$taskDetails['name_rus']}* 👈\n"
                . "⌛️ До конца еще: *{$timeLeftText}* минут!\n\n"
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
                'chat_id' => $chatId,
                'text'    => $text,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard)
            ]);
        }

        // Проверка и переход на новую локацию здесь

        $currentCell = $this->mapModel->where('cell_number', $character['cell_number'])->first();
        if (!$currentCell) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Локация персонажа не найдена.']);
        }

        $surroundingCellsInfo = $this->getSurroundingCellsInfo($currentCell['coordinate_x'], $currentCell['coordinate_y'], $character['id']);
        $keyboardButtons = $this->generateKeyboard($surroundingCellsInfo);

        if (empty($keyboardButtons)) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Нет доступных направлений для переезда. Возможно, стоит исследовать окрестности?']);
        }

        $keyboard = ['inline_keyboard' => $keyboardButtons];
        $text = "👋 *Привет, герой!* 🙋‍♂️\n\n"
            . "🚜 *Ты готов к переезду?* 🏡\n\n"
            . "🗺️ *Выбери новую ячейку, которую ты исследовал*\n\n"
            . "👀 *Будь внимателен и осторожен!*\n\n"
            . "🍀 *Удачи в пути!* 🍀\n\n";

        $imagePath = base_url('uploads/telegram/moves_to_another_territory.png');

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendPhoto([
            'chat_id' => $chatId,
            'photo'   => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }


    private function generateKeyboard($surroundingCellsInfo)
    {
        $keyboard = [];
        $directions = [
            ['northwest' => '↖️ Северо-запад', 'northeast' => '↗️ Северо-восток'],
            ['north' => '⬆️ Север', 'south' => '⬇️ Юг'],
            ['west' => '⬅️ Запад', 'east' => '➡️ Восток'],
            ['southwest' => '↙️ Юго-запад', 'southeast' => '↘️ Юго-восток'],
        ];

        foreach ($directions as $pair) {
            $row = [];
            foreach ($pair as $dir => $label) {
                if (isset($surroundingCellsInfo[$dir])) {
                    $row[] = ['text' => $label, 'callback_data' => 'moveto' . $dir];
                }
            }
            if (!empty($row)) {
                $keyboard[] = $row;
            }
        }

        return $keyboard;
    }

    private function getSurroundingCellsInfo($x, $y, $characterId)
    {
        $neighboringPositions = [
            'northwest' => [$x - 1, $y - 1],
            'north' => [$x, $y - 1],
            'northeast' => [$x + 1, $y - 1],
            'west' => [$x - 1, $y],
            'east' => [$x + 1, $y],
            'southwest' => [$x - 1, $y + 1],
            'south' => [$x, $y + 1],
            'southeast' => [$x + 1, $y + 1],
        ];

        $surroundingCellsInfo = [];
        foreach ($neighboringPositions as $direction => $coords) {
            $cell = $this->mapModel->where('coordinate_x', $coords[0])->where('coordinate_y', $coords[1])->first();
            if ($cell) {
                $explored = $this->exploredCellsModel->where('character_id', $characterId)->where('map_cell_id', $cell['id'])->first();
                if ($explored) {
                    $biome = $this->biomeModel->find($cell['biome_id']);
                    $surroundingCellsInfo[$direction] = [
                        'cell_number' => $cell['id'],
                        'biome_name' => $biome ? $biome['name'] : 'Unknown',
                        'direction' => $direction,
                    ];
                }
            }
        }

        return $surroundingCellsInfo;
    }

}
