<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Components;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\TaskModel;
use App\Models\CharacterTaskModel;
use App\Models\CharacterResourceModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use App\Services\Tasks\ActiveTasksService;

/**
 * Класс WiringCraftActionStart:
 * Запуск количественного крафта "Проводки" (🔌).
 * Списывает ресурсы (Мхи) и крафтовые предметы (metalFragments), создаёт запись в character_tasks.
 */
class WiringCraftActionStart extends BaseAction
{
    protected $taskModel;
    protected $characterTaskModel;
    protected $characterResourceModel;
    protected $craftedItemsModel;
    protected $craftedItemsLogModel;

    private int $quantity = 1;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);

        $this->taskModel              = new TaskModel();
        $this->characterTaskModel     = new CharacterTaskModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->craftedItemsModel      = new CraftedItemsModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();

        // Извлекаем число из callback_data (напр. "craftWiring_10")
        $data  = $callbackQuery->getData();
        $parts = explode('_', $data);
        if (isset($parts[1]) && is_numeric($parts[1])) {
            $this->quantity = (int)$parts[1];
        }
    }

    public function handle(): ServerResponse
    {
        // Убираем "часики" на инлайн-кнопке
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendError("Персонаж не найден или пользователь не определён.");
        }

        // Проверка активного переезда
        if ((new ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $this->callbackQuery->getMessage()->getChat()->getId()
        )) {
            return Request::emptyResponse();
        }

        // Ищем задачу craftWiring (должна быть в tasks)
        $taskRow = $this->taskModel->where('name', 'craftWiring')->first();
        if (!$taskRow) {
            return $this->sendError("Задача 'craftWiring' не найдена в таблице tasks!");
        }

        // Проверка, нет ли уже активного крафта "Проводки"
        $existing = $this->characterTaskModel->where([
            'character_id' => $character['id'],
            'task_id'      => $taskRow['id'],
            'status'       => 'in_work'
        ])->first();

        if ($existing) {
            return $this->sendError("У тебя уже идёт крафт Проводки! Дождись окончания или прерви.");
        }

        // Списываем ресурсы
        if (!$this->checkAndDeductResources($character['id'], $this->quantity)) {
            return $this->sendError("Недостаточно ресурсов или крафтовых предметов для {$this->quantity} шт. Проводки!");
        }

        // Запускаем задачу в character_tasks
        return $this->startCraftTask($character, $user['id'], $taskRow, $this->quantity);
    }

    /**
     * Проверка и списание:
     * - "Мхи" (resources) × 2 × quantity
     * - "metalFragments" (crafted_items) × 3 × quantity
     */
    private function checkAndDeductResources(int $charId, int $qty): bool
    {
        // Для 1 шт.:
        $required = [
            'resources' => [
                'Мхи' => 2,
            ],
            'crafted_items' => [
                'metalFragments' => 3,
            ]
        ];

        // 1) Обычные ресурсы (Мхи)
        foreach ($required['resources'] as $resName => $needPerItem) {
            $totalNeed = $needPerItem * $qty;
            // Ищем ресурс
            $resRow = $this->characterResourceModel->getResourceByNameAndCharacterId($resName, $charId);
            if (!$resRow || $resRow['quantity'] < $totalNeed) {
                return false; // Не хватает
            }
        }

        // 2) Крафтовые предметы (metalFragments)
        foreach ($required['crafted_items'] as $itemEng => $needPerItem) {
            $totalNeed = $needPerItem * $qty;
            // Находим ID предмета:
            $itemId = $this->craftedItemsModel->getIdByName($itemEng);
            if (!$itemId) {
                return false; // Вдруг нет в таблице crafted_items
            }

            // Проверяем лог (crafted_items_log)
            $logRow = $this->craftedItemsLogModel->getItemByCraftedItemIdAndCharacterId($itemId, $charId);
            if (!$logRow || $logRow['quantity'] < $totalNeed) {
                return false; // Не хватает
            }
        }

        // Если всего хватает — списываем
        // (1) Список обычных ресурсов:
        foreach ($required['resources'] as $resName => $needPerItem) {
            $totalNeed = $needPerItem * $qty;
            $this->characterResourceModel->deductResourceByName($resName, $charId, $totalNeed);
        }
        // (2) Список крафтовых
        foreach ($required['crafted_items'] as $itemEng => $needPerItem) {
            $totalNeed = $needPerItem * $qty;
            $itemId    = $this->craftedItemsModel->getIdByName($itemEng);
            $this->craftedItemsLogModel->deductCraftedItem($itemId, $charId, $totalNeed);
        }

        return true;
    }

    /**
     * Создаём запись в `character_tasks` со временем крафта (мин_duration..max_duration).
     */
    private function startCraftTask(array $char, int $userId, array $taskRow, int $qty): ServerResponse
    {
        $start = new \DateTime();

        // Примерно: min=10, max=15
        $minDur = $taskRow['min_duration'] ?? 10;
        $maxDur = $taskRow['max_duration'] ?? 15;

        $timePerOne = $this->calculateTimeByAttributes($char, $minDur, $maxDur);
        $totalTime  = $timePerOne * $qty;

        $end = (clone $start)->add(new \DateInterval("PT{$totalTime}M"));

        // Сохраняем quantity в task_settings
        $taskSettings = [
            'quantity' => $qty,
        ];

        $this->characterTaskModel->insert([
            'character_id'     => $char['id'],
            'telegram_user_id' => $userId,
            'task_id'          => $taskRow['id'],
            'start_time'       => $start->format('Y-m-d H:i:s'),
            'end_time'         => $end->format('Y-m-d H:i:s'),
            'status'           => 'in_work',
            'task_settings'    => json_encode($taskSettings),
        ]);

        return $this->notifyCraftStarted($qty, $start, $end);
    }

    private function calculateTimeByAttributes(array $char, int $minDur, int $maxDur): int
    {
        $intellect = (int)($char['intellect'] ?? 0);
        // Чем выше интеллект, тем ближе к minDur
        $factor = 1 - min(1.0, $intellect / 150.0);
        $delta  = $maxDur - $minDur;
        $time   = $maxDur - $delta * (1 - $factor);

        return max($minDur, (int)round($time));
    }

    private function notifyCraftStarted(int $qty, \DateTime $start, \DateTime $end): ServerResponse
    {
        $diff = $start->diff($end);
        $mins = $diff->days * 1440 + $diff->h*60 + $diff->i;

        $timeStr = $this->formatMinutes($mins);
        $text = "*Запущен крафт:* 🔌 Проводка x{$qty}\n"
            . "Время ~ {$timeStr}.\n"
            . "❗Прервать = потеря ресурсов.";

        $imgPath = base_url('uploads/telegram/craft/components/wiring_craft.jpg');
        return Request::sendPhoto([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'      => Request::encodeFile($imgPath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    private function formatMinutes(int $m): string
    {
        $days = intdiv($m, 1440);
        $m    = $m % 1440;
        $h    = intdiv($m, 60);
        $mins = $m % 60;

        $parts = [];
        if ($days > 0) $parts[] = "{$days}д";
        if ($h > 0)    $parts[] = "{$h}ч";
        if ($mins > 0) $parts[] = "{$mins}мин";

        return !empty($parts) ? implode(' ', $parts) : '0мин';
    }

    private function sendError(string $message): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'    => $message,
        ]);
    }
}
