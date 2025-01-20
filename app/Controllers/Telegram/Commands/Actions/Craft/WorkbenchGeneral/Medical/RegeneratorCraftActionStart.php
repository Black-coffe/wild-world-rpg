<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Medical;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\ActiveEventModel;
use App\Models\CharacterResourceModel;
use App\Models\EventModel;
use App\Models\TaskModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class RegeneratorCraftActionStart extends BaseAction
{
    protected $taskModel;
    protected $eventModel;
    protected $activeEventModel;
    protected $characterResourceModel;

    /**
     * Количество предметов, извлекаемое из callback_data (по умолчанию 1).
     */
    private int $quantity = 1;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);

        $this->taskModel              = new TaskModel();
        $this->eventModel             = new EventModel();
        $this->activeEventModel       = new ActiveEventModel();
        $this->characterResourceModel = new CharacterResourceModel();

        // Например, если callback_data = "craftRegenerator_10", берём вторую часть = 10
        $data  = $callbackQuery->getData();
        $parts = explode('_', $data);
        if (isset($parts[1]) && is_numeric($parts[1])) {
            $this->quantity = (int) $parts[1];
        }
    }

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendError("Пользователь или персонаж не найден в базе данных или не создан.");
        }

        // Проверка ресурсов (нормы на 1 шт. × quantity)
        if (!$this->checkAndDeductResources($character['id'], $this->quantity)) {
            return $this->sendError("Недостаточно ресурсов для крафта {$this->quantity} шт.");
        }

        // Запуск процесса (создание записи в character_tasks)
        return $this->startCraftingProcess($character, $user['id'], $this->quantity);
    }

    /**
     * Проверяем и списываем ресурсы, умножая нормы на $qty.
     */
    private function checkAndDeductResources(int $characterId, int $qty): bool
    {
        // Нормы на 1 шт.
        $requiredResources = [
            'Мясо диких животных' => 2,
            'Водные растения'     => 2,
            'Травы'               => 6,
            'Вода'                => 30,
        ];

        foreach ($requiredResources as $resName => $baseAmount) {
            $totalNeeded = $baseAmount * $qty;
            $resource    = $this->characterResourceModel->getResourceByNameAndCharacterId($resName, $characterId);

            if (!$resource || $resource['quantity'] < $totalNeeded) {
                return false;
            }

            // Списываем
            $charRes = $this->characterResourceModel
                ->where('id_characters', $characterId)
                ->where('id_resources', $resource['id'])
                ->first();

            $newQty = $charRes['quantity'] - $totalNeeded;
            $this->characterResourceModel->update($charRes['id'], ['quantity' => $newQty]);
        }

        return true;
    }

    /**
     * Создаём запись в character_tasks: учитываем $qty, рассчитываем время крафта.
     */
    private function startCraftingProcess(array $character, int $userId, int $qty): ServerResponse
    {
        // Ищем задачу craftRegenerator
        $craftTask = $this->taskModel->where('name', 'craftRegenerator')->first();
        if (!$craftTask) {
            return $this->sendError('Задача "Крафт Регенератора" не найдена в базе данных.');
        }

        // Проверяем, нет ли уже активной задачи
        $activeTask = $this->characterTaskModel->where([
            'character_id' => $character['id'],
            'task_id'      => $craftTask['id'],
            'status'       => 'in_work'
        ])->first();

        if ($activeTask) {
            return $this->sendError(
                "У тебя уже идёт крафт «Регенератора»! Подожди окончания или прерви, " .
                "но учти: при прерывании ресурсы не вернутся."
            );
        }

        // Считаем базовую длительность (на 1 шт.), умножаем на qty
        $durationForOne = $this->calculateCraftingDuration($character, $craftTask);
        $totalDuration  = $durationForOne * $qty;

        // Время старта/окончания
        $startTime = new \DateTime();
        $endTime   = (clone $startTime)->add(new \DateInterval('PT' . $totalDuration . 'M'));

        // Сохраняем количество в task_settings
        $taskSettings = [
            'quantity' => $qty
        ];

        // Создаём запись
        $this->characterTaskModel->save([
            'character_id'     => $character['id'],
            'telegram_user_id' => $userId,
            'task_id'          => $craftTask['id'],
            'start_time'       => $startTime->format('Y-m-d H:i:s'),
            'end_time'         => $endTime->format('Y-m-d H:i:s'),
            'status'           => 'in_work',
            'task_settings'    => json_encode($taskSettings),
        ]);

        // Уведомляем игрока
        return $this->notifyCraftStarted($character, $startTime, $endTime, $qty);
    }

    /**
     * Расчёт длительности (на 1 шт.) по формуле атрибутов (min_duration..max_duration).
     */
    private function calculateCraftingDuration(array $character, array $craftTask): int
    {
        $experience = $character['experience'];
        $agility    = $character['agility'];
        $intellect  = $character['intellect'];

        $expFactor = 0.3; // 30%
        $agiFactor = 0.3; // 30%
        $intFactor = 0.4; // 40%

        $attributeScore    = ($experience * $expFactor) + ($agility * $agiFactor) + ($intellect * $intFactor);
        $maxAttributeScore = 1000 * ($expFactor + $agiFactor + $intFactor);
        $normalizedScore   = $attributeScore / $maxAttributeScore;

        $minDuration = $craftTask['min_duration'];
        $maxDuration = $craftTask['max_duration'];

        // Чем выше атрибуты, тем ближе к minDuration
        $adjustedDuration = $minDuration + ($maxDuration - $minDuration) * (1 - $normalizedScore);
        $final = max($minDuration, min($maxDuration, round($adjustedDuration)));

        return $final;
    }

    /**
     * Уведомляем игрока о старте крафта.
     * Показываем итоговое время (суммарно).
     */
    private function notifyCraftStarted(array $character, \DateTime $startTime, \DateTime $endTime, int $qty): ServerResponse
    {
        // Считаем разницу в минутах
        $interval     = $startTime->diff($endTime);
        $totalMinutes = $interval->days * 1440 + $interval->h * 60 + $interval->i;
        $timeString   = $this->formatMinutes($totalMinutes);

        $text = "*Процесс крафта запущен*\n\n"
            . "Ты создаёшь: *🔋 Регенератор* x{$qty} шт.\n\n"
            . "Время крафта: *{$timeString}* ⏱️\n\n"
            . "После завершения будет добавлено *{$qty}* шт. в твой инвентарь.\n\n"
            . "❗Прерывание задачи = потеря ресурсов!\n\n"
            . "_О готовности узнаешь в сообщении._ 🎁\n";

        $imagePath = base_url('uploads/telegram/craft/health_and_strength_regenerator.jpg');
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendPhoto([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    /**
     * Преобразуем минуты в строку "X дней Y часов Z минут".
     */
    private function formatMinutes(int $totalMinutes): string
    {
        if ($totalMinutes <= 0) {
            return "0 минут";
        }

        $days  = intdiv($totalMinutes, 1440);
        $rem   = $totalMinutes % 1440;
        $hours = intdiv($rem, 60);
        $mins  = $rem % 60;

        $parts = [];
        if ($days > 0) {
            $parts[] = "{$days} " . $this->pluralForm($days, ['день','дня','дней']);
        }
        if ($hours > 0) {
            $parts[] = "{$hours} " . $this->pluralForm($hours, ['час','часа','часов']);
        }
        if ($mins > 0) {
            $parts[] = "{$mins} " . $this->pluralForm($mins, ['минута','минуты','минут']);
        }

        return empty($parts) ? "0 минут" : implode(' ', $parts);
    }

    /**
     * Выбираем правильную форму (в русском языке).
     */
    private function pluralForm(int $n, array $forms): string
    {
        $nMod10  = $n % 10;
        $nMod100 = $n % 100;

        if ($nMod100 >= 11 && $nMod100 <= 14) {
            return $forms[2];
        }
        switch ($nMod10) {
            case 1:
                return $forms[0];
            case 2:
            case 3:
            case 4:
                return $forms[1];
            default:
                return $forms[2];
        }
    }

    /**
     * Универсальный метод отправки ошибок.
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
