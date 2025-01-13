<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Components;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\ActiveEventModel;
use App\Models\CharacterResourceModel;
use App\Models\EventModel;
use App\Models\TaskModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class FabricCraftActionStart extends BaseAction
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
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendError('Пользователь не найден в базе данных или персонаж не определён.');
        }

        // 1) Ищем задачу "craftFabric" в базе
        $craftTask = $this->taskModel->where('name', 'craftFabric')->first();
        if (!$craftTask) {
            return $this->sendError('Задача "Крафт Ткань (craftFabric)" не найдена в базе данных.');
        }

        // 2) Проверяем, нет ли уже активной задачи крафта "craftFabric" у персонажа
        $activeTask = $this->characterTaskModel
            ->where('character_id', $character['id'])
            ->where('task_id', $craftTask['id'])
            ->where('status', 'in_work')
            ->first();

        if ($activeTask) {
            // Уже идёт крафт этого предмета — выходим без списания ресурсов
            return $this->sendError(
                "Извини, но у тебя уже идёт крафт ткани. "
                . "Дождись завершения, прежде чем начинать новый!"
            );
        }

        // 3) Теперь проверяем ресурсы, если их хватает — списываем
        if (!$this->checkAndDeductResources($character['id'])) {
            return $this->sendError('Недостаточно ресурсов для крафта: ничего не списано.');
        }

        // 4) Запускаем процесс крафта
        return $this->startCraftingProcess($character, $user['id'], $craftTask);
    }

    /**
     * Проверяет наличие нужных ресурсов у персонажа и, если хватает, списывает.
     */
    private function checkAndDeductResources(int $characterId): bool
    {
        $requiredResources = [
            'Шерсть животных'           => 10,
            'Шёлк пауков-пустынников'   => 1,
            'Текстильные культуры'      => 10,
        ];

        // Сначала убеждаемся, что всего хватает
        foreach ($requiredResources as $resourceName => $requiredAmount) {
            $resource = $this->characterResourceModel->getResourceByNameAndCharacterId($resourceName, $characterId);
            if (!$resource || $resource['quantity'] < $requiredAmount) {
                return false; // Не хватает хотя бы одного ресурса
            }
        }

        // Теперь, когда точно всего хватает, списываем
        foreach ($requiredResources as $resourceName => $requiredAmount) {
            $resource = $this->characterResourceModel->getResourceByNameAndCharacterId($resourceName, $characterId);

            // В character_resources primary key — поле `id`
            $this->characterResourceModel->update($resource['id'], [
                'quantity' => $resource['quantity'] - $requiredAmount,
            ]);
        }

        return true;
    }

    /**
     * Создаём запись в character_tasks и шлём сообщение о старте крафта.
     */
    private function startCraftingProcess(array $character, int $userId, array $craftTask): ServerResponse
    {
        $duration = $this->calculateCraftingDuration($character, $craftTask);

        $startTime = new \DateTime();
        $endTime   = (clone $startTime)->add(new \DateInterval('PT' . $duration . 'M'));

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
     * Упрощённый расчёт времени крафта.
     */
    private function calculateCraftingDuration(array $character, array $craftTask): int
    {
        // Извлекаем из task
        $minDuration = $craftTask['min_duration'] ?? 5;
        $maxDuration = $craftTask['max_duration'] ?? 15;

        // Пусть влияет интеллект, ловкость и т.д. — примерные логики
        $experience = $character['experience'] ?? 0;
        $agility    = $character['agility']    ?? 0;
        $intellect  = $character['intellect']  ?? 0;

        // Коэффициенты
        $expFactor = 0.3;
        $agiFactor = 0.3;
        $intFactor = 0.4;

        $attributeScore = ($experience * $expFactor) + ($agility * $agiFactor) + ($intellect * $intFactor);
        $maxAttributeScore = 1000 * ($expFactor + $agiFactor + $intFactor);

        $normalized = $attributeScore / $maxAttributeScore;
        if ($normalized > 1) {
            $normalized = 1;
        }

        // Чем выше нормализованный скор, тем меньше времени
        $duration = $minDuration + ($maxDuration - $minDuration) * (1 - $normalized);

        return (int) max($minDuration, min($maxDuration, round($duration)));
    }

    /**
     * Сообщаем, что крафт запущен.
     */
    private function notifyCraftStarted(array $character, \DateTime $startTime, \DateTime $endTime): ServerResponse
    {
        $interval = $startTime->diff($endTime);
        $minutes  = $interval->days * 1440 + $interval->h * 60 + $interval->i;

        $text = "*Процесс крафта запущен*\n\n"
            . "*Ты создаёшь: 🧵 Ткань*\n\n"
            . "__*Время крафта: {$minutes} минут.*__ ⏱️\n\n"
            . "*О готовности ты узнаешь в сообщении.* 🎁\n\n"
            . "P.S. _Не забудь поделиться своими находками!_ 🗣️\n";

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        $imagePath = base_url('uploads/telegram/craft/huge_mechanical_workbench.jpg');

        return Request::sendPhoto([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    /**
     * Сообщение об ошибке с ответом на CallbackQuery.
     */
    private function sendError(string $message): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'    => $message,
        ]);
    }
}
