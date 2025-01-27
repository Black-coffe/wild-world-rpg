<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Components;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\ActiveEventModel;
use App\Models\CharacterResourceModel;
use App\Models\CraftedItemsModel;
use App\Models\CraftedItemsLogModel;
use App\Models\EventModel;
use App\Models\TaskModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * Класс FertilizerCraftActionStart:
 * Запускает количественный крафт «Удобрение» (Fertilizer).
 * Списывает ресурсы и сохраняет quantity в task_settings.
 */
class FertilizerCraftActionStart extends BaseAction
{
    protected $taskModel;
    protected $eventModel;
    protected $activeEventModel;
    protected $characterResourceModel;
    protected $craftedItemsModel;
    protected $craftedItemsLogModel;

    /**
     * Количество (по умолчанию 1), извлекается из callback_data вида "craftFertilizer_10".
     */
    private int $quantity = 1;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);

        $this->taskModel              = new TaskModel();
        $this->eventModel             = new EventModel();
        $this->activeEventModel       = new ActiveEventModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->craftedItemsModel      = new CraftedItemsModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();

        // Пример: "craftFertilizer_10" => ["craftFertilizer","10"] => quantity=10
        $data  = $callbackQuery->getData();
        $parts = explode('_', $data);
        if (isset($parts[1]) && is_numeric($parts[1])) {
            $this->quantity = (int)$parts[1];
        }
    }

    /**
     * Главный метод обработки нажатия «Крафтить» для «Удобрения».
     */
    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendError("Пользователь или персонаж не найден.");
        }

        // Проверка активного переезда (BaseRelocation)
        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $this->callbackQuery->getMessage()->getChat()->getId()
        )) {
            return Request::emptyResponse(); // Переезд есть, сервис уже отписался
        }

        // 1) Находим задачу "craftFertilizer" в таблице tasks
        $craftTask = $this->taskModel->where('name', 'craftFertilizer')->first();
        if (!$craftTask) {
            return $this->sendError('Задача "Крафт удобрений" (craftFertilizer) не найдена.');
        }

        // 2) Проверяем, нет ли уже активной задачи "craftFertilizer"
        $activeTask = $this->characterTaskModel
            ->where('character_id', $character['id'])
            ->where('task_id', $craftTask['id'])
            ->where('status', 'in_work')
            ->first();
        if ($activeTask) {
            return $this->sendError(
                "У тебя уже идёт крафт удобрений! Дождись завершения или прерви (ресурсы не вернутся)."
            );
        }

        // 3) Проверка / списание ресурсов, учитывая $this->quantity
        if (!$this->checkAndDeductResources($character['id'], $this->quantity)) {
            return $this->sendError("Недостаточно ресурсов для крафта {$this->quantity} шт. удобрений.");
        }

        // 4) Создаём запись о задаче (с quantity) + уведомляем игрока
        return $this->startCraftingProcess($character, $user['id'], $craftTask, $this->quantity);
    }

    /**
     * Проверяет и списывает ресурсы на $qty штук.
     */
    private function checkAndDeductResources(int $characterId, int $qty): bool
    {
        // Нормы на 1 шт.
        $requiredResources = [
            'Кости животных' => 1,
            'Вода'           => 5,
            'Водоросли'      => 20,
            'Ил'             => 10,
        ];

        // Сначала убеждаемся, что всего хватает
        foreach ($requiredResources as $resName => $perItem) {
            $needTotal = $perItem * $qty;
            $row       = $this->characterResourceModel->getResourceForCraft($resName, $characterId);
            $have      = $row ? (int)$row['charResQty'] : 0;
            if ($have < $needTotal) {
                return false;
            }
        }

        // Если хватает, списываем
        foreach ($requiredResources as $resName => $perItem) {
            $needTotal = $perItem * $qty;
            if (!$this->characterResourceModel->deductResourceForCraft($resName, $characterId, $needTotal)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Создаём запись в character_tasks, фиксируем quantity в task_settings.
     */
    private function startCraftingProcess(array $character, int $userId, array $craftTask, int $qty): ServerResponse
    {
        // Время на 1 шт., умножаем на qty
        $durationForOne = $this->calculateCraftingDuration($character, $craftTask);
        $totalDuration  = $durationForOne * $qty;

        $startTime = new \DateTime();
        $endTime   = (clone $startTime)->add(new \DateInterval('PT' . $totalDuration . 'M'));

        $taskSettings = [
            'quantity' => $qty
        ];

        $this->characterTaskModel->insert([
            'character_id'     => $character['id'],
            'telegram_user_id' => $userId,
            'task_id'          => $craftTask['id'],
            'start_time'       => $startTime->format('Y-m-d H:i:s'),
            'end_time'         => $endTime->format('Y-m-d H:i:s'),
            'status'           => 'in_work',
            'task_settings'    => json_encode($taskSettings),
        ]);

        return $this->notifyCraftStarted($character, $startTime, $endTime, $qty);
    }

    /**
     * Примерный расчёт времени (для 1 шт.), зависящий от атрибутов персонажа.
     */
    private function calculateCraftingDuration(array $character, array $craftTask): int
    {
        $minDuration = $craftTask['min_duration'] ?? 5;
        $maxDuration = $craftTask['max_duration'] ?? 12;

        $experience = $character['experience'] ?? 0;
        $agility    = $character['agility']    ?? 0;
        $intellect  = $character['intellect']  ?? 0;

        $expFactor = 0.3;
        $agiFactor = 0.3;
        $intFactor = 0.4;

        $attributeScore    = ($experience * $expFactor) + ($agility * $agiFactor) + ($intellect * $intFactor);
        $maxAttributeScore = 1000 * ($expFactor + $agiFactor + $intFactor);
        $normalized        = $maxAttributeScore > 0 ? ($attributeScore / $maxAttributeScore) : 0;
        if ($normalized > 1) {
            $normalized = 1;
        }

        $timeRaw = $minDuration + ($maxDuration - $minDuration) * (1 - $normalized);
        $time    = (int)round($timeRaw);

        return max($minDuration, min($maxDuration, $time));
    }

    /**
     * Отправляем сообщение о старте: X шт. удобрений, прерывание = потеря ресурсов.
     * Здесь добавим форматирование времени (дни/часы/минуты).
     */
    private function notifyCraftStarted(array $character, \DateTime $startTime, \DateTime $endTime, int $qty): ServerResponse
    {
        // Подсчитаем общее количество минут
        $interval = $startTime->diff($endTime);
        $minutes  = $interval->days * 1440 + $interval->h * 60 + $interval->i;

        // Форматируем: "X д. Y ч. Z мин."
        $timeString = $this->formatDuration($minutes);

        $text = "*Процесс крафта запущен*\n\n"
            . "Ты создаёшь: *🌿 Удобрение* x{$qty} шт.\n\n"
            . "**Время крафта:** ~{$timeString}\n\n"
            . "❗Прерывание задачи = потеря ресурсов!\n\n"
            . "_О готовности узнаешь в сообщении._";

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        $imagePath = base_url('uploads/telegram/craft/components/craftFertilizer.jpg');

        return Request::sendPhoto([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    /**
     * Вспомогательный метод: разбивает количество минут на дни, часы, минуты.
     * Возвращает строку вида "2 д. 3 ч. 15 мин." или "45 мин.", "0 мин." и т.д.
     */
    private function formatDuration(int $totalMinutes): string
    {
        $days  = intdiv($totalMinutes, 1440); // 1 день = 1440 мин.
        $rem   = $totalMinutes % 1440;
        $hours = intdiv($rem, 60);
        $mins  = $rem % 60;

        $parts = [];
        if ($days > 0) {
            $parts[] = "{$days} д.";
        }
        if ($hours > 0) {
            $parts[] = "{$hours} ч.";
        }
        if ($mins > 0) {
            $parts[] = "{$mins} мин.";
        }

        // Если все 0 => "0 мин."
        if (empty($parts)) {
            $parts[] = '0 мин.';
        }

        return implode(' ', $parts);
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
