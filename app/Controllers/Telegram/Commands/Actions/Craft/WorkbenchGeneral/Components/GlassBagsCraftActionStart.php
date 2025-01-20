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
 * Класс GlassBagsCraftActionStart:
 * Запуск количественного крафта «Стеклопакеты» (GlassBags).
 * Списывает ресурсы × quantity, записывает quantity в task_settings.
 */
class GlassBagsCraftActionStart extends BaseAction
{
    protected $taskModel;
    protected $eventModel;
    protected $activeEventModel;
    protected $characterResourceModel;
    protected $craftedItemsModel;
    protected $craftedItemsLogModel;

    /**
     * Количество (по умолчанию 1), извлекается из callback_data вида "craftGlassBags_10".
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

        // Пример callback_data: "craftGlassBags_10" => ["craftGlassBags","10"] => quantity=10
        $data  = $callbackQuery->getData();
        $parts = explode('_', $data);
        if (isset($parts[1]) && is_numeric($parts[1])) {
            $this->quantity = (int)$parts[1];
        }
    }

    public function handle(): ServerResponse
    {
        // 1. Получаем пользователя и персонажа
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendError("Пользователь не найден или персонаж не создан.");
        }

        // 2. Ищем задачу "craftGlassBags" в tasks
        $craftTask = $this->taskModel->where('name', 'craftGlassBags')->first();
        if (!$craftTask) {
            return $this->sendError('Задача "Крафт Стеклопакеты" (craftGlassBags) не найдена.');
        }

        // 3. Проверяем, нет ли уже активной задачи craftGlassBags
        $activeTask = $this->characterTaskModel
            ->where('character_id', $character['id'])
            ->where('task_id', $craftTask['id'])
            ->where('status', 'in_work')
            ->first();
        if ($activeTask) {
            return $this->sendError(
                "У тебя уже идёт крафт стеклопакетов! Дождись завершения или прерви (ресурсы не вернутся)."
            );
        }

        // 4. Списываем ресурсы, учитывая выбранное quantity
        if (!$this->checkAndDeductResources($character['id'], $this->quantity)) {
            return $this->sendError("Недостаточно ресурсов для крафта {$this->quantity} шт. стеклопакетов.");
        }

        // 5. Запускаем процесс крафта
        return $this->startCraftingProcess($character, $user['id'], $craftTask, $this->quantity);
    }

    /**
     * Проверяем и списываем ресурсы (Древесина, Песок, Базальт, Лавовый камень) × quantity.
     */
    private function checkAndDeductResources(int $characterId, int $qty): bool
    {
        // Нормы на 1 шт.
        $requiredResources = [
            'Древесина'      => 10,
            'Песок'          => 50,
            'Базальт'        => 10,
            'Лавовый камень' => 8,
        ];

        // Проверяем наличие
        foreach ($requiredResources as $resName => $perOne) {
            $needTotal = $perOne * $qty;
            $row       = $this->characterResourceModel->getResourceForCraft($resName, $characterId);
            $have      = $row ? (int)$row['charResQty'] : 0;
            if ($have < $needTotal) {
                return false;
            }
        }

        // Списываем
        foreach ($requiredResources as $resName => $perOne) {
            $needTotal = $perOne * $qty;
            if (!$this->characterResourceModel->deductResourceForCraft($resName, $characterId, $needTotal)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Создаём запись о задаче (в character_tasks), записываем quantity в task_settings.
     */
    private function startCraftingProcess(array $character, int $userId, array $craftTask, int $qty): ServerResponse
    {
        // Время на 1 шт., умножаем на qty
        $durationForOne = $this->calculateCraftingDuration($character, $craftTask);
        $totalDuration  = $durationForOne * $qty;

        $startTime = new \DateTime();
        $endTime   = (clone $startTime)->add(new \DateInterval('PT' . $totalDuration . 'M'));

        // Запоминаем quantity в JSON
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
     * Примерная формула расчёта времени (на 1 шт.).
     */
    private function calculateCraftingDuration(array $character, array $craftTask): int
    {
        $minDuration = $craftTask['min_duration'] ?? 4;
        $maxDuration = $craftTask['max_duration'] ?? 8;

        // Допустим, интеллект уменьшает время
        $intellect = (float)($character['intellect'] ?? 0);
        $factor    = 1 - min(1.0, $intellect / 200.0);

        $timeRaw   = $maxDuration - ($maxDuration - $minDuration) * (1 - $factor);
        $time      = (int) round($timeRaw);

        return max($minDuration, min($maxDuration, $time));
    }

    /**
     * Уведомляем о старте крафта: X шт., прерывание = потеря ресурсов.
     */
    private function notifyCraftStarted(array $character, \DateTime $startTime, \DateTime $endTime, int $qty): ServerResponse
    {
        $interval = $startTime->diff($endTime);
        $minutes  = $interval->days * 1440 + $interval->h * 60 + $interval->i;

        $text = "*Процесс крафта запущен*\n\n"
            . "Ты создаёшь: 🪟 *Стеклопакеты* x{$qty} шт.\n\n"
            . "**Время крафта:** ~{$minutes} минут.\n\n"
            . "❗Прерывание задачи = потеря всех ресурсов!\n\n"
            . "_О готовности узнаешь в сообщении._";

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        $imagePath = base_url('uploads/telegram/craft/components/craftGlassBags.jpg');

        return Request::sendPhoto([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    /**
     * Универсальная отправка ошибки
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
