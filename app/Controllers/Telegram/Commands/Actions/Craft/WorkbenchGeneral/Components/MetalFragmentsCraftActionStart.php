<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Components;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\ActiveEventModel;
use App\Models\CharacterResourceModel;
use App\Models\EventModel;
use App\Models\TaskModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * Класс для запуска крафта Металлических Фрагментов.
 */
class MetalFragmentsCraftActionStart extends BaseAction
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

        // 1) Проверяем, есть ли задача "craftMetalFragments"
        $craftTask = $this->taskModel->where('name', 'craftMetalFragments')->first();
        if (!$craftTask) {
            return $this->sendError('Задача "Крафт металлических компонентов" не найдена в базе данных.');
        }

        // 2) Проверяем, нет ли уже активной задачи крафта этого типа
        $activeTask = $this->characterTaskModel
            ->where('character_id', $character['id'])
            ->where('task_id', $craftTask['id'])
            ->where('status', 'in_work')
            ->first();

        if ($activeTask) {
            return $this->sendError(
                "Извини, но ты не многорукий и не всемогущ. "
                . "Крафт «Металл фрагменты» уже выполняется. Поищи себе другое занятие!"
            );
        }

        // 3) Проверяем ресурсы (без списания), если не хватает — выходим
        if (!$this->hasEnoughResources($character['id'])) {
            return $this->sendError('Недостаточно ресурсов для крафта: ничего не списано.');
        }

        // 4) Теперь списываем ресурсы и запускаем процесс
        $this->deductResources($character['id']);
        return $this->startCraftingProcess($character, $user['id'], $craftTask);
    }

    /**
     * Проверяем, достаточно ли ресурсов (не списываем).
     */
    private function hasEnoughResources(int $characterId): bool
    {
        $requiredResources = [
            'Железная руда' => 100,
            'Древесина'     => 10,
            'Песок'         => 1,
        ];

        foreach ($requiredResources as $resourceName => $amount) {
            $resourceRow = $this->characterResourceModel
                ->getResourceByNameAndCharacterId($resourceName, $characterId);
            if (!$resourceRow || $resourceRow['quantity'] < $amount) {
                return false;
            }
        }
        return true;
    }

    /**
     * Списываем ресурсы, зная, что всего хватает.
     */
    private function deductResources(int $characterId): void
    {
        $requiredResources = [
            'Железная руда' => 100,
            'Древесина'     => 10,
            'Песок'         => 1,
        ];

        foreach ($requiredResources as $resourceName => $amount) {
            $resourceRow = $this->characterResourceModel
                ->getResourceByNameAndCharacterId($resourceName, $characterId);

            // Обновляем количество (тут точно хватает, раз прошли hasEnoughResources)
            $this->characterResourceModel->update($resourceRow['id'], [
                'quantity' => $resourceRow['quantity'] - $amount,
            ]);
        }
    }

    /**
     * Запускаем процесс крафта: создаём запись в character_tasks со статусом in_work.
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
     * Логика определения длительности крафта.
     */
    private function calculateCraftingDuration(array $character, array $craftTask): int
    {
        $experience = $character['experience'] ?? 0;
        $agility    = $character['agility']    ?? 0;
        $intellect  = $character['intellect']  ?? 0;

        $expFactor = 0.3;
        $agiFactor = 0.3;
        $intFactor = 0.4;

        $attributeScore = ($experience * $expFactor)
            + ($agility    * $agiFactor)
            + ($intellect  * $intFactor);

        $maxAttributeScore = 1000 * ($expFactor + $agiFactor + $intFactor);
        $normalized        = min(1.0, $attributeScore / $maxAttributeScore);

        $minDuration       = $craftTask['min_duration'] ?? 5;
        $maxDuration       = $craftTask['max_duration'] ?? 15;

        $adjustedDuration  = $minDuration + ($maxDuration - $minDuration) * (1 - $normalized);
        $finalDuration     = (int) round($adjustedDuration);

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
            . "*Ты создаешь: 🔩 Металл фрагменты*\n\n"
            . "__*Время крафта: {$minutes} минут.*__ ⏱️\n\n"
            . "*О готовности ты узнаешь в сообщении.* 🎁\n\n"
            . "P.S. _Не забудь поделиться своими находками!_ 🗣️\n";

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
     * Шаблон для отправки ошибки.
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
