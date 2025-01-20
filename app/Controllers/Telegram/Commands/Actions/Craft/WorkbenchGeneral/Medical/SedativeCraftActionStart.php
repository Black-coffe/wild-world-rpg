<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Medical;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\ActiveEventModel;
use App\Models\CharacterResourceModel;
use App\Models\EventModel;
use App\Models\TaskModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class SedativeCraftActionStart extends BaseAction
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

        // Пример: callback_data = "craftSedative_10" => извлечём 10
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
            return $this->sendError("Пользователь не найден в базе данных или персонаж не определён.");
        }

        // Проверка ресурсов на $this->quantity
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
        // Нормы на 1 шт.
        $requiredResources = [
            'Цветы орхидей' => 1,
            'Травы'         => 2,
            'Вода'          => 25,
        ];

        foreach ($requiredResources as $resName => $baseAmount) {
            $totalNeed = $baseAmount * $qty;
            $resource  = $this->characterResourceModel->getResourceByNameAndCharacterId($resName, $characterId);

            if (!$resource || $resource['quantity'] < $totalNeed) {
                return false;
            }

            // Списываем
            $charRes = $this->characterResourceModel
                ->where('id_characters', $characterId)
                ->where('id_resources', $resource['id'])
                ->first();

            $newQty = $charRes['quantity'] - $totalNeed;
            $this->characterResourceModel->update($charRes['id'], ['quantity' => $newQty]);
        }

        return true;
    }

    /**
     * Создаём запись в character_tasks, учитывая $qty,
     * и рассчитываем итоговое время.
     */
    private function startCraftingProcess(array $character, int $userId, int $qty): ServerResponse
    {
        // Ищем задачу craftSedative
        $craftTask = $this->taskModel->where('name', 'craftSedative')->first();
        if (!$craftTask) {
            return $this->sendError('Задача "Крафт Успокоительного" не найдена в базе.');
        }

        // Проверяем, нет ли уже активной задачи
        $activeTask = $this->characterTaskModel->where([
            'character_id' => $character['id'],
            'task_id'      => $craftTask['id'],
            'status'       => 'in_work',
        ])->first();

        if ($activeTask) {
            return $this->sendError(
                "У тебя уже идёт крафт успокоительного! Дождись окончания или прерви," .
                " но имей в виду, что ресурсы не вернутся при прерывании."
            );
        }

        // Базовое время (на 1 шт.)
        $durationForOne = $this->calculateCraftingDuration($character, $craftTask);
        // Умножаем на кол-во
        $totalDuration  = $durationForOne * $qty;

        $startTime = new \DateTime();
        $endTime   = (clone $startTime)->add(new \DateInterval('PT' . $totalDuration . 'M'));

        // Сохраняем qty в task_settings
        $taskSettings = [
            'quantity' => $qty
        ];

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
     * Логика расчёта длительности (на 1 шт.).
     */
    private function calculateCraftingDuration(array $character, array $craftTask): int
    {
        $experience = $character['experience'];
        $agility    = $character['agility'];
        $intellect  = $character['intellect'];

        $expFactor = 0.3;
        $agiFactor = 0.3;
        $intFactor = 0.4;

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
     * Уведомляем игрока об общем времени (на все $qty шт.),
     * предупреждаем о потере ресурсов при прерывании.
     */
    private function notifyCraftStarted(array $character, \DateTime $startTime, \DateTime $endTime, int $qty): ServerResponse
    {
        $interval     = $startTime->diff($endTime);
        $totalMinutes = $interval->days * 1440 + $interval->h * 60 + $interval->i;
        $timeString   = $this->formatMinutes($totalMinutes);

        $text = "*Процесс крафта запущен*\n\n"
            . "Ты создаёшь: 🫖 *Успокоительное* x{$qty} шт.\n\n"
            . "Время крафта: *{$timeString}* ⏱️\n\n"
            . "После завершения будет добавлено *{$qty}* шт. в твой инвентарь.\n\n"
            . "❗Если прервёшь задачу, все ресурсы пропадут!\n\n"
            . "_О готовности узнаешь в сообщении._ 🎁\n";

        $imagePath = base_url('uploads/telegram/craft/dry_herb_tea.jpg');
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendPhoto([
            'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
        ]);
    }

    /**
     * Форматирует общее кол-во минут в "X дней Y часов Z минут".
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
     * Выбираем правильную форму (день/дня/дней).
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
     * Метод отправки ошибок.
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
