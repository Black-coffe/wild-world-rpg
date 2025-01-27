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

class MetalFragmentsCraftActionStart extends BaseAction
{
    protected $taskModel;
    protected $eventModel;
    protected $activeEventModel;
    protected $characterResourceModel;
    protected $craftedItemsModel;
    protected $craftedItemsLogModel;

    /**
     * Количество (по умолчанию 1), извлекается из callback_data вида "craftMetalFragments_10"
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

        // Парсим callback_data, например "craftMetalFragments_10" => quantity=10
        $data  = $callbackQuery->getData();
        $parts = explode('_', $data);
        if (isset($parts[1]) && is_numeric($parts[1])) {
            $this->quantity = (int)$parts[1];
        }
    }

    /**
     * Точка входа при нажатии "Крафтить X шт." в MetalFragmentsCraft1Action.
     */
    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendError('Пользователь не найден в базе или персонаж отсутствует.');
        }

        // Проверка активного переезда (BaseRelocation)
        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $this->callbackQuery->getMessage()->getChat()->getId()
        )) {
            return Request::emptyResponse(); // Переезд есть, сервис уже отписался
        }

        // 1) Находим задачу "craftMetalFragments" (из таблицы tasks)
        $craftTask = $this->taskModel->where('name', 'craftMetalFragments')->first();
        if (!$craftTask) {
            return $this->sendError('Задача "Крафт металлических фрагментов" не найдена в базе.');
        }

        // 2) Проверяем, нет ли уже активной задачи
        $activeTask = $this->characterTaskModel
            ->where('character_id', $character['id'])
            ->where('task_id', $craftTask['id'])
            ->where('status', 'in_work')
            ->first();
        if ($activeTask) {
            return $this->sendError(
                "У тебя уже идёт крафт «Металл фрагменты». " .
                "Дождись завершения или прерви задачу!"
            );
        }

        // 3) Проверяем / списываем ресурсы с учетом $this->quantity
        if (!$this->checkAndDeductResources($character['id'], $this->quantity)) {
            return $this->sendError(
                "Недостаточно ресурсов для крафта {$this->quantity} шт. металлических фрагментов."
            );
        }

        // 4) Запускаем крафт
        return $this->startCraftingProcess($character, $user['id'], $craftTask, $this->quantity);
    }

    /**
     * Списываем ресурсы (Железная руда×100, Древесина×10, Песок×1) × quantity
     */
    private function checkAndDeductResources(int $charId, int $qty): bool
    {
        // Норма на 1 шт.
        $requiredResources = [
            'Железная руда' => 100,
            'Древесина'     => 10,
            'Песок'         => 1,
        ];

        // Сначала убеждаемся, что всего хватает
        foreach ($requiredResources as $resName => $amountPerOne) {
            $needTotal = $amountPerOne * $qty;
            $row       = $this->characterResourceModel->getResourceForCraft($resName, $charId);
            $have      = $row ? (int)$row['charResQty'] : 0;
            if ($have < $needTotal) {
                return false;
            }
        }

        // Списываем
        foreach ($requiredResources as $resName => $amountPerOne) {
            $needTotal = $amountPerOne * $qty;
            if (!$this->characterResourceModel->deductResourceForCraft($resName, $charId, $needTotal)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Записываем новую задачу (character_tasks) с учетом quantity
     */
    private function startCraftingProcess(array $character, int $userId, array $craftTask, int $qty): ServerResponse
    {
        // Расчет времени на 1 шт.
        $durationForOne = $this->calculateCraftingDuration($character, $craftTask);
        $totalDuration  = $durationForOne * $qty;

        $startTime = new \DateTime();
        $endTime   = (clone $startTime)->add(new \DateInterval('PT' . $totalDuration . 'M'));

        // Сохраняем quantity в task_settings
        $taskSettings = [
            'quantity' => $qty
        ];

        // Создаём запись
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
     * Пример формулы расчёта времени (на 1 шт.)
     */
    private function calculateCraftingDuration(array $character, array $craftTask): int
    {
        $minDuration = $craftTask['min_duration'] ?? 5;
        $maxDuration = $craftTask['max_duration'] ?? 15;

        $exp   = (float)($character['experience'] ?? 0);
        $agi   = (float)($character['agility']    ?? 0);
        $intel = (float)($character['intellect']  ?? 0);

        $expFactor = 0.3;
        $agiFactor = 0.3;
        $intFactor = 0.4;

        $attrScore = $exp * $expFactor + $agi * $agiFactor + $intel * $intFactor;
        $maxAttr   = 1000 * ($expFactor + $agiFactor + $intFactor);
        $norm      = ($maxAttr > 0) ? min(1.0, $attrScore / $maxAttr) : 0;

        $duration  = $minDuration + ($maxDuration - $minDuration) * (1 - $norm);
        $final     = (int) round($duration);
        return max($minDuration, min($maxDuration, $final));
    }

    /**
     * Сообщаем о запуске крафта: X шт., прерывание = потеря ресурсов
     */
    private function notifyCraftStarted(array $character, \DateTime $startTime, \DateTime $endTime, int $qty): ServerResponse
    {
        $interval = $startTime->diff($endTime);
        $minutes  = $interval->days * 1440 + $interval->h * 60 + $interval->i;

        // Преобразуем минуты в "X д. Y ч. Z мин."
        $durationStr = $this->formatDuration($minutes);

        $text = "*Процесс крафта запущен*\n\n"
            . "Ты создаёшь: 🔩 *Металл фрагменты* x{$qty} шт.\n\n"
            . "**Время крафта:** ~{$durationStr}\n\n"
            . "❗Прерывание задачи = потеря ресурсов.\n\n"
            . "_О готовности будет сообщено._";

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        $imagePath = base_url('uploads/telegram/craft/components/craftMetalFragments.jpg');

        return Request::sendPhoto([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    /**
     * Вспомогательный метод: форматируем кол-во минут как "X д. Y ч. Z мин."
     */
    private function formatDuration(int $totalMinutes): string
    {
        $days = intdiv($totalMinutes, 1440); // 1 день = 1440 минут
        $rem  = $totalMinutes % 1440;
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
            $parts[] = "0 мин.";
        }

        return implode(' ', $parts);
    }

    /**
     * Универсальный метод для отправки ошибки
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
