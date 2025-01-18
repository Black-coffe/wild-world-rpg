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
 * Класс FabricCraftActionStart:
 * Запускает процесс крафта «Ткань» (Fabric), списывая ресурсы.
 *
 * Используем новые методы getResourceForCraft() и deductResourceForCraft()
 * из CharacterResourceModel (через алиасы).
 */
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

    /**
     * Основной метод обработки нажатия "🛠️ Крафтить" для "Ткань".
     */
    public function handle(): ServerResponse
    {
        // Получаем данные пользователя и персонажа.
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendError('Пользователь не найден в базе данных или персонаж не определён.');
        }

        // 1) Ищем задачу "craftFabric" в таблице tasks.
        $craftTask = $this->taskModel->where('name', 'craftFabric')->first();
        if (!$craftTask) {
            return $this->sendError('Задача "Крафт Ткань (craftFabric)" не найдена в базе данных.');
        }

        // 2) Проверяем, нет ли уже активной задачи крафта "craftFabric" у персонажа.
        $activeTask = $this->characterTaskModel
            ->where('character_id', $character['id'])
            ->where('task_id', $craftTask['id'])
            ->where('status', 'in_work')
            ->first();

        if ($activeTask) {
            // Уже идёт крафт этого предмета — выходим без списания ресурсов.
            return $this->sendError(
                "Извини, но у тебя уже идёт крафт ткани. Дождись завершения, прежде чем начинать новый!"
            );
        }

        // 3) Проверяем и списываем ресурсы.
        if (!$this->checkAndDeductResources($character['id'])) {
            return $this->sendError('Недостаточно ресурсов для крафта: ничего не списано.');
        }

        // 4) Запускаем процесс крафта (запись в character_tasks).
        return $this->startCraftingProcess($character, $user['id'], $craftTask);
    }

    /**
     * Проверяет наличие нужных ресурсов у персонажа и, если хватает, списывает.
     * Используем новые методы getResourceForCraft() / deductResourceForCraft() (alias'ы).
     */
    private function checkAndDeductResources(int $characterId): bool
    {
        // Необходимые ресурсы для крафта ткани.
        $requiredResources = [
            'Шерсть животных'          => 10,
            'Шёлк пауков-пустынников'  => 1,
            'Текстильные культуры'     => 10,
        ];

        // 1) Проверяем, хватает ли ресурсов (через alias'ы).
        foreach ($requiredResources as $resourceName => $requiredAmount) {
            $row = $this->characterResourceModel->getResourceForCraft($resourceName, $characterId);
            // В $row['charResQty'] лежит кол-во ресурса из character_resources.
            if (!$row || (int)$row['charResQty'] < $requiredAmount) {
                return false;
            }
        }

        // 2) Списываем (раз всего хватает).
        foreach ($requiredResources as $resourceName => $requiredAmount) {
            if (!$this->characterResourceModel->deductResourceForCraft($resourceName, $characterId, $requiredAmount)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Создаём запись в character_tasks и уведомляем игрока.
     */
    private function startCraftingProcess(array $character, int $userId, array $craftTask): ServerResponse
    {
        // Рассчитываем примерное время крафта.
        $duration  = $this->calculateCraftingDuration($character, $craftTask);
        $startTime = new \DateTime();
        $endTime   = (clone $startTime)->add(new \DateInterval('PT' . $duration . 'M'));

        // Запись в таблицу character_tasks.
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
     * Пример расчёта длительности крафта.
     */
    private function calculateCraftingDuration(array $character, array $craftTask): int
    {
        $minDuration = $craftTask['min_duration'] ?? 5;
        $maxDuration = $craftTask['max_duration'] ?? 15;

        // Имеем логику, зависящую от характеристики.
        $intellect = (float)($character['intellect'] ?? 0);
        $factor    = 1 - min(1.0, $intellect / 200.0);

        $time = $maxDuration - ($maxDuration - $minDuration) * (1 - $factor);
        return (int) max($minDuration, min($maxDuration, round($time)));
    }

    /**
     * Отправляем сообщение о том, что крафт стартовал, + картинка.
     */
    private function notifyCraftStarted(array $character, \DateTime $startTime, \DateTime $endTime): ServerResponse
    {
        $interval = $startTime->diff($endTime);
        $minutes  = $interval->days * 1440 + $interval->h * 60 + $interval->i;

        $text = "*Процесс крафта запущен*\n\n"
            . "*Ты создаешь: 🧵 Ткань*\n\n"
            . "__*Время крафта: {$minutes} минут.*__ ⏱️\n\n"
            . "*О готовности ты узнаешь в сообщении.* 🎁\n\n"
            . "P.S. _Не забудь поделиться своими находками!_";

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        $imagePath = base_url('uploads/telegram/craft/components/craftFabric.jpg');

        return Request::sendPhoto([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    /**
     * Унифицированный метод для отправки ошибки.
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
