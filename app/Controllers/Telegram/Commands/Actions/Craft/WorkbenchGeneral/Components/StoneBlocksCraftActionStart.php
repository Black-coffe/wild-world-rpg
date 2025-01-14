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
 * Класс для запуска крафта «Каменных блоков».
 * Теперь использует новые методы модели (getResourceForCraft / deductResourceForCraft),
 * чтобы избежать конфликтов alias и проблем с полем 'id'.
 */
class StoneBlocksCraftActionStart extends BaseAction
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

    /**
     * Главная точка входа в класс: проверяем задачу, ресурсы, запускаем крафт.
     */
    public function handle(): ServerResponse
    {
        // 1) Получаем пользователя и персонажа
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendError("Пользователь не найден в базе данных или персонаж не определён.");
        }

        // 2) Ищем задачу "craftStoneBlocks" в БД
        $craftTask = $this->taskModel->where('name', 'craftStoneBlocks')->first();
        if (!$craftTask) {
            return $this->sendError('Задача "Крафт каменных блоков" не найдена в базе данных.');
        }

        // 3) Проверяем, нет ли уже активной задачи такого типа
        $activeTask = $this->characterTaskModel
            ->where('character_id',  $character['id'])
            ->where('task_id',       $craftTask['id'])
            ->where('status',        'in_work')
            ->first();

        if ($activeTask) {
            return $this->sendError(
                "Извини, но у тебя уже запущена задача крафта \"Каменные блоки\". " .
                "Дождись окончания или прерви её, прежде чем начинать заново!"
            );
        }

        // 4) Проверяем ресурсы (не списываем)
        if (!$this->hasEnoughResources($character['id'])) {
            return $this->sendError('Недостаточно ресурсов для крафта: ничего не списано!');
        }

        // 5) Раз всего хватает — списываем
        if (!$this->deductResources($character['id'])) {
            // Теоретически может быть ошибка при списании
            return $this->sendError('Произошла ошибка при списании ресурсов.');
        }

        // 6) Запускаем процесс крафта
        return $this->startCraftingProcess($character, $user['id'], $craftTask);
    }

    /**
     * Проверяем, хватает ли необходимых ресурсов, используя alias-метод getResourceForCraft().
     */
    private function hasEnoughResources(int $characterId): bool
    {
        // Список необходимых ресурсов
        $requiredResources = [
            'Камни' => 36,
            'Вода'  => 10,
        ];

        // Проверяем каждый ресурс
        foreach ($requiredResources as $resourceName => $neededAmount) {
            // Используем alias-метод getResourceForCraft()
            $row = $this->characterResourceModel->getResourceForCraft($resourceName, $characterId);
            // Проверяем поле 'charResQty'
            if (!$row || (int)$row['charResQty'] < $neededAmount) {
                return false; // Не хватает
            }
        }
        return true;
    }

    /**
     * Списываем ресурсы через alias-метод deductResourceForCraft(),
     * когда уверены, что всего точно хватает.
     */
    private function deductResources(int $characterId): bool
    {
        $requiredResources = [
            'Камни' => 36,
            'Вода'  => 10,
        ];

        foreach ($requiredResources as $resourceName => $neededAmount) {
            // Если вдруг не получилось списать — возвращаем false
            if (!$this->characterResourceModel->deductResourceForCraft($resourceName, $characterId, $neededAmount)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Создаём запись о задаче крафта и уведомляем пользователя о старте.
     */
    private function startCraftingProcess(array $character, int $userId, array $craftTask): ServerResponse
    {
        $duration  = $this->calculateCraftingDuration($character, $craftTask);
        $startTime = new \DateTime();
        $endTime   = (clone $startTime)->add(new \DateInterval('PT' . $duration . 'M'));

        // Запись в character_tasks
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
     * Упрощённая логика вычисления длительности крафта на основе статов персонажа.
     */
    private function calculateCraftingDuration(array $character, array $craftTask): int
    {
        $experience = (float)($character['experience'] ?? 0);
        $agility    = (float)($character['agility']    ?? 0);
        $intellect  = (float)($character['intellect']  ?? 0);

        // Вес статов
        $expFactor = 0.3;
        $agiFactor = 0.3;
        $intFactor = 0.4;

        $attrScore = $experience * $expFactor
            + $agility   * $agiFactor
            + $intellect * $intFactor;
        $maxAttr   = 1000 * ($expFactor + $agiFactor + $intFactor);
        $norm      = min(1.0, $attrScore / $maxAttr);

        $minDuration = (int)($craftTask['min_duration'] ?? 5);
        $maxDuration = (int)($craftTask['max_duration'] ?? 15);

        // Чем выше norm, тем меньше реальное время
        $adjusted    = $minDuration + ($maxDuration - $minDuration) * (1 - $norm);
        $final       = (int)round($adjusted);

        return max($minDuration, min($final, $maxDuration));
    }

    /**
     * Отправляем сообщение об успешном запуске крафта.
     */
    private function notifyCraftStarted(array $character, \DateTime $startTime, \DateTime $endTime): ServerResponse
    {
        $interval = $startTime->diff($endTime);
        $minutes  = $interval->days * 1440 + $interval->h * 60 + $interval->i;

        $text = "*Процесс крафта запущен*\n\n"
            . "*Ты создаёшь: 🧱 Каменные блоки*\n\n"
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
            'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Отправляем сообщение об ошибке.
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
