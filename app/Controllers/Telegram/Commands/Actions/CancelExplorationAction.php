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
use DateTime;

class CancelExplorationAction
{
    protected $callbackQuery;
    protected $characterTaskModel;
    protected $characterModel;
    protected $mapModel;
    protected $biomeModel;
    protected $exploredCellsModel;

    public function __construct(CallbackQuery $callbackQuery)
    {
        $this->callbackQuery = $callbackQuery;
        $this->characterTaskModel = new CharacterTaskModel();
        $this->characterModel = new CharacterModel();
        $this->mapModel = new MapModel();
        $this->biomeModel = new BiomeModel();
        $this->exploredCellsModel = new ExploredCellsModel();
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

        $task = $this->characterTaskModel
            ->where('character_id', $character['id'])
            ->where('status', 'in_work')
            ->first();

        if (!$task) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Задача изучения местности не найдена или уже завершена.',
                'parse_mode' => 'Markdown',
            ]);
        }

        $this->characterTaskModel->update($task['id'], ['status' => 'interrupted']);

        $startTime = new DateTime($task['start_time']);
        $now = new DateTime();
        $interval = $now->diff($startTime);
        $minutesPassed = ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;

        // Если время выполнения задачи больше 5 минут, то обрабатываем результаты исследования
        if ($minutesPassed >= 5) {
            $currentCell = $this->mapModel->where('cell_number', $character['cell_number'])->first();
            $surroundingCells = $this->getSurroundingCells($currentCell, $minutesPassed);
            // Запись в базу данных информации о изученных ячейках
            foreach ($surroundingCells as $cell) {
                // Проверяем, существует ли уже запись для данной ячейки и персонажа
                $query = $this->exploredCellsModel->where('character_id', $character['id'])
                    ->where('map_cell_id', $cell['cell_number'])
                    ->get();

                // Если запись не найдена, то вставляем новую
                if ($query->getNumRows() === 0) {
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
            // Иначе, если исследование было прервано раньше времени, не записываем результаты и отправляем соответствующее сообщение
            $text = $this->formatResultMessage([], false);
            // Обновляем характеристики персонажа в минус 0.01
            $this->characterModel->update($character['id'], [
                'experience' => $character['experience'] - 0.01,]);
        }

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions']
                ],
                [
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ['text' => '🎉 События', 'callback_data' => 'events']
                ]
            ]
        ];
        $encodedKeyboard = json_encode($keyboard);

        $imagePath = base_url('uploads/telegram/character_rushes_back_to_his_base.png'); // Укажите актуальный путь к изображению

        // Ответ на колбек-запрос и отправка сообщения
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
        ]);

        // Отправляем ответное сообщение пользователю
        return Request::sendPhoto([
            'chat_id' => $chatId,
            'photo'   => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => $encodedKeyboard
        ]);
    }

    private function getSurroundingCells($currentCell, $minutesPassed) {
        $cellsToExplore = min(floor($minutesPassed / 5) * 2, 8); // Расчет количества изученных ячеек
        $x = $currentCell['coordinate_x'];
        $y = $currentCell['coordinate_y'];

        // Определение координат соседних ячеек
        $neighboringPositions = [
            ['x' => $x - 1, 'y' => $y - 1], // Северо-запад
            ['x' => $x,     'y' => $y - 1], // Север
            ['x' => $x + 1, 'y' => $y - 1], // Северо-восток
            ['x' => $x - 1, 'y' => $y],     // Запад
            ['x' => $x + 1, 'y' => $y],     // Восток
            ['x' => $x - 1, 'y' => $y + 1], // Юго-запад
            ['x' => $x,     'y' => $y + 1], // Юг
            ['x' => $x + 1, 'y' => $y + 1], // Юго-восток
        ];

        $surroundingCellsInfo = [];

        for ($i = 0; $i < $cellsToExplore; $i++) {
            if (!isset($neighboringPositions[$i])) break;
            $position = $neighboringPositions[$i];

            $cell = $this->mapModel->where('coordinate_x', $position['x'])
                ->where('coordinate_y', $position['y'])
                ->first();

            if ($cell) {
                $biome = $this->biomeModel->find($cell['biome_id']);
                $surroundingCellsInfo[] = [
                    'cell_number' => $cell['cell_number'],
                    'biome_id' => $cell['biome_id'],
                ];
            }
        }

        return $surroundingCellsInfo;
    }

    protected function formatResultMessage($exploredCells, $explorationCompleted) {

        $messageText = "*Пришлось прерваться!* 😥\n\n";
        $messageText .= "Неотложные дела заставила меня 🏃‍♂️ домой.\n\n";

        // Добавление информации об изменении характеристик персонажа
        $messageText .= "\n😠😠😠\n\n";
        $messageText .= "💪 *Но я не сдаюсь!*\n";
        $messageText .= "*Завтра я вернусь и продолжу свое дело!🤓*\n\n";
        $messageText .= "*❤️ Сегодня я вернулся домой живым, а это уже победа!*\n\n";
        $messageText .= "*Не вешай нос! 💪😜*\n\n";

        return $messageText;
    }

}
