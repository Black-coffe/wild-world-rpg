<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Components;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\ActiveEventModel;
use App\Models\CharacterResourceModel;
use App\Models\EventModel;
use App\Models\TaskModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class WoodMaterialsCraftActionStart extends BaseAction
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
            return $this->sendError("Пользователь не найден в базе данных или персонаж не определён.");
        }

        // 1) Ищем задачу "craftWoodMaterials"
        $craftTask = $this->taskModel->where('name', 'craftWoodMaterials')->first();
        if (!$craftTask) {
            return $this->sendError('Задача "Крафт деревяных брусов" не найдена в базе данных.');
        }

        // 2) Проверяем, нет ли уже активной задачи данного типа
        $activeTask = $this->characterTaskModel
            ->where('character_id', $character['id'])
            ->where('task_id', $craftTask['id'])
            ->where('status', 'in_work')
            ->first();

        if ($activeTask) {
            return $this->sendError(
                "Извини, но ты не многорукий и не всемогущ. "
                . "Данная задача крафта уже выполняется, ожидай."
            );
        }

        // 3) Проверяем, хватает ли ресурсов
        if (!$this->hasEnoughResources($character['id'])) {
            return $this->sendError('Недостаточно ресурсов для крафта: ничего не списано!');
        }

        // 4) Списываем ресурсы только после всех проверок
        $this->deductResources($character['id']);

        // 5) Запускаем процесс крафта
        return $this->startCraftingProcess($character, $user['id'], $craftTask);
    }

    /**
     * Проверяем, достаточно ли ресурсов (не списывая).
     */
    private function hasEnoughResources(int $characterId): bool
    {
        $requiredResources = [
            'Древесина' => 50,
            'Вода'      => 5,
        ];

        foreach ($requiredResources as $resourceName => $requiredAmount) {
            $resource = $this->characterResourceModel
                ->getResourceByNameAndCharacterId($resourceName, $characterId);

            if (!$resource || $resource['quantity'] < $requiredAmount) {
                return false;
            }
        }
        return true;
    }

    /**
     * Списываем ресурсы, когда убедились, что их хватает.
     */
    private function deductResources(int $characterId): void
    {
        $requiredResources = [
            'Древесина' => 50,
            'Вода'      => 5,
        ];

        foreach ($requiredResources as $resourceName => $requiredAmount) {
            $resRow = $this->characterResourceModel
                ->getResourceByNameAndCharacterId($resourceName, $characterId);

            $this->characterResourceModel->update($resRow['id'], [
                'quantity' => $resRow['quantity'] - $requiredAmount
            ]);
        }
    }

    /**
     * Собственно запуск крафта: запись в character_tasks.
     */
    private function startCraftingProcess(array $character, int $userId, array $craftTask): ServerResponse
    {
        $duration  = $this->calculateCraftingDuration($character, $craftTask);
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

    private function calculateCraftingDuration(array $character, array $craftTask): int
    {
        $experience = $character['experience'] ?? 0;
        $agility    = $character['agility']    ?? 0;
        $intellect  = $character['intellect']  ?? 0;

        // Весовые коэффициенты
        $expFactor = 0.3;
        $agiFactor = 0.3;
        $intFactor = 0.4;

        $attrScore   = ($experience * $expFactor)
            + ($agility    * $agiFactor)
            + ($intellect  * $intFactor);
        $maxAttrScore= 1000 * ($expFactor + $agiFactor + $intFactor);
        $normalized  = min(1.0, $attrScore / $maxAttrScore);

        $minDuration = $craftTask['min_duration'] ?? 5;
        $maxDuration = $craftTask['max_duration'] ?? 15;

        $adjustedDuration = $minDuration + ($maxDuration - $minDuration) * (1 - $normalized);
        $finalDuration    = (int) round($adjustedDuration);

        return max($minDuration, min($finalDuration, $maxDuration));
    }

    /**
     * Возвращаем ошибку (и не списываем ресурсы).
     */
    private function sendError(string $message): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'    => $message,
        ]);
    }

    /**
     * Сообщаем о запущенном крафте.
     */
    private function notifyCraftStarted(array $character, \DateTime $startTime, \DateTime $endTime): ServerResponse
    {
        $interval = $startTime->diff($endTime);
        $minutes  = $interval->days * 1440 + $interval->h * 60 + $interval->i;

        $text = "*Процесс крафта запущен*\n\n"
            . "*Ты создаешь: 🪵 Древесные материалы*\n\n"
            . "__*Время крафта: {$minutes} минут.*__ ⏱️\n\n"
            . "*О готовности ты узнаешь в сообщении.* 🎁\n\n"
            . "P.S. _Не забудь поделиться своими находками!_ 🗣️\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🛠️ Крафтинг', 'callback_data' => 'crafting']
                ],
            ]
        ];

        $imagePath = base_url('uploads/telegram/craft/huge_mechanical_workbench.jpg');
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendPhoto([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
