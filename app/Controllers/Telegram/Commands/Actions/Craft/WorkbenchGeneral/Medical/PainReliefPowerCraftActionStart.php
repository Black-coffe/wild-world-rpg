<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Medical;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\ActiveEventModel;
use App\Models\CharacterResourceModel;
use App\Models\EventModel;
use App\Models\TaskModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class PainReliefPowerCraftActionStart extends BaseAction
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

        // Пытаемся извлечь количество из callback_data вида "craftPainReliefPower_10"
        $data = $callbackQuery->getData();
        $parts = explode('_', $data);
        if (isset($parts[1]) && is_numeric($parts[1])) {
            $this->quantity = (int)$parts[1];
        }
    }

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendError('Пользователь не найден в базе или персонаж не определён.');
        }

        // Повторная проверка ресурсов (умножая нормы на $this->quantity)
        if (!$this->checkAndDeductResources($character['id'], $this->quantity)) {
            return $this->sendError("Недостаточно ресурсов для крафта {$this->quantity} шт.");
        }

        // Запуск процесса крафта
        return $this->startCraftingProcess($character, $user['id'], $this->quantity);
    }

    /**
     * Проверяем и списываем ресурсы с учётом выбранного количества.
     */
    private function checkAndDeductResources(int $characterId, int $qty): bool
    {
        // Базовые требования на 1 шт.
        $requiredResources = [
            'Ядовитые растения'       => 1,
            'Кора деревьев'           => 3,
            'Береговая растительность'=> 2,
            'Цветы орхидей'           => 1,
        ];

        foreach ($requiredResources as $resourceName => $reqPerItem) {
            $totalNeed = $reqPerItem * $qty;
            $resource  = $this->characterResourceModel->getResourceByNameAndCharacterId($resourceName, $characterId);
            if (!$resource || $resource['quantity'] < $totalNeed) {
                return false;
            } else {
                // Списываем
                $charRes = $this->characterResourceModel
                    ->where('id_characters', $characterId)
                    ->where('id_resources', $resource['id'])
                    ->first();

                $newQuantity = $charRes['quantity'] - $totalNeed;
                $this->characterResourceModel->update($charRes['id'], ['quantity' => $newQuantity]);
            }
        }
        return true;
    }

    /**
     * Создаём запись в character_tasks, учитывая нужное количество.
     */
    private function startCraftingProcess(array $character, int $userId, int $qty): ServerResponse
    {
        // Ищем задачу craftPainReliefPower
        $craftTask = $this->taskModel->where('name', 'craftPainReliefPower')->first();
        if (!$craftTask) {
            return $this->sendError('Задача "Крафт Обезболивающего порошка" не найдена в базе.');
        }

        // Проверяем, нет ли уже активной задачи
        $activeTask = $this->characterTaskModel->where([
            'character_id' => $character['id'],
            'task_id'      => $craftTask['id'],
            'status'       => 'in_work'
        ])->first();

        if ($activeTask) {
            return $this->sendError(
                "Извини, но у тебя уже идёт крафт этого предмета. Дождись окончания, " .
                "либо прерви, но имей в виду, что ресурсы не вернутся!"
            );
        }

        // Базовая длительность на 1 шт.
        $durationForOne = $this->calculateCraftingDuration($character, $craftTask);
        // Итоговое время = базовое * кол-во
        $totalDuration  = $durationForOne * $qty;

        $startTime = new \DateTime();
        $endTime   = (clone $startTime)->add(new \DateInterval('PT' . $totalDuration . 'M'));

        // Сохраняем количество в task_settings
        $taskSettings = json_encode(['quantity' => $qty]);

        // Создаём запись
        $this->characterTaskModel->save([
            'character_id'     => $character['id'],
            'telegram_user_id' => $userId,
            'task_id'          => $craftTask['id'],
            'start_time'       => $startTime->format('Y-m-d H:i:s'),
            'end_time'         => $endTime->format('Y-m-d H:i:s'),
            'status'           => 'in_work',
            'task_settings'    => $taskSettings,
        ]);

        // Уведомляем игрока
        return $this->notifyCraftStarted($character, $startTime, $endTime, $qty);
    }

    /**
     * Формула расчёта длительности (на 1 шт.), аналогична тому, что было.
     */
    private function calculateCraftingDuration(array $character, array $craftTask): int
    {
        $experience = $character['experience'];
        $agility    = $character['agility'];
        $intellect  = $character['intellect'];

        // Весовые коэффициенты
        $expFactor = 0.3;
        $agiFactor = 0.3;
        $intFactor = 0.4;

        $attributeScore    = ($experience * $expFactor) + ($agility * $agiFactor) + ($intellect * $intFactor);
        $maxAttributeScore = 1000 * ($expFactor + $agiFactor + $intFactor);
        $normalizedScore   = $attributeScore / $maxAttributeScore;

        $minDuration = $craftTask['min_duration'];
        $maxDuration = $craftTask['max_duration'];

        $adjustedDuration = $minDuration + ($maxDuration - $minDuration) * (1 - $normalizedScore);
        $final = max($minDuration, min($maxDuration, round($adjustedDuration)));

        return $final;
    }

    /**
     * Отправляем сообщение игроку о запущенном процессе.
     * Выводим время в формате "X дней Y часов Z минут".
     */
    private function notifyCraftStarted(array $character, \DateTime $startTime, \DateTime $endTime, int $qty): ServerResponse
    {
        $interval = $startTime->diff($endTime);
        $totalMinutes = $interval->days * 1440 + $interval->h * 60 + $interval->i;
        // Разбиваем на дни, часы, минуты (как показывалось ранее)
        $timeString = $this->formatMinutes($totalMinutes);

        $text = "*Процесс крафта запущен*\n\n"
            . "Ты создаёшь: 🌡️ *Обезболивающий порошок* x{$qty} шт.\n\n"
            . "Время крафта: *{$timeString}* ⏱️\n\n"
            . "После завершения будет добавлено *{$qty}* шт. в твой инвентарь.\n\n"
            . "❗Прерывание задачи приведёт к полной потере ресурсов!\n\n"
            . "_О готовности узнаешь в сообщении._ 🎁";

        $imagePath = base_url('uploads/telegram/craft/analgesic_powder.jpg');
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendPhoto([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    /**
     * Форматируем минуты в строку "X дней Y часов Z минут".
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
        if (empty($parts)) {
            return "0 минут";
        }

        return implode(' ', $parts);
    }

    /**
     * Упрощённая функция выбора правильной формы слова (день/дня/дней).
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
     * Отправка ошибки.
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
