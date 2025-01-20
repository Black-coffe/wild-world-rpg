<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Tools;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\ActiveEventModel;
use App\Models\CharacterResourceModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\EventModel;
use App\Models\TaskModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class FoldingKnifeCraftActionStart extends BaseAction
{
    protected $taskModel;
    protected $eventModel;
    protected $activeEventModel;
    protected $characterResourceModel;
    protected $craftedItemsModel;
    protected $craftedItemsLogModel;

    /**
     * Количество ножей, извлекаемое из callback_data (по умолчанию 1).
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

        // Пример callback_data="craftFoldingKnife_10" -> 10
        $data  = $callbackQuery->getData();
        $parts = explode('_', $data);
        if (isset($parts[1]) && is_numeric($parts[1])) {
            $this->quantity = (int)$parts[1];
        }
    }

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendError("Пользователь или персонаж не найден.");
        }

        // Проверяем ресурсы на $this->quantity
        if (!$this->checkAndDeductResources($character['id'], $this->quantity)) {
            return $this->sendError("Недостаточно ресурсов для крафта {$this->quantity} шт. ножей.");
        }

        // Запускаем процесс крафта
        return $this->startCraftingProcess($character, $user['id'], $this->quantity);
    }

    /**
     * Проверяем и списываем ресурсы, умножая нормы на $qty.
     */
    private function checkAndDeductResources(int $characterId, int $qty): bool
    {
        // Нормы на 1 шт.
        $requiredResources = [
            'Древесина'     => 2,
            'Железная руда' => 36,
            'Кожа животных' => 1,
            'Камни'         => 2,
        ];

        foreach ($requiredResources as $resName => $perItem) {
            $needTotal = $perItem * $qty;
            $resource  = $this->characterResourceModel->getResourceByNameAndCharacterId($resName, $characterId);

            if (!$resource || $resource['quantity'] < $needTotal) {
                return false;
            }

            // Списываем
            $charRes = $this->characterResourceModel
                ->where('id_characters', $characterId)
                ->where('id_resources', $resource['id'])
                ->first();

            $newQty = $charRes['quantity'] - $needTotal;
            $this->characterResourceModel->update($charRes['id'], ['quantity' => $newQty]);
        }

        return true;
    }

    /**
     * Создаём запись в character_tasks (учитывая $qty),
     * сохраняем $qty в task_settings и рассчитываем общее время крафта.
     */
    private function startCraftingProcess(array $character, int $userId, int $qty): ServerResponse
    {
        // Ищем задачу craftFoldingKnife
        $craftTask = $this->taskModel->where('name', 'craftFoldingKnife')->first();
        if (!$craftTask) {
            return $this->sendError('Задача "Крафт Складного ножа" не найдена в базе.');
        }

        // Проверяем, нет ли уже активной задачи
        $activeTask = $this->characterTaskModel->where([
            'character_id' => $character['id'],
            'task_id'      => $craftTask['id'],
            'status'       => 'in_work'
        ])->first();

        if ($activeTask) {
            return $this->sendError(
                "У тебя уже идёт крафт ножа! Дождись окончания или прерви," .
                " но ресурсы не вернутся при прерывании."
            );
        }

        // Базовое время (на 1 шт.)
        $durationForOne = $this->calculateCraftingDuration($character, $craftTask);
        // Итоговое время = базовое × qty
        $totalDuration  = $durationForOne * $qty;

        $startTime = new \DateTime();
        $endTime   = (clone $startTime)->add(new \DateInterval('PT' . $totalDuration . 'M'));

        // Запоминаем кол-во в task_settings
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

        return $this->notifyCraftStarted($character, $startTime, $endTime, $qty);
    }

    /**
     * Формула для расчёта времени (1 шт.) — чем выше атрибуты, тем меньше время.
     */
    private function calculateCraftingDuration(array $character, array $craftTask): int
    {
        $experience = $character['experience'];
        $agility    = $character['agility'];
        $intellect  = $character['intellect'];

        $expFactor = 0.3;
        $agiFactor = 0.3;
        $intFactor = 0.4;

        $attrScore      = ($experience * $expFactor) + ($agility * $agiFactor) + ($intellect * $intFactor);
        $maxAttrScore   = 1000 * ($expFactor + $agiFactor + $intFactor);
        $normalized     = $maxAttrScore > 0 ? ($attrScore / $maxAttrScore) : 0;

        $minDuration = $craftTask['min_duration'];
        $maxDuration = $craftTask['max_duration'];

        $adjusted = $minDuration + ($maxDuration - $minDuration) * (1 - $normalized);
        $final    = max($minDuration, min($maxDuration, round($adjusted)));

        return $final;
    }

    /**
     * Уведомляем игрока о суммарном времени (минуты -> дни/часы/минуты) и прерывании.
     */
    private function notifyCraftStarted(array $character, \DateTime $startTime, \DateTime $endTime, int $qty): ServerResponse
    {
        $interval     = $startTime->diff($endTime);
        $totalMinutes = $interval->days * 1440 + $interval->h * 60 + $interval->i;
        $timeStr      = $this->formatMinutes($totalMinutes);

        $text = "*Процесс крафта запущен*\n\n"
            . "Ты создаёшь: *🔪 Складной нож* x{$qty} шт.\n\n"
            . "Время крафта: *{$timeStr}* ⏱️\n\n"
            . "После завершения будет добавлено *{$qty}* шт. в инвентарь.\n\n"
            . "❗Прерывание задачи = потеря ресурсов!\n\n"
            . "_О готовности узнаешь в сообщении._ 🎁";

        $imagePath = base_url('uploads/telegram/craft/huge_mechanical_workbench.jpg');
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendPhoto([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    /**
     * Преобразование минут -> строка "X дней Y часов Z минут".
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
     * Подбор правильной формы слова (русский язык).
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
     * Вывод ошибок.
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
