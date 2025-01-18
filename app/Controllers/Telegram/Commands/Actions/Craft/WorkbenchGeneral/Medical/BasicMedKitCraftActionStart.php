<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Medical;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\ActiveEventModel;
use App\Models\CharacterResourceModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\EventModel;
use App\Models\TaskModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class BasicMedKitCraftActionStart extends BaseAction
{
    protected $taskModel;
    protected $eventModel;
    protected $craftedItemsModel;
    protected $craftedItemsLogModel;
    protected $activeEventModel;
    protected $characterResourceModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->taskModel = new TaskModel();
        $this->eventModel = new EventModel();
        $this->craftedItemsModel = new CraftedItemsModel();
        $this->craftedItemsLogModel = new CraftedItemsLogModel();
        $this->activeEventModel = new ActiveEventModel();
        $this->characterResourceModel = new CharacterResourceModel();
    }

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendError('Пользователь не найден в базе данных или персонаж не определён.');
        }

        if (!$this->checkAndDeductResources($character['id'])) {
            return $this->sendError('Недостаточно ресурсов для крафта.');
        }

        return $this->startCraftingProcess($character, $user['id']);
    }

    private function checkAndDeductResources($characterId): bool
    {
        $requiredResources = [
            'resources' => [
                'Грибы' => 4,
                'Мед' => 2,
                'Алоэ' => 4,
                'Вода' => 11,
            ],
            'crafted_items' => [
                'Bandage' => 5,
            ]
        ];

        foreach ($requiredResources['resources'] as $resourceName => $requiredAmount) {
            $resource = $this->characterResourceModel->getResourceByNameAndCharacterId($resourceName, $characterId);
            if (!$resource || $resource['quantity'] < $requiredAmount) {
                return false; // Не хватает ресурсов
            }else{
                $item = $this->characterResourceModel->where('id_characters', $characterId)->where('id_resources', $resource['id'])->first();
                $this->characterResourceModel->update($item['id'], ['quantity' => $item['quantity'] - $requiredAmount]);
            }
        }

        foreach ($requiredResources['crafted_items'] as $itemName => $requiredAmount) {
            $itemId = $this->craftedItemsModel->getIdByName($itemName);
            if (!$itemId) {
                log_message('error', "Крафтовый предмет не найден: $itemName");
                return false;
            }
            $item = $this->craftedItemsLogModel->getItemByCraftedItemIdAndCharacterId($itemId, $characterId);
            if (!$item || $item['quantity'] < $requiredAmount) {
                log_message('error', "Не хватает крафтового предмета: $itemName, требуется: $requiredAmount, наличие: " . ($item ? $item['quantity'] : '0'));
                return false;
            }
            $this->craftedItemsLogModel->update($item['id'], ['quantity' => $item['quantity'] - $requiredAmount]);
        }

        return true;
    }

    private function startCraftingProcess($character, $userId): ServerResponse
    {
        $craftTask = $this->taskModel->where('name', 'craftBasicMedKit')->first();
        if (!$craftTask) {
            return $this->sendError('Задача "Крафт Базовой аптечки" не найдена в базе данных.');
        }

        $activeTask = $this->characterTaskModel->where([
            'character_id' => $character['id'],
            'task_id' => $craftTask['id'],
            'status' => 'in_work'
        ])->first();

        if ($activeTask) {
            return $this->sendError("Извини, но ты не многорукий и не всемогущ. Данная задача крафта уже выполняется, ожидай.");
        }

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
        $experience = $character['experience'];
        $agility = $character['agility'];
        $intellect = $character['intellect'];

        $expFactor = 0.3;
        $agiFactor = 0.3;
        $intFactor = 0.4;

        $attributeScore = ($experience * $expFactor) + ($agility * $agiFactor) + ($intellect * $intFactor);
        $maxAttributeScore = 1000 * ($expFactor + $agiFactor + $intFactor);

        $normalizedScore = $attributeScore / $maxAttributeScore;

        $minDuration = $craftTask['min_duration'];
        $maxDuration = $craftTask['max_duration'];
        $adjustedDuration = $minDuration + ($maxDuration - $minDuration) * (1 - $normalizedScore);

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
            . "*Ты создаешь: 🚑 Аптечку базовую!*\n\n"
            . "__*Время крафта: " . $minutes . " минут.*__ ⏱️\n\n"
            . "*О готовности ты узнаешь в сообщении.* 🎁\n\n"
            . "P.S. _Не забудь поделиться своими находками!_ 🗣️\n";

        $imagePath = base_url('uploads/telegram/craft/simple_craft_kit.jpg'); // Исправлен путь к изображению
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendPhoto([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'   => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
        ]);
    }
}
