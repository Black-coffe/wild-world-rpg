<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Armor;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
use App\Models\CraftedItemsModel;
use App\Models\CraftedItemsLogModel;
use App\Models\TaskModel;
use App\Models\CharacterTaskModel;
use App\Models\ClaimedCellModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class StartCraftLeatherJacket2Action extends BaseAction
{
    protected $characterModel;
    protected $charResourceModel;
    protected $craftedItemsModel;
    protected $craftedItemsLogModel;
    protected $taskModel;
    protected $characterTaskModel;
    protected $claimModel;

    private int $quantity = 1;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);

        $this->characterModel     = new CharacterModel();
        $this->charResourceModel  = new CharacterResourceModel();
        $this->craftedItemsModel  = new CraftedItemsModel();
        $this->craftedItemsLogModel = new CraftedItemsLogModel();
        $this->taskModel          = new TaskModel();
        $this->characterTaskModel = new CharacterTaskModel();
        $this->claimModel         = new ClaimedCellModel();

        // Пример разбора callback_data: "startCraftLeatherJacket2_10"
        $data  = $callbackQuery->getData();
        $parts = explode('_', $data);
        if (isset($parts[1]) && is_numeric($parts[1])) {
            $this->quantity = (int) $parts[1];
        }
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendError($chatId, 'Персонаж не найден.');
        }

        // Проверка переезда (блокировка)
        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $chatId
        )) {
            return Request::emptyResponse();
        }

        // Проверка, есть ли база
        $base = $this->claimModel->where('character_id', $character['id'])->first();
        if (!$base) {
            return $this->sendError($chatId, 'У вас нет базы, крафт невозможен.');
        }

        // ----- Повторяем проверки (лучшая практика) -----

        // 1) золото
        $requiredGold = 700;
        $totalGoldNeeded = $requiredGold * $this->quantity;
        if ($character['gold'] < $totalGoldNeeded) {
            return $this->sendError(
                $chatId,
                "Недостаточно золота. Нужно {$totalGoldNeeded}, у вас {$character['gold']}."
            );
        }

        // 2) Крафтовые предметы: 'Складной нож' => 1, 'Ткань' => 8
        $craftedNeed = [
            'Складной нож' => 1,
            'Ткань'        => 8,
        ];

        foreach ($craftedNeed as $itemName => $qtyPerOne) {
            $totalNeeded = $qtyPerOne * $this->quantity;
            $craftedItem = $this->craftedItemsModel->getCraftedItemByName($itemName);
            if (!$craftedItem) {
                return $this->sendError($chatId, "Не найден предмет «{$itemName}» в crafted_items!");
            }
            $logRow = $this->craftedItemsLogModel
                ->where('character_id', $character['id'])
                ->where('crafted_item_id', $craftedItem['id'])
                ->first();
            $haveQty = $logRow ? (int)$logRow['quantity'] : 0;
            if ($haveQty < $totalNeeded) {
                return $this->sendError($chatId, "Недостаточно «{$itemName}»: нужно {$totalNeeded}, у вас {$haveQty}.");
            }
        }

        // 3) Сырьевые ресурсы: Кожа животных (x4), Древесина (x2)
        $rawNeeded = [
            ['name' => 'Кожа животных', 'qty' => 4],
            ['name' => 'Древесина',     'qty' => 2],
        ];

        foreach ($rawNeeded as $res) {
            $totalNeeded = $res['qty'] * $this->quantity;
            $resourceRow = (new \App\Models\ResourceModel())->getResourceByName($res['name']);
            if (!$resourceRow) {
                return $this->sendError($chatId, "Ресурс «{$res['name']}» не найден в таблице resources!");
            }
            $charRes = $this->charResourceModel
                ->where('id_characters', $character['id'])
                ->where('id_resources', $resourceRow['id'])
                ->first();
            $haveQty = $charRes ? (int)$charRes['quantity'] : 0;
            if ($haveQty < $totalNeeded) {
                return $this->sendError(
                    $chatId,
                    "Недостаточно «{$res['name']}»: нужно {$totalNeeded}, у вас {$haveQty}."
                );
            }
        }

        // ----- Списываем -----
        // 1) Золото
        $this->characterModel
            ->where('id', $character['id'])
            ->set('gold', 'gold - ' . $totalGoldNeeded, false)
            ->update();

        // 2) Крафтовые предметы
        foreach ($craftedNeed as $itemName => $qtyPerOne) {
            $totalNeeded = $qtyPerOne * $this->quantity;
            $craftedItem = $this->craftedItemsModel->getCraftedItemByName($itemName);
            $logRow = $this->craftedItemsLogModel
                ->where('character_id', $character['id'])
                ->where('crafted_item_id', $craftedItem['id'])
                ->first();
            $newQty = $logRow['quantity'] - $totalNeeded;
            $this->craftedItemsLogModel->update($logRow['id'], ['quantity' => $newQty]);
        }

        // 3) Сырьевые ресурсы
        foreach ($rawNeeded as $res) {
            $totalNeeded = $res['qty'] * $this->quantity;
            $resourceRow = (new \App\Models\ResourceModel())->getResourceByName($res['name']);
            $this->charResourceModel
                ->where('id_characters', $character['id'])
                ->where('id_resources', $resourceRow['id'])
                ->set('quantity', 'quantity - ' . $totalNeeded, false)
                ->update();
        }

        // ----- Создаём запись задачи крафта -----
        $taskRow = $this->taskModel->where('name', 'craftLeatherJacket')->first();
        if (!$taskRow) {
            $newTaskId = $this->taskModel->insert([
                'name'                       => 'craftLeatherJacket',
                'name_rus'                   => 'Крафт Кожаной куртки',
                'description'                => 'Процесс крафта LeatherJacket',
                'min_duration'               => 5,
                'max_duration'               => 15,
                'type'                       => 'craft',
                'difficulty_level'           => 4,
                'execution_limit'            => 0,
                'parallel_execution_allowed' => 1,
                'interruptible'              => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $taskRow = ['id' => $newTaskId, 'name' => 'craftLeatherJacket'];
        }

        // === Добавляем проверку, чтобы не начать второй крафт параллельно ===
        $activeTask = $this->characterTaskModel
            ->where('character_id', $character['id'])
            ->where('task_id', $taskRow['id'])
            ->where('status', 'in_work')
            ->first();
        if ($activeTask) {
            return $this->sendError(
                $chatId,
                'У вас уже идёт крафт «Кожаной куртки»! Дождитесь завершения предыдущего крафта.'
            );
        }

        // Допустим 10 минут за 1 шт.
        $timeForOne = 10;
        $totalTime  = $timeForOne * $this->quantity;
        $startTime  = new \DateTime();
        $endTime    = (clone $startTime)->add(new \DateInterval("PT{$totalTime}M"));

        $this->characterTaskModel->insert([
            'character_id'     => $character['id'],
            'telegram_user_id' => $user['id'],
            'task_id'          => $taskRow['id'],
            'start_time'       => $startTime->format('Y-m-d H:i:s'),
            'end_time'         => $endTime->format('Y-m-d H:i:s'),
            'status'           => 'in_work',
            'task_settings'    => json_encode([
                'item_crafted' => 'LeatherJacket',
                'quantity'     => $this->quantity,
            ]),
        ]);

        // Снимаем "часики"
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);

        $text = "*Начат крафт {$this->quantity} шт. «Кожаной куртки»!*\n"
            . "Общее время: ~{$totalTime} мин.\n"
            . "По окончании вы получите результат.\n";

        // Отправляем финальное сообщение
        $imagePath = base_url('uploads/telegram/craft/standard/leather_jacket.jpg');
        return Request::sendPhoto([
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    private function sendError($chatId, $msg): ServerResponse
    {
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);
        return Request::sendMessage([
            'chat_id'    => $chatId,
            'text'       => $msg,
            'parse_mode' => 'Markdown',
        ]);
    }
}
