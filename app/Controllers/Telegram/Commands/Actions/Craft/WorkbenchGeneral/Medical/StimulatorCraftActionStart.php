<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Medical;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\ActiveEventModel;
use App\Models\CharacterResourceModel;
use App\Models\EventModel;
use App\Models\TaskModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class StimulatorCraftActionStart extends BaseAction
{
    protected $taskModel;
    protected $eventModel;
    protected $activeEventModel;
    protected $characterResourceModel;

    /**
     * Количество предметов крафта, извлекаемое из callback_data.
     * По умолчанию 1 (если не смогли распарсить).
     */
    private int $quantity = 1;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);

        $this->taskModel              = new TaskModel();
        $this->eventModel             = new EventModel();
        $this->activeEventModel       = new ActiveEventModel();
        $this->characterResourceModel = new CharacterResourceModel();

        // Пример callback_data: "craftStimulator_10"
        // Разбиваем строку по "_"
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
            return $this->sendError("Пользователь или персонаж не найден.");
        }

        // Списываем ресурсы на $this->quantity штук
        if (!$this->checkAndDeductResources($character['id'], $this->quantity)) {
            return $this->sendError("Недостаточно ресурсов для крафта {$this->quantity} шт.");
        }

        // Запуск процесса крафта
        return $this->startCraftingProcess($character, $user['id'], $this->quantity);
    }

    /**
     * Проверяем и списываем ресурсы, умножая нормы на $qty.
     */
    private function checkAndDeductResources(int $characterId, int $qty): bool
    {
        // Нормы на 1 шт.
        $requiredResources = [
            'Грибы' => 3,
            'Мед'   => 2,
            'Алоэ'  => 3,
            'Вода'  => 12,
        ];

        foreach ($requiredResources as $resName => $baseAmount) {
            $totalNeeded = $baseAmount * $qty;

            // Проверяем наличие
            $resource = $this->characterResourceModel->getResourceByNameAndCharacterId($resName, $characterId);
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
     * Создаём задачу в character_tasks, учитывая $qty, и рассчитываем общее время.
     */
    private function startCraftingProcess(array $character, int $userId, int $qty): ServerResponse
    {
        $craftTask = $this->taskModel->where('name', 'craftStimulator')->first();
        if (!$craftTask) {
            return $this->sendError('Задача "Крафт Стимулятора" не найдена в базе данных.');
        }

        // Проверяем, нет ли уже активной такой задачи
        $activeTask = $this->characterTaskModel->where([
            'character_id' => $character['id'],
            'task_id'      => $craftTask['id'],
            'status'       => 'in_work'
        ])->first();

        if ($activeTask) {
            return $this->sendError(
                "У тебя уже идёт крафт Стимулятора! Дождись окончания или прерви," .
                " но при прерывании ресурсы не вернутся."
            );
        }

        // Считаем базовую длительность на 1 шт., умножаем на qty
        $durationForOne = $this->calculateCraftingDuration($character, $craftTask);
        $totalDuration  = $durationForOne * $qty;

        $startTime = new \DateTime();
        $endTime   = (clone $startTime)->add(new \DateInterval('PT' . $totalDuration . 'M'));

        // Сохраняем кол-во (qty) в task_settings
        $taskSettings = [
            'quantity' => $qty
        ];

        // Создаём запись в character_tasks
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
     * Расчёт длительности (на 1 шт.) по формуле атрибутов.
     */
    private function calculateCraftingDuration(array $character, array $craftTask): int
    {
        // Атрибуты
        $experience = $character['experience'];
        $agility    = $character['agility'];
        $intellect  = $character['intellect'];

        // Весовые коэффициенты
        $expFactor  = 0.3;
        $agiFactor  = 0.3;
        $intFactor  = 0.4;

        $attributeScore     = ($experience * $expFactor) + ($agility * $agiFactor) + ($intellect * $intFactor);
        $maxAttributeScore  = 1000 * ($expFactor + $agiFactor + $intFactor);
        $normalizedScore    = $attributeScore / $maxAttributeScore;

        $minDuration = $craftTask['min_duration'];
        $maxDuration = $craftTask['max_duration'];

        // Обратная зависимость: чем выше атрибуты, тем ближе к minDuration
        $adjustedDuration = $minDuration + ($maxDuration - $minDuration) * (1 - $normalizedScore);

        return max($minDuration, min($maxDuration, round($adjustedDuration)));
    }

    /**
     * Уведомляем, указывая итоговое время (дни/часы/минуты) и предупреждаем о потере ресурсов.
     */
    private function notifyCraftStarted(array $character, \DateTime $startTime, \DateTime $endTime, int $qty): ServerResponse
    {
        $interval     = $startTime->diff($endTime);
        $totalMinutes = $interval->days * 1440 + $interval->h * 60 + $interval->i;
        $timeString   = $this->formatMinutes($totalMinutes);

        $text = "*Процесс крафта запущен*\n\n"
            . "Ты создаёшь: *💉 Стимулятор* x{$qty} шт.\n\n"
            . "Время крафта: *{$timeString}* ⏱️\n\n"
            . "После завершения будет добавлено *{$qty}* шт. в твой инвентарь.\n\n"
            . "❗Прерывание задачи = потеря ресурсов!\n\n"
            . "_О готовности узнаешь в сообщении._ 🎁\n";

        $imagePath = base_url('uploads/telegram/craft/liquid_mixture_of_very_invigorating_acid-green_beverage.jpg');
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendPhoto([
            'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
        ]);
    }

    /**
     * Форматируем минуты в X дней Y часов Z минут.
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
     * Подбираем форму слова для дней/часов/минут.
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
     * Отправка ошибок.
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
