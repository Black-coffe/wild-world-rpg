<?php

namespace App\Controllers\Telegram\Commands\Actions;

use Longman\TelegramBot\Entities\CallbackQuery;
use App\Models\CharacterModel;
use App\Models\ResourceModel;
use App\Models\TelegramUserModel;
use App\Models\CharacterTaskModel;
use App\Models\TaskModel;

abstract class BaseAction
{
    protected $callbackQuery;
    protected $characterModel;
    protected $resourceModel;
    protected $telegramUserModel;
    protected $characterTaskModel;
    protected $taskModel;
    protected $blockParallelExecution = false;
    protected $blockingTaskDetails = null;

    public function __construct(CallbackQuery $callbackQuery)
    {
        $this->callbackQuery = $callbackQuery;
        $this->characterModel = new CharacterModel();
        $this->resourceModel = new ResourceModel();
        $this->telegramUserModel = new TelegramUserModel();
        $this->characterTaskModel = new CharacterTaskModel();
        $this->taskModel = new TaskModel();
    }

    protected function getUserAndCharacter()
    {
        $userId = $this->callbackQuery->getFrom()->getId();
        $user = $this->telegramUserModel->where('telegram_id', $userId)->first();

        if (!$user) {
            return [null, null];
        }

        $character = $this->characterModel->where('telegram_user_id', $user['id'])->first();

        return [$user, $character];
    }

    protected function checkActiveTasks($characterId)
    {
        return $this->characterTaskModel
            ->where('character_id', $characterId)
            ->where('status', 'in_work')
            ->findAll();
    }

    protected function calculateDurationReduction($character)
    {
        // Начальное уменьшение продолжительности в минутах
        $reduction = 0;

        // Добавляем уменьшение продолжительности на основе уровня и характеристик персонажа
        // Это пример формулы, которую можно настроить под нужды игры
        $reduction += $character['level'] * 0.5; // Каждый уровень уменьшает на 0.5 минуты
        $reduction += $character['experience'] * 0.1;
        $reduction += ($character['health'] / 100) * 0.2; // Каждые 100 единиц здоровья уменьшают на 0.2 минуты
        $reduction += $character['strength'] * 0.1;
        $reduction += $character['agility'] * 0.1;
        $reduction += $character['intellect'] * 0.1;

        // Округляем уменьшение продолжительности до ближайшего целого числа
        return round($reduction);
    }

    protected function checkParallelExecutionAllowed($characterId)
    {
        $activeTasks = $this->characterTaskModel
            ->where('character_id', $characterId)
            ->where('status', 'in_work')
            ->join('tasks', 'tasks.id = character_tasks.task_id')
            ->findAll();

        foreach ($activeTasks as $task) {
            if (!$task['parallel_execution_allowed']) {
                $this->blockParallelExecution = true;
                // Важно убедиться, что $task содержит 'end_time' из character_tasks
                $this->blockingTaskDetails = $task;
                return false;
            }
        }

        return true;
    }

    protected function prepareBlockedTaskResponse($callbackDataCancel)
    {
        $endTime = new \DateTime($this->blockingTaskDetails['end_time']);
        $now = new \DateTime();
        $timeLeft = $now > $endTime ? 0 : $now->diff($endTime);
        $minutesLeft = $now > $endTime ? 0 : ($timeLeft->days * 24 * 60 + $timeLeft->h * 60 + $timeLeft->i);

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '❌ Прервать действие', 'callback_data' => $callbackDataCancel]
                ],
            ]
        ];

        $text = "*Вы не можете начать действие!* 😥\n\n"
            . "*Вы уже заняты выполнением задачи:*\n\n"
            . "👉 *{$this->blockingTaskDetails['name_rus']}* 👈\n"
            . "⌛️ До конца еще: *{$minutesLeft}* минут!\n\n"
            . "**😔 Пожалуйста, завершите текущую задачу или дождитесь окончания, прежде чем начинать новую.**\n\n"
            . "*P.S.*\n\n"
            . "💡 Вы можете посмотреть список активных задач, используя команду➡️ /tasks\n\n";


        return [
            'text' => $text,
            'reply_markup' => json_encode($keyboard)
        ];
    }

    /**
     * Получает массив ID биомов из строки.
     *
     * @param string $biomeIdsString Строка, содержащая ID биомов, разделенных запятыми.
     * @return array Массив ID биомов.
     */
    protected function getBiomeIdsFromString($biomeIdsString)
    {
        // Убираем пробелы вокруг запятых и разделяем строку на массив по запятым
        $biomeIdsArray = explode(',', str_replace(' ', '', $biomeIdsString));

        // Преобразуем все элементы массива в целые числа и фильтруем пустые значения
        $biomeIdsArray = array_filter(array_map('intval', $biomeIdsArray));

        return $biomeIdsArray;
    }

    protected function checkForBlockingTasks()
    {
        $isAllowed = $this->checkParallelExecutionAllowed($this->character['id']);
        if (!$isAllowed) {
            return [
                'success' => false,
                'response' => $this->prepareBlockedTaskResponse('cancelCurrentTask')
            ];
        }
        return ['success' => true];
    }

    public function cancelCurrentTask()
    {
        // Получаем ID текущей активной задачи, если она есть
        $activeTask = $this->characterTaskModel
            ->where('character_id', $this->character['id'])
            ->where('status', 'in_work')
            ->first();

        if ($activeTask) {
            // Обновляем статус задачи на 'cancelled'
            $this->characterTaskModel->update($activeTask['id'], ['status' => 'cancelled']);

            // Возвращаем сообщение об успешном прерывании
            return [
                'text' => "Задача '{$activeTask['name']}' была успешно прервана.",
                'reply_markup' => json_encode(['inline_keyboard' => $this->getDefaultKeyboard()])
            ];
        } else {
            // Если активных задач не найдено, сообщаем об этом
            return [
                'text' => "Активных задач на прерывание не найдено.",
                'reply_markup' => json_encode(['inline_keyboard' => $this->getDefaultKeyboard()])
            ];
        }
    }

    /**
     * Обновляет цены всех ресурсов с учетом текущего спроса и предложения.
     */
    protected function updateResourcePrices()
    {
        $this->resourceModel->updateResourcePrices();
    }


    abstract public function handle();
}
