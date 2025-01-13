<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Components;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\ActiveEventModel;
use App\Models\CharacterResourceModel;
use App\Models\EventModel;
use App\Models\TaskModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class CharcoalBriquettesActionStart extends BaseAction
{
    protected $taskModel;
    protected $eventModel;
    protected $activeEventModel;
    protected $characterResourceModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->taskModel             = new TaskModel();
        $this->eventModel            = new EventModel();
        $this->activeEventModel      = new ActiveEventModel();
        $this->characterResourceModel= new CharacterResourceModel();
    }

    public function handle(): ServerResponse
    {
        // 1. Получаем пользователя / персонажа
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendError('Пользователь не найден в базе данных или персонаж не определён.');
        }

        // 2. Ищем задачу «craftCharcoalBriquettes» в таблице tasks
        $craftTask = $this->taskModel->where('name', 'craftCharcoalBriquettes')->first();
        if (!$craftTask) {
            return $this->sendError('Задача "Крафт Угольные брикеты" не найдена в базе данных.');
        }

        // 3. Проверяем, нет ли уже активной задачи этого крафта
        $activeTask = $this->characterTaskModel
            ->where('character_id', $character['id'])
            ->where('task_id', $craftTask['id'])
            ->where('status', 'in_work')
            ->first();

        if ($activeTask) {
            // Если такая задача есть — выходим без списания ресурсов
            return $this->sendError(
                "Извини, но ты не многорукий. "
                . "У тебя уже идёт крафт \"Угольные брикеты\". Дождись завершения!"
            );
        }

        // 4. Проверяем и списываем ресурсы
        if (!$this->checkAndDeductResources($character['id'])) {
            return $this->sendError('Недостаточно ресурсов для крафта: ничего не списано.');
        }

        // 5. Стартуем процесс крафта
        return $this->startCraftingProcess($character, $user['id'], $craftTask);
    }

    /**
     * Проверяет наличие нужных ресурсов у персонажа и, если хватает, списывает.
     */
    private function checkAndDeductResources(int $characterId): bool
    {
        $requiredResources = [
            'Древесина'       => 10,
            'Глина'           => 2,
            'Вода'            => 2,
            'Угольная порода' => 20,
        ];

        // Сначала проверяем, хватает ли
        foreach ($requiredResources as $resourceName => $amountNeeded) {
            $resource = $this->characterResourceModel->getResourceByNameAndCharacterId($resourceName, $characterId);
            if (!$resource || $resource['quantity'] < $amountNeeded) {
                // Как минимум одного ресурса не хватает
                return false;
            }
        }

        // Теперь списываем (раз ресурсов точно хватает)
        foreach ($requiredResources as $resourceName => $amountNeeded) {
            $resource = $this->characterResourceModel
                ->getResourceByNameAndCharacterId($resourceName, $characterId);

            // Важно: $resource['id'] — это id в character_resources, а не в таблице resources
            $this->characterResourceModel->update($resource['id'], [
                'quantity' => $resource['quantity'] - $amountNeeded,
            ]);
        }

        return true;
    }

    /**
     * Создаём запись в character_tasks и шлём сообщение о старте крафта.
     */
    private function startCraftingProcess(array $character, int $userId, array $craftTask): ServerResponse
    {
        // Считаем время крафта
        $duration = $this->calculateCraftingDuration($character, $craftTask);

        $startTime = new \DateTime();
        $endTime   = (clone $startTime)->add(new \DateInterval('PT' . $duration . 'M'));

        // Создаём запись о задаче
        $this->characterTaskModel->insert([
            'character_id'     => $character['id'],
            'telegram_user_id' => $userId,
            'task_id'          => $craftTask['id'],
            'start_time'       => $startTime->format('Y-m-d H:i:s'),
            'end_time'         => $endTime->format('Y-m-d H:i:s'),
            'status'           => 'in_work',
        ]);

        return $this->notifyCraftStarted($character, $startTime, $endTime);
    }

    /**
     * Пример упрощённого расчета времени крафта.
     */
    private function calculateCraftingDuration(array $character, array $craftTask): int
    {
        // Примерная логика — интуитивно:
        $minDuration = $craftTask['min_duration'] ?? 5;
        $maxDuration = $craftTask['max_duration'] ?? 15;

        // Допустим, чем выше интеллект, тем короче
        $intellect = $character['intellect'] ?? 0;
        $factor = 1 - min(1.0, $intellect / 200.0);

        $time = $maxDuration - ($maxDuration - $minDuration) * (1 - $factor);

        return (int) max($minDuration, min($maxDuration, round($time)));
    }

    /**
     * Отправляем сообщение, что крафт запущен.
     */
    private function notifyCraftStarted(array $character, \DateTime $startTime, \DateTime $endTime): ServerResponse
    {
        $interval = $startTime->diff($endTime);
        $minutes  = $interval->days * 1440 + $interval->h * 60 + $interval->i;

        $text = "*Процесс крафта запущен*\n\n"
            . "*Ты создаёшь: 🪨 Угольные брикеты!*\n\n"
            . "__*Время крафта: {$minutes} минут.*__ ⏱️\n\n"
            . "*О готовности ты узнаешь в сообщении.* 🎁\n\n"
            . "P.S. _Не забудь поделиться своими находками!_ 🗣️\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🛠️ Крафт',    'callback_data' => 'crafting']
                ],
            ]
        ];

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        $imagePath = base_url('uploads/telegram/craft/huge_mechanical_workbench.jpg');

        return Request::sendPhoto([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Метод для отправки ошибки.
     */
    private function sendError(string $msg): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'    => $msg,
        ]);
    }
}
