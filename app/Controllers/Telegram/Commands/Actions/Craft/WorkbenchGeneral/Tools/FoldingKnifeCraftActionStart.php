<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Tools;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\ActiveEventModel;
use App\Models\CharacterResourceModel;
use App\Models\EventModel;
use App\Models\TaskModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

// Модель для доступа к ресурсам персонажа

class FoldingKnifeCraftActionStart extends BaseAction
{
    protected $taskModel;
    protected $eventModel;
    protected $activeEventModel;
    protected $characterResourceModel; // Добавление свойства для модели

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->taskModel = new TaskModel();
        $this->eventModel = new EventModel();
        $this->activeEventModel = new ActiveEventModel();
        $this->characterResourceModel = new CharacterResourceModel(); // Инициализация модели
    }

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendError('Пользователь не найден в базе данных или персонаж не определён.');
        }

        // Проверка и списание необходимых ресурсов
        if (!$this->checkAndDeductResources($character['id'])) {
            return $this->sendError('Недостаточно ресурсов для крафта.');
        }

        // Запуск процесса крафта
        return $this->startCraftingProcess($character, $user['id']);
    }

    private function checkAndDeductResources($characterId): bool
    {
        $requiredResources = [
            'Древесина' => 2,
            'Железная руда' => 36,
            'Кожа животных' => 1,
            'Камни' => 2,
        ];

        foreach ($requiredResources as $resourceName => $requiredAmount) {
            $resource = $this->characterResourceModel->getResourceByNameAndCharacterId($resourceName, $characterId);
            if (!$resource || $resource['quantity'] < $requiredAmount) {
                return false; // Не хватает ресурсов
            }else{
                $item = $this->characterResourceModel->where('id_characters', $characterId)->where('id_resources', $resource['id'])->first();
                $this->characterResourceModel->update($item['id'], ['quantity' => $item['quantity'] - $requiredAmount]);
            }
        }

        return true;
    }

    private function startCraftingProcess($character, $userId): ServerResponse
    {
        $craftTask = $this->taskModel->where('name', 'craftFoldingKnife')->first();
        if (!$craftTask) {
            return $this->sendError('Задача "Крафт 🔪 Складной нож" не найдена в базе данных.');
        }

        // Проверка наличия активной задачи крафта
        $activeTask = $this->characterTaskModel->where([
            'character_id' => $character['id'],
            'task_id' => $craftTask['id'],
            'status' => 'in_work'
        ])->first();

        if ($activeTask) {
            return $this->sendError("Извини, но ты не многорукий и не всемогущ. Данная задача крафта уже выполняется, ожидай. А чтобы не скучать пойди проведи время в разделе \"Развлечения\"");
        }

        // Calculate adjusted crafting duration
        $duration = $this->calculateCraftingDuration($character, $craftTask);

        $startTime = new \DateTime();
        $endTime = (clone $startTime)->add(new \DateInterval('PT' . $duration . 'M'));

        $this->characterTaskModel->save([
            'character_id' => $character['id'],
            'telegram_user_id' => $userId,
            'task_id' => $craftTask['id'],
            'start_time' => $startTime->format('Y-m-d H:i:s'),
            'end_time' => $endTime->format('Y-m-d H:i:s'),
            'status' => 'in_work',
        ]);

        return $this->notifyCraftStarted($character, $startTime, $endTime);
    }

    private function calculateCraftingDuration($character, $craftTask)
    {
        // Retrieve character attributes
        $experience = $character['experience'];
        $agility = $character['agility'];
        $intellect = $character['intellect'];

        // Define weighting factors for each attribute
        $expFactor = 0.3; // 30% weight to experience
        $agiFactor = 0.3; // 30% weight to agility
        $intFactor = 0.4; // 40% weight to intellect

        // Calculate attribute contribution
        $attributeScore = ($experience * $expFactor) + ($agility * $agiFactor) + ($intellect * $intFactor);
        $maxAttributeScore = 1000 * ($expFactor + $agiFactor + $intFactor); // Assuming maximum score for each is 1000

        // Normalize the score to a scale of 0 to 1
        $normalizedScore = $attributeScore / $maxAttributeScore;

        // Determine crafting time based on normalized score
        $minDuration = $craftTask['min_duration'];
        $maxDuration = $craftTask['max_duration'];
        $adjustedDuration = $minDuration + ($maxDuration - $minDuration) * (1 - $normalizedScore); // Inverse relationship

        // Ensure the duration is within task defined limits
        return max($minDuration, min($maxDuration, round($adjustedDuration)));
    }

    private function sendError($message): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text' => $message,
        ]);
    }

    private function notifyCraftStarted($character, $startTime, $endTime): ServerResponse
    {
        $interval = $startTime->diff($endTime);
        $minutes = $interval->days * 1440 + $interval->h * 60 + $interval->i;

        $text = "*Процесс крафта запущен*\n\n"
            . "*Ты создаешь: 🔪 Складной нож!*\n\n"
            . "__*Время крафта: " . $minutes . " минут.*__ ⏱️\n\n"
            . "*О готовности ты узнаешь в сообщении.* 🎁\n\n";

        $imagePath = base_url('uploads/telegram/craft/huge_mechanical_workbench.jpg'); // Ensure this path is correctly configured
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendPhoto([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'   => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
        ]);
    }
}
