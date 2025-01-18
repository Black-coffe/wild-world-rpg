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
 * Класс, отвечающий за отображение возможностей переезда персонажа
 * и переход в соседние (исследованные) локации.
 *
 * Логика: этот Action вызывается при нажатии кнопки "move" (callback_data = "move")
 * и выводит список направлений. Но сам переезд теперь обрабатывается
 * в MoveCharacterToDirectionAction.php через callback_data = "move_dir_{direction}".
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

    // Радиус "обзора" (какие ячейки можно увидеть)
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
            $text = "🚑 *Ваш персонаж не выдержит больше нагрузок.*\n\n"
                . "*Необходимо восстановить здоровье или выносливость*\n\n"
                . "*💖 Здоровье: {$character['health']}%*\n"
                . "*🥱 Выносливость: {$character['tired']}%*\n\n"
                . "Отлежитесь или используйте аптечку";

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
            $task        = $nonParallelTasks[0];
            $taskDetails = $this->taskModel->find($task['task_id']);

            $endTime     = new \DateTime($task['end_time']);
            $now         = new \DateTime();
            $timeLeft    = $now > $endTime ? 0 : $now->diff($endTime);
            $minutesLeft = $now > $endTime ? 0 : ($timeLeft->days * 24 * 60 + $timeLeft->h * 60 + $timeLeft->i);
            $timeLeftText = $now > $endTime ? "00" : $minutesLeft;

            $text = "*Ой! Вы заняты другой задачей:* \n\n"
                . "👉 *{$taskDetails['name_rus']}* \n"
                . "⌛️ Осталось: *{$timeLeftText}* мин.\n"
                . "**Дождитесь окончания задачи, прежде чем переехать.**\n";

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

        // 5. Формируем меню доступных направлений
        $directionsKeyboard = $this->buildDirectionsKeyboard();

        if (empty($directionsKeyboard)) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Нет доступных направлений для переезда. Возможно, стоит исследовать окрестности?'
            ]);
        }

        $keyboard = ['inline_keyboard' => $directionsKeyboard];

        $text = "🚜 *Куда переедем?*\n\n"
            . "Выберите направление для перехода.\n"
            . "(Учтите, что для перемещения требуется достаточно здоровья и выносливости.)\n";

        $imagePath = base_url('uploads/telegram/moves_to_another_territory.png');

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendPhoto([
            'chat_id'      => $chatId,
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Генерирует inline-кнопки для 8 направлений: север, юг, запад, восток,
     * северо-запад, северо-восток, юго-запад, юго-восток.
     * И callback_data будет в формате "move_dir_north" и т.д.
     */
    private function buildDirectionsKeyboard(): array
    {
        // Можно сформировать кнопки в виде массива
        // Каждый "ряд" будет отображать 1-2 кнопки.
        // Пример: первую строку (север, ...), вторую строку (запад, восток) и т.д.

        return [
            [
                ['text' => '↖️ Сев-Запад', 'callback_data' => 'move_dir_northwest'],
                ['text' => '⬆️ Север',     'callback_data' => 'move_dir_north'],
                ['text' => '↗️ Сев-Восток','callback_data' => 'move_dir_northeast'],
            ],
            [
                ['text' => '⬅️ Запад',     'callback_data' => 'move_dir_west'],
                ['text' => '➡️ Восток',    'callback_data' => 'move_dir_east'],
            ],
            [
                ['text' => '↙️ Юго-Запад', 'callback_data' => 'move_dir_southwest'],
                ['text' => '⬇️ Юг',       'callback_data' => 'move_dir_south'],
                ['text' => '↘️ Юго-Восток','callback_data' => 'move_dir_southeast'],
            ],
        ];
    }
}
