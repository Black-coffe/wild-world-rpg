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
 * Класс FabricCraftActionStart:
 * Запускает процесс крафта «Ткань» (Fabric), списывая ресурсы количественно.
 */
class FabricCraftActionStart extends BaseAction
{
    protected $taskModel;
    protected $eventModel;
    protected $activeEventModel;
    protected $characterResourceModel;
    protected $craftedItemsModel;
    protected $craftedItemsLogModel;

    /**
     * Количество (по умолчанию 1), извлекается из callback_data вида "craftFabric_10".
     */
    private int $quantity = 1;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);

        $this->taskModel             = new TaskModel();
        $this->eventModel            = new EventModel();
        $this->activeEventModel      = new ActiveEventModel();
        $this->characterResourceModel= new CharacterResourceModel();
        $this->craftedItemsModel     = new CraftedItemsModel();
        $this->craftedItemsLogModel  = new CraftedItemsLogModel();

        // Пример callback_data: "craftFabric_10" => ["craftFabric","10"] => quantity=10
        $data  = $callbackQuery->getData();
        $parts = explode('_', $data);
        if (isset($parts[1]) && is_numeric($parts[1])) {
            $this->quantity = (int)$parts[1];
        }
    }

    /**
     * Обработка нажатия кнопки "Крафтить" для "Ткань".
     */
    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendError("Пользователь или персонаж не найден.");
        }

        // 1) Ищем задачу "craftFabric"
        $craftTask = $this->taskModel->where('name', 'craftFabric')->first();
        if (!$craftTask) {
            return $this->sendError('Задача "Крафт Ткань (craftFabric)" не найдена.');
        }

        // 2) Проверяем, нет ли уже активного крафта этого типа
        $activeTask = $this->characterTaskModel
            ->where('character_id', $character['id'])
            ->where('task_id', $craftTask['id'])
            ->where('status', 'in_work')
            ->first();
        if ($activeTask) {
            return $this->sendError(
                "У тебя уже идёт крафт ткани! Дождись завершения или прерви (ресурсы не вернутся)."
            );
        }

        // 3) Проверяем/списываем ресурсы, учитывая $this->quantity
        if (!$this->checkAndDeductResources($character['id'], $this->quantity)) {
            return $this->sendError("Недостаточно ресурсов для крафта {$this->quantity} шт. Ткани.");
        }

        // 4) Создаём запись задачи, указывая quantity в task_settings.
        return $this->startCraftingProcess($character, $user['id'], $craftTask, $this->quantity);
    }

    /**
     * Проверяет и списывает ресурсы, умножая базовые нормы на $qty.
     */
    private function checkAndDeductResources(int $characterId, int $qty): bool
    {
        $requiredResources = [
            'Шерсть животных'          => 10,
            'Шёлк пауков-пустынников'  => 1,
            'Текстильные культуры'     => 10,
        ];

        // Сначала проверяем наличие (через getResourceForCraft).
        foreach ($requiredResources as $resName => $perItem) {
            $needTotal = $perItem * $qty;
            $row       = $this->characterResourceModel->getResourceForCraft($resName, $characterId);
            $have      = $row ? (int)$row['charResQty'] : 0;

            if ($have < $needTotal) {
                return false; // Не хватает
            }
        }

        // Списываем (через deductResourceForCraft).
        foreach ($requiredResources as $resName => $perItem) {
            $needTotal = $perItem * $qty;
            if (!$this->characterResourceModel->deductResourceForCraft($resName, $characterId, $needTotal)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Создаём запись в character_tasks, сохраняем quantity в task_settings.
     */
    private function startCraftingProcess(array $character, int $userId, array $craftTask, int $qty): ServerResponse
    {
        // Рассчитываем время (на 1 шт.), умножая на qty.
        $durationForOne = $this->calculateCraftingDuration($character, $craftTask);
        $totalDuration  = $durationForOne * $qty;

        $startTime = new \DateTime();
        $endTime   = (clone $startTime)->add(new \DateInterval('PT' . $totalDuration . 'M'));

        // Запоминаем в task_settings
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
     * Примерная формула расчёта времени (1 шт.), зависящая от интеллекта.
     */
    private function calculateCraftingDuration(array $character, array $craftTask): int
    {
        $minDuration = $craftTask['min_duration'] ?? 5;
        $maxDuration = $craftTask['max_duration'] ?? 15;

        $intellect   = (float)($character['intellect'] ?? 0);
        $factor      = 1 - min(1.0, $intellect / 200.0);

        $timeRaw     = $maxDuration - ($maxDuration - $minDuration) * (1 - $factor);
        $time        = (int) round($timeRaw);

        return max($minDuration, min($maxDuration, $time));
    }

    /**
     * Уведомляем игрока о запущенном крафте: X штук, прерывание = потеря ресурсов.
     * Здесь добавим форматирование времени: дни / часы / минуты.
     */
    private function notifyCraftStarted(array $character, \DateTime $startTime, \DateTime $endTime, int $qty): ServerResponse
    {
        // 1) Вычисляем общее количество минут
        $interval     = $startTime->diff($endTime);
        $totalMinutes = $interval->days * 1440 + $interval->h * 60 + $interval->i;

        // 2) Форматируем в "X д. Y ч. Z мин."
        $timeString = $this->formatDuration($totalMinutes);

        // 3) Формируем текст
        $text = "*Процесс крафта запущен*\n\n"
            . "Ты создаешь: *🧵 Ткань* x{$qty} шт.\n\n"
            . "**Время крафта** (примерно): {$timeString}\n\n"
            . "❗При прерывании ресурсы пропадут!\n\n"
            . "_О готовности узнаешь в сообщении._ 🎁";

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
     * Вспомогательный метод: разбивает totalMinutes на дни, часы, минуты.
     * Возвращает строку вида "1 д. 2 ч. 30 мин." или "45 мин.", "0 мин." и т.п.
     */
    private function formatDuration(int $totalMinutes): string
    {
        $days  = intdiv($totalMinutes, 1440); // 1 день = 1440 мин
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

        // Если все 0, значит 0 мин.
        if (empty($parts)) {
            $parts[] = '0 мин.';
        }

        return implode(' ', $parts);
    }

    /**
     * Унифицированный метод отправки ошибки.
     */
    private function sendError(string $msg): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'    => $msg,
        ]);
    }
}
