<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Components;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\TaskModel;
use App\Models\CharacterTaskModel;
use App\Models\CharacterResourceModel;
use App\Models\CraftedItemsModel;
use App\Models\CraftedItemsLogModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use App\Services\Tasks\ActiveTasksService;

class ElectronicComponentsCraftActionStart extends BaseAction
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

        // callback_data: "craftElectronicComponents_10" => ["craftElectronicComponents","10"]
        $data  = $callbackQuery->getData();
        $parts = explode('_', $data);
        if (!empty($parts[1]) && is_numeric($parts[1])) {
            $this->quantity = (int)$parts[1];
        }
    }

    public function handle(): ServerResponse
    {
        // Закрываем "часики" на нажатой кнопке
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // Проверяем пользователя/персонажа
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendError("Ошибка: пользователь/персонаж не найден.");
        }

        // Если идёт переезд - блокируем
        if ((new ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $this->callbackQuery->getMessage()->getChat()->getId()
        )) {
            return Request::emptyResponse();
        }

        // Ищем задачу craftElectronicComponents в таблице tasks
        $taskRow = $this->taskModel->where('name', 'craftElectronicComponents')->first();
        if (!$taskRow) {
            return $this->sendError("Не найдена задача 'craftElectronicComponents' в tasks!");
        }

        // Проверяем, нет ли уже активной
        $exists = $this->characterTaskModel->where([
            'character_id' => $character['id'],
            'task_id'      => $taskRow['id'],
            'status'       => 'in_work'
        ])->first();
        if ($exists) {
            return $this->sendError("У тебя уже идёт крафт Электронных компонентов! Дождись окончания или прерви.");
        }

        // Конкретные требования на 1 шт. (должны совпадать с ElectronicComponentsCraft1Action)
        // Здесь списываем те же самые ресурсы/предметы:
        $required = [
            'resources' => [
                'Нефть' => 3,
            ],
            'crafted_items' => [
                'metalFragments' => 5,
            ]
        ];

        // Проверяем и списываем (qty * каждый)
        if (!$this->checkAndDeduct($character['id'], $required, $this->quantity)) {
            return $this->sendError("Недостаточно ресурсов/предметов для {$this->quantity} шт. Электронных компонентов!");
        }

        // Запускаем задачу
        return $this->startCraftTask($character, $user['id'], $taskRow, $this->quantity);
    }

    /**
     * Списываем и ресурсы (resources) и крафтовые предметы (crafted_items).
     */
    private function checkAndDeduct(int $charId, array $required, int $qty): bool
    {
        // 1) Проверяем
        foreach ($required['resources'] as $resName => $needPerItem) {
            $need = $needPerItem * $qty;
            // Ищем у игрока
            $row = $this->characterResourceModel->getResourceForCraft($resName, $charId);
            $have= $row ? (int)$row['charResQty'] : 0;
            if ($have < $need) {
                return false;
            }
        }
        foreach ($required['crafted_items'] as $itemEng => $needPerItem) {
            $need = $needPerItem * $qty;
            // Находим ID крафтового предмета
            $itemId = $this->craftedItemsModel->getIdByName($itemEng);
            if (!$itemId) {
                return false;
            }
            // Проверяем, сколько у игрока
            $logRow = $this->craftedItemsLogModel->getItemByCraftedItemIdAndCharacterId($itemId, $charId);
            $have   = $logRow ? (int)$logRow['quantity'] : 0;
            if ($have < $need) {
                return false;
            }
        }

        // 2) Списываем
        // (А) Обычные ресурсы
        foreach ($required['resources'] as $resName => $needPerItem) {
            $need = $needPerItem * $qty;
            if (!$this->characterResourceModel->deductResourceForCraft($resName, $charId, $need)) {
                return false;
            }
        }
        // (Б) Крафтовые предметы
        foreach ($required['crafted_items'] as $itemEng => $needPerItem) {
            $need   = $needPerItem * $qty;
            $itemId = $this->craftedItemsModel->getIdByName($itemEng);
            // Допустим, у вас есть метод deductCraftedItem(...) — если нет, используйте subtractItem(...)
            // или сделайте метод-обёртку:
            $logRow = $this->craftedItemsLogModel->getItemByCraftedItemIdAndCharacterId($itemId, $charId);
            if (!$logRow || $logRow['quantity'] < $need) {
                return false;
            }

            // Здесь либо subtractItem($charId, $itemEng, $need),
            // либо делаем свой deductCraftedItem($itemId, $charId, $need)
            // Пример:
            $newQty = $logRow['quantity'] - $need;
            if ($newQty <= 0) {
                $this->craftedItemsLogModel->delete($logRow['id']);
            } else {
                $this->craftedItemsLogModel->update($logRow['id'], ['quantity' => $newQty]);
            }
        }

        return true;
    }

    private function startCraftTask(array $char, int $userId, array $taskRow, int $qty): ServerResponse
    {
        $start = new \DateTime();

        // Допустим, min=15, max=20
        $minDur = $taskRow['min_duration'] ?? 15;
        $maxDur = $taskRow['max_duration'] ?? 20;

        $timeForOne = $this->calcTimeByAttributes($char, $minDur, $maxDur);
        $totalTime  = $timeForOne * $qty;

        $end = (clone $start)->add(new \DateInterval("PT{$totalTime}M"));

        // Запоминаем, сколько шт. хотим создать
        $settings = [
            'quantity' => $qty,
        ];

        // Создаём запись в character_tasks
        $this->characterTaskModel->insert([
            'character_id'     => $char['id'],
            'telegram_user_id' => $userId,
            'task_id'          => $taskRow['id'],
            'start_time'       => $start->format('Y-m-d H:i:s'),
            'end_time'         => $end->format('Y-m-d H:i:s'),
            'status'           => 'in_work',
            'task_settings'    => json_encode($settings),
        ]);

        return $this->notifyStarted($qty, $start, $end);
    }

    /**
     * Простейшая формула сокращения времени крафта при высоком интеллекте.
     */
    private function calcTimeByAttributes(array $char, int $minDur, int $maxDur): int
    {
        $intellect = (int)($char['intellect'] ?? 0);
        // Чем выше интеллект, тем ближе к minDur
        $factor = 1 - min(1.0, $intellect / 200.0);
        $delta  = $maxDur - $minDur;
        $time   = $maxDur - $delta * (1 - $factor);

        return max($minDur, (int)round($time));
    }

    private function notifyStarted(int $qty, \DateTime $start, \DateTime $end): ServerResponse
    {
        $diff = $start->diff($end);
        $minutes = $diff->days * 1440 + $diff->h * 60 + $diff->i;
        $timeStr = $this->formatMinutes($minutes);

        $text = "*Начат крафт:* 💻 Электронные компоненты x{$qty}\n"
            . "Примерное время: {$timeStr}.\n"
            . "Прерывание = потеря ресурсов.";

        $img = base_url('uploads/telegram/craft/components/electronic_components.jpg');
        return Request::sendPhoto([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'      => Request::encodeFile($img),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    private function formatMinutes(int $m): string
    {
        $d = intdiv($m, 1440);
        $m = $m % 1440;
        $h = intdiv($m, 60);
        $mins = $m % 60;

        $parts = [];
        if ($d>0)   $parts[] = "{$d}д";
        if ($h>0)   $parts[] = "{$h}ч";
        if ($mins>0)$parts[] = "{$mins}мин";
        return empty($parts) ? "0мин" : implode(' ', $parts);
    }

    private function sendError(string $msg): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'    => $msg,
        ]);
    }
}
