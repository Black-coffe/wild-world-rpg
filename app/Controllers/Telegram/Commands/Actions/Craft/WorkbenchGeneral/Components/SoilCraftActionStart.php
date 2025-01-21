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

    /**
     * Количество для крафта (по умолчанию 1),
     * парсится из callback_data вида "craftSoil_10".
     */
    private int $quantity = 1;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);

        $this->taskModel             = new TaskModel();
        $this->eventModel            = new EventModel();
        $this->activeEventModel      = new ActiveEventModel();
        $this->characterResourceModel= new CharacterResourceModel();

        // Парсим callback_data, например "craftSoil_25" => quantity=25
        $data  = $callbackQuery->getData(); // "craftSoil_25"
        $parts = explode('_', $data);
        if (isset($parts[1]) && is_numeric($parts[1])) {
            $this->quantity = (int)$parts[1];
        }
    }

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendError('Пользователь или персонаж не определён в БД.');
        }

        // 1) Ищем задачу "craftSoil"
        $craftTask = $this->taskModel->where('name', 'craftSoil')->first();
        if (!$craftTask) {
            return $this->sendError('Задача "Крафт грунта" (craftSoil) не найдена в базе данных.');
        }

        // 2) Проверяем, нет ли уже активной задачи
        $activeTask = $this->characterTaskModel
            ->where('character_id', $character['id'])
            ->where('task_id', $craftTask['id'])
            ->where('status', 'in_work')
            ->first();

        if ($activeTask) {
            return $this->sendError(
                "У тебя уже выполняется крафт \"Грунта\". " .
                "Дождись завершения или прерви задачу!"
            );
        }

        // 3) Проверяем / списываем ресурсы, учитывая $this->quantity
        if (!$this->checkAndDeductResources($character['id'], $this->quantity)) {
            return $this->sendError("Недостаточно ресурсов для крафта {$this->quantity} шт. грунта.");
        }

        // 4) Запуск крафта
        return $this->startCraftingProcess($character, $user['id'], $craftTask, $this->quantity);
    }

    /**
     * Списываем ресурсы на нужное количество.
     */
    private function checkAndDeductResources(int $charId, int $qty): bool
    {
        // Нормы на 1 шт. грунта
        $requiredPerOne = [
            'Глина'     => 10,
            'Водоросли' => 5,
            'Песок'     => 26,
            'Ил'        => 15,
        ];

        // Сначала проверяем
        foreach ($requiredPerOne as $resName => $amountOne) {
            $totalNeeded = $amountOne * $qty;

            // Берём ресурс через alias-метод getResourceForCraft
            $row = $this->characterResourceModel->getResourceForCraft($resName, $charId);
            if (!$row || $row['charResQty'] < $totalNeeded) {
                // Не хватает
                return false;
            }
        }

        // Если всего хватает — списываем
        foreach ($requiredPerOne as $resName => $amountOne) {
            $totalNeeded = $amountOne * $qty;

            if (!$this->characterResourceModel->deductResourceForCraft($resName, $charId, $totalNeeded)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Создаём запись о задаче в character_tasks, указывая quantity в task_settings.
     */
    private function startCraftingProcess(array $character, int $userId, array $craftTask, int $qty): ServerResponse
    {
        $durationForOne = $this->calculateCraftingDuration($character, $craftTask);
        $totalDuration  = $durationForOne * $qty;

        $startTime = new \DateTime();
        $endTime   = (clone $startTime)->add(new \DateInterval('PT' . $totalDuration . 'M'));

        // Пишем количество в task_settings
        $taskSettings = [
            'quantity' => $qty,
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
     * Пример расчёта времени (за 1 шт.)
     */
    private function calculateCraftingDuration(array $character, array $craftTask): int
    {
        $experience = (float)($character['experience'] ?? 0);
        $agility    = (float)($character['agility']    ?? 0);
        $intellect  = (float)($character['intellect']  ?? 0);

        $expFactor  = 0.3;
        $agiFactor  = 0.3;
        $intFactor  = 0.4;

        $attrScore = $experience * $expFactor
            + $agility    * $agiFactor
            + $intellect  * $intFactor;
        $maxAttr   = 1000 * ($expFactor + $agiFactor + $intFactor);
        $norm      = ($maxAttr > 0) ? min(1.0, $attrScore / $maxAttr) : 0;

        $minDuration = $craftTask['min_duration'] ?? 4;
        $maxDuration = $craftTask['max_duration'] ?? 16;

        $adjusted = $minDuration + ($maxDuration - $minDuration) * (1 - $norm);
        $final    = (int) round($adjusted);

        return max($minDuration, min($final, $maxDuration));
    }

    /**
     * Сообщаем о запуске крафта N шт.
     */
    private function notifyCraftStarted(
        array $character,
        \DateTime $startTime,
        \DateTime $endTime,
        int $qty
    ): ServerResponse {
        $interval = $startTime->diff($endTime);
        $minutes  = $interval->days * 1440 + $interval->h * 60 + $interval->i;

        // Теперь форматируем minutes -> "X д. Y ч. Z мин."
        $timeString = $this->formatDuration($minutes);

        $text = "*Процесс крафта запущен*\n\n"
            . "Ты создаёшь: 🌱 *Грунт* x{$qty} шт.\n\n"
            . "__*Время крафта: {$timeString}*__ ⏱️\n\n"
            . "❗Прерывание задачи = потеря ресурсов.\n\n"
            . "_О готовности узнаешь в отдельном сообщении._";

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        $imagePath = base_url('uploads/telegram/craft/components/craftSoil.jpg');

        return Request::sendPhoto([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    /**
     * (Новый) Метод для перевода общего кол-ва минут в "X д. Y ч. Z мин."
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

        if (empty($parts)) {
            $parts[] = '0 мин.';
        }

        return implode(' ', $parts);
    }

    /**
     * Универсальный метод для вывода ошибок
     */
    private function sendError(string $message): ServerResponse
    {
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'    => $message,
        ]);
    }
}
