<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Components;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\ActiveEventModel;
use App\Models\CharacterResourceModel;
use App\Models\EventModel;
use App\Models\TaskModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class SoilCraftActionStart extends BaseAction
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

        // 1) Ищем задачу "craftSoil" в базе
        $craftTask = $this->taskModel->where('name', 'craftSoil')->first();
        if (!$craftTask) {
            return $this->sendError('Задача "Крафт металлических компонентов" (craftSoil) не найдена в БД.');
        }

        // 2) Проверяем, нет ли уже активного крафта "craftSoil" у этого персонажа
        $activeTask = $this->characterTaskModel
            ->where('character_id', $character['id'])
            ->where('task_id', $craftTask['id'])
            ->where('status', 'in_work')
            ->first();

        if ($activeTask) {
            return $this->sendError(
                "Извини, но ты не многорукий и не всемогущ. "
                . "Задача крафта \"Грунт\" уже выполняется. Немного терпения!"
            );
        }

        // 3) Проверяем, достаточно ли ресурсов (не списываем)
        if (!$this->hasEnoughResources($character['id'])) {
            return $this->sendError('Недостаточно ресурсов для крафта: ничего не списано.');
        }

        // 4) Раз ресурсов хватает — списываем, а затем запускаем процесс крафта
        $this->deductResources($character['id']);
        return $this->startCraftingProcess($character, $user['id'], $craftTask);
    }

    /**
     * Проверяем, достаточно ли ресурсов (не списывая).
     */
    private function hasEnoughResources(int $characterId): bool
    {
        // Список требуемых ресурсов
        $requiredResources = [
            'Глина'     => 10,
            'Водоросли' => 5,
            'Песок'     => 26,
            'Ил'        => 15,
        ];

        foreach ($requiredResources as $resourceName => $requiredAmount) {
            $resourceRow = $this->characterResourceModel
                ->getResourceByNameAndCharacterId($resourceName, $characterId);
            if (!$resourceRow || $resourceRow['quantity'] < $requiredAmount) {
                return false;
            }
        }
        return true;
    }

    /**
     * Списываем ресурсы, когда уже знаем, что всего хватает.
     */
    private function deductResources(int $characterId): void
    {
        $requiredResources = [
            'Глина'     => 10,
            'Водоросли' => 5,
            'Песок'     => 26,
            'Ил'        => 15,
        ];

        foreach ($requiredResources as $resourceName => $requiredAmount) {
            $resourceRow = $this->characterResourceModel
                ->getResourceByNameAndCharacterId($resourceName, $characterId);

            $this->characterResourceModel->update($resourceRow['id'], [
                'quantity' => $resourceRow['quantity'] - $requiredAmount
            ]);
        }
    }

    /**
     * Запуск процесса крафта: создаём запись в character_tasks со статусом in_work.
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

    /**
     * Пример расчёта длительности крафта на основе атрибутов персонажа.
     */
    private function calculateCraftingDuration(array $character, array $craftTask): int
    {
        $experience = $character['experience'] ?? 0;
        $agility    = $character['agility']    ?? 0;
        $intellect  = $character['intellect']  ?? 0;

        $expFactor = 0.3;
        $agiFactor = 0.3;
        $intFactor = 0.4;

        $attributeScore   = ($experience * $expFactor)
            + ($agility    * $agiFactor)
            + ($intellect  * $intFactor);
        $maxAttributeScore= 1000 * ($expFactor + $agiFactor + $intFactor);
        $normalized       = min(1.0, $attributeScore / $maxAttributeScore);

        $minDuration = $craftTask['min_duration'] ?? 5;
        $maxDuration = $craftTask['max_duration'] ?? 15;

        $adjustedDuration = $minDuration + ($maxDuration - $minDuration) * (1 - $normalized);
        $finalDuration    = (int) round($adjustedDuration);

        return max($minDuration, min($finalDuration, $maxDuration));
    }

    /**
     * Отправляем сообщение об успешно запущенном крафте.
     */
    private function notifyCraftStarted(array $character, \DateTime $startTime, \DateTime $endTime): ServerResponse
    {
        $interval = $startTime->diff($endTime);
        $minutes  = $interval->days * 1440 + $interval->h * 60 + $interval->i;

        $text = "*Процесс крафта запущен*\n\n"
            . "*Ты создаешь: 🌱 Грунт*\n\n"
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

    /**
     * Удобный метод для отправки сообщения об ошибке.
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
