<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Components;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\ActiveEventModel;
use App\Models\CharacterResourceModel;
use App\Models\EventModel;
use App\Models\TaskModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class FertilizerCraftActionStart extends BaseAction
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

        // 1) Ищем задачу "craftFertilizer"
        $craftTask = $this->taskModel->where('name', 'craftFertilizer')->first();
        if (!$craftTask) {
            return $this->sendError('Задача "Крафт удобрений" не найдена в базе данных.');
        }

        // 2) Проверяем, нет ли уже активной задачи этого типа у персонажа
        $activeTask = $this->characterTaskModel
            ->where('character_id', $character['id'])
            ->where('task_id', $craftTask['id'])
            ->where('status', 'in_work')
            ->first();

        if ($activeTask) {
            // Уже идёт крафт
            return $this->sendError(
                "Извини, но у тебя уже выполняется крафт удобрений. "
                . "Дождись завершения, прежде чем начинать новый!"
            );
        }

        // 3) Проверка ресурсов и их списание
        if (!$this->checkAndDeductResources($character['id'])) {
            return $this->sendError('Недостаточно ресурсов для крафта: ничего не списано.');
        }

        // 4) Всё в порядке — запускаем крафт
        return $this->startCraftingProcess($character, $user['id'], $craftTask);
    }

    /**
     * Проверяем ресурсы и списываем только если точно всего хватает.
     */
    private function checkAndDeductResources(int $characterId): bool
    {
        $requiredResources = [
            'Кости животных' => 1,
            'Вода'          => 5,
            'Водоросли'     => 20,
            'Ил'            => 10,
        ];

        // Сначала убеждаемся, что всего хватает
        foreach ($requiredResources as $resourceName => $requiredAmount) {
            $resource = $this->characterResourceModel->getResourceByNameAndCharacterId($resourceName, $characterId);
            if (!$resource || $resource['quantity'] < $requiredAmount) {
                return false;
            }
        }

        // Затем списываем
        foreach ($requiredResources as $resourceName => $requiredAmount) {
            $resource = $this->characterResourceModel->getResourceByNameAndCharacterId($resourceName, $characterId);
            $this->characterResourceModel->update($resource['id'], [
                'quantity' => $resource['quantity'] - $requiredAmount,
            ]);
        }

        return true;
    }

    /**
     * Создаём задачу в character_tasks.
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
     * Примерный расчёт времени крафта.
     */
    private function calculateCraftingDuration(array $character, array $craftTask): int
    {
        $minDuration = $craftTask['min_duration'] ?? 5;
        $maxDuration = $craftTask['max_duration'] ?? 15;

        $experience = $character['experience'] ?? 0;
        $agility    = $character['agility']    ?? 0;
        $intellect  = $character['intellect']  ?? 0;

        $expFactor = 0.3;
        $agiFactor = 0.3;
        $intFactor = 0.4;

        $attributeScore = ($experience * $expFactor) + ($agility * $agiFactor) + ($intellect * $intFactor);
        $maxAttributeScore = 1000 * ($expFactor + $agiFactor + $intFactor);

        $normalized = $attributeScore / $maxAttributeScore;
        if ($normalized > 1) {
            $normalized = 1;
        }

        $duration = $minDuration + ($maxDuration - $minDuration) * (1 - $normalized);
        return (int) max($minDuration, min($maxDuration, round($duration)));
    }

    /**
     * Сообщение об успехе.
     */
    private function notifyCraftStarted(array $character, \DateTime $startTime, \DateTime $endTime): ServerResponse
    {
        $interval = $startTime->diff($endTime);
        $minutes  = $interval->days * 1440 + $interval->h * 60 + $interval->i;

        $text = "*Процесс крафта запущен*\n\n"
            . "*Ты создаешь: 🌿 Удобрение*\n\n"
            . "__*Время крафта: {$minutes} минут.*__ ⏱️\n\n"
            . "*О готовности ты узнаешь в сообщении.* 🎁\n\n"
            . "P.S. _Не забудь поделиться своими находками!_ 🗣️\n";

        // Можно и клавиатуру добавить при необходимости
        $imagePath = base_url('uploads/telegram/craft/huge_mechanical_workbench.jpg');
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendPhoto([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    /**
     * Сообщение об ошибке.
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
