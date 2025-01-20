<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Medical;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\ActiveEventModel;
use App\Models\CharacterResourceModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\EventModel;
use App\Models\TaskModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * Класс запуска крафта "Базовой аптечки" (BasicMedKit).
 * Поддерживает количественный крафт (1,5,10,25,50,100...).
 */
class BasicMedKitCraftActionStart extends BaseAction
{
    protected $taskModel;
    protected $eventModel;
    protected $craftedItemsModel;
    protected $craftedItemsLogModel;
    protected $activeEventModel;
    protected $characterResourceModel;

    /**
     * Количество аптечек, извлекаемое из callback_data (по умолчанию 1).
     */
    private int $quantity = 1;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);

        $this->taskModel              = new TaskModel();
        $this->eventModel             = new EventModel();
        $this->craftedItemsModel      = new CraftedItemsModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();
        $this->activeEventModel       = new ActiveEventModel();
        $this->characterResourceModel = new CharacterResourceModel();

        // Например, callback_data="craftBasicMedKit_10" -> 10
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

        // Списываем ресурсы и крафтовые предметы, умножая нормы на $this->quantity
        if (!$this->checkAndDeductResources($character['id'], $this->quantity)) {
            return $this->sendError("Недостаточно ресурсов или крафтовых предметов для {$this->quantity} аптечек.");
        }

        // Запускаем задачу крафта
        return $this->startCraftingProcess($character, $user['id'], $this->quantity);
    }

    /**
     * Проверяем и списываем как обычные ресурсы (Грибы, Мед, Алоэ, Вода),
     * так и крафтовые предметы (Bandage), умножая на $qty.
     */
    private function checkAndDeductResources(int $characterId, int $qty): bool
    {
        $requiredResources = [
            'resources' => [
                'Грибы' => 4,
                'Мед'   => 2,
                'Алоэ'  => 4,
                'Вода'  => 11,
            ],
            'crafted_items' => [
                'Bandage' => 5
            ]
        ];

        // 1) Обычные ресурсы
        foreach ($requiredResources['resources'] as $resName => $reqPerItem) {
            $totalNeed = $reqPerItem * $qty;
            $resource  = $this->characterResourceModel->getResourceByNameAndCharacterId($resName, $characterId);
            if (!$resource || $resource['quantity'] < $totalNeed) {
                return false; // Не хватает
            }
            // Списываем
            $charRes = $this->characterResourceModel
                ->where('id_characters', $characterId)
                ->where('id_resources', $resource['id'])
                ->first();

            $newQty = $charRes['quantity'] - $totalNeed;
            $this->characterResourceModel->update($charRes['id'], ['quantity' => $newQty]);
        }

        // 2) Крафтовые предметы (из crafted_items_log)
        foreach ($requiredResources['crafted_items'] as $itemNameEng => $reqPerItem) {
            $totalNeed = $reqPerItem * $qty;

            $itemId = $this->craftedItemsModel->getIdByName($itemNameEng);
            if (!$itemId) {
                log_message('error', "Крафтовый предмет (name_eng=$itemNameEng) не найден в crafted_items.");
                return false;
            }

            $logRow = $this->craftedItemsLogModel->getItemByCraftedItemIdAndCharacterId($itemId, $characterId);
            if (!$logRow || $logRow['quantity'] < $totalNeed) {
                log_message('error', "Не хватает крафтового предмета: $itemNameEng, требуется $totalNeed, у игрока " . ($logRow['quantity'] ?? 0));
                return false;
            }

            // Списываем
            $this->craftedItemsLogModel->update($logRow['id'], [
                'quantity' => $logRow['quantity'] - $totalNeed
            ]);
        }

        return true;
    }

    /**
     * Создаём запись в character_tasks: сохраняем $qty в task_settings,
     * рассчитываем итоговое время (мин_время..макс_время).
     */
    private function startCraftingProcess(array $character, int $userId, int $qty): ServerResponse
    {
        $craftTask = $this->taskModel->where('name', 'craftBasicMedKit')->first();
        if (!$craftTask) {
            return $this->sendError('Задача "Крафт Базовой аптечки" не найдена в базе.');
        }

        // Проверка, нет ли уже такой задачи in_work
        $activeTask = $this->characterTaskModel->where([
            'character_id' => $character['id'],
            'task_id'      => $craftTask['id'],
            'status'       => 'in_work'
        ])->first();

        if ($activeTask) {
            return $this->sendError("У тебя уже идёт крафт базовой аптечки! Подожди окончания или прерви.");
        }

        // Вычисляем базовую длительность (на 1 шт.) и умножаем на qty
        $durationForOne = $this->calculateCraftingDuration($character, $craftTask);
        $totalDuration  = $durationForOne * $qty;

        $startTime = new \DateTime();
        $endTime   = (clone $startTime)->add(new \DateInterval('PT' . $totalDuration . 'M'));

        // Записываем quantity в task_settings
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
     * Простейшая формула: чем выше атрибуты, тем ближе к minDuration.
     */
    private function calculateCraftingDuration(array $character, array $craftTask): int
    {
        $experience = $character['experience'];
        $agility    = $character['agility'];
        $intellect  = $character['intellect'];

        $expFactor = 0.3;
        $agiFactor = 0.3;
        $intFactor = 0.4;

        $score         = ($experience * $expFactor) + ($agility * $agiFactor) + ($intellect * $intFactor);
        $maxScore      = 1000 * ($expFactor + $agiFactor + $intFactor);
        $normalized    = $maxScore > 0 ? $score / $maxScore : 0;

        $minDuration = $craftTask['min_duration'];
        $maxDuration = $craftTask['max_duration'];
        $adjusted    = $minDuration + ($maxDuration - $minDuration) * (1 - $normalized);
        $final       = max($minDuration, min($maxDuration, round($adjusted)));

        return $final;
    }

    /**
     * Отправляем сообщение: сколько всего минут (дней/часов/минут), количество шт., и предупреждение
     * о потере ресурсов при прерывании.
     */
    private function notifyCraftStarted(array $character, \DateTime $startTime, \DateTime $endTime, int $qty): ServerResponse
    {
        $interval = $startTime->diff($endTime);
        $minutes  = $interval->days * 1440 + $interval->h * 60 + $interval->i;
        $timeStr  = $this->formatMinutes($minutes);

        $text = "*Процесс крафта запущен*\n\n"
            . "Ты создаёшь: *🚑 Базовая аптечка* x{$qty} шт.\n\n"
            . "Время крафта: *{$timeStr}* ⏱️\n\n"
            . "После завершения будет добавлено *{$qty}* шт. в твой инвентарь.\n\n"
            . "❗Прерывание задачи = потеря ресурсов!\n\n"
            . "_О готовности узнаешь в сообщении._ 🎁";

        $imagePath = base_url('uploads/telegram/craft/simple_craft_kit.jpg');
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendPhoto([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    /**
     * Преобразуем общее количество минут в "X дней Y часов Z минут".
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
     * Подбираем правильную форму слова (день/дня/дней и т.д.).
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
     * Выводим сообщение об ошибке.
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
