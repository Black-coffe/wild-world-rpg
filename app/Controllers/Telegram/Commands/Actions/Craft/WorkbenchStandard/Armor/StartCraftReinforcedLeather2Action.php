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

/**
 * Класс запуска задачи крафта "Усиленная кожаная куртка" (ReinforcedLeatherJacket).
 * Делает повторные проверки (чтобы нельзя было обойти логику первого шага),
 * создаёт запись в character_tasks, списывает золото/ресурсы/крафтовые предметы и
 * задаёт время крафта.
 */
class StartCraftReinforcedLeather2Action extends BaseAction
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

        $this->characterModel       = new CharacterModel();
        $this->charResourceModel    = new CharacterResourceModel();
        $this->craftedItemsModel    = new CraftedItemsModel();
        $this->craftedItemsLogModel = new CraftedItemsLogModel();
        $this->taskModel            = new TaskModel();
        $this->characterTaskModel   = new CharacterTaskModel();
        $this->claimModel           = new ClaimedCellModel();

        // Разбираем callback_data вида: "startCraftReinforcedLeather2_10"
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

        // Блокируем, если идёт переезд
        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $chatId
        )) {
            return Request::emptyResponse();
        }

        // Проверка базы
        $base = $this->claimModel->where('character_id', $character['id'])->first();
        if (!$base) {
            return $this->sendError($chatId, 'У вас нет базы, крафт невозможен.');
        }

        // --- Проверки, идентичные первому шагу ---
        // 0) Требования к силе и уровню
        $minStrength = 5;
        $minLevel    = 3;
        if ($character['strength'] < $minStrength) {
            return $this->sendError(
                $chatId,
                "Недостаточная сила: требуется {$minStrength}, у вас {$character['strength']}."
            );
        }
        if ($character['level'] < $minLevel) {
            return $this->sendError(
                $chatId,
                "Недостаточный уровень: требуется {$minLevel}, у вас {$character['level']}."
            );
        }

        // 1) Золото
        $requiredGold = 1200; // как указано в первом классе
        $totalGoldNeeded = $requiredGold * $this->quantity;
        if ($character['gold'] < $totalGoldNeeded) {
            return $this->sendError(
                $chatId,
                "Недостаточно золота: нужно {$totalGoldNeeded}, у вас {$character['gold']}."
            );
        }

        // 2) Крафтовые предметы
        $craftedNeed = [
            'Складной нож'      => 1,
            'Ткань'             => 12,
            'Металл фрагменты'  => 1,
        ];

        foreach ($craftedNeed as $itemName => $qtyPerOne) {
            $totalNeeded = $qtyPerOne * $this->quantity;
            $craftedItem = $this->craftedItemsModel->getCraftedItemByName($itemName);
            if (!$craftedItem) {
                return $this->sendError($chatId, "Предмет «{$itemName}» не найден в crafted_items!");
            }
            $logRow = $this->craftedItemsLogModel
                ->where('character_id', $character['id'])
                ->where('crafted_item_id', $craftedItem['id'])
                ->first();
            $haveQty = $logRow ? (int)$logRow['quantity'] : 0;
            if ($haveQty < $totalNeeded) {
                return $this->sendError(
                    $chatId,
                    "Недостаточно «{$itemName}»: нужно {$totalNeeded}, у вас {$haveQty}."
                );
            }
        }

        // 3) Сырьевые ресурсы
        // В оригинале: (Кожа животных х6, Древесина х4, Металлолом х3)
        // Тут: ['Редкие металлы'=>3] => можно поменять обратно, если нужно "Металлолом".
        $rawNeeded = [
            ['name' => 'Кожа животных', 'qty' => 6],
            ['name' => 'Древесина',     'qty' => 4],
            ['name' => 'Редкие металлы', 'qty' => 3],
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

        // Списываем золото/предметы/ресурсы
        // 1) золото
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

        // Создаём (или находим) запись задачи "craftReinforcedLeatherJacket"
        $taskRow = $this->taskModel->where('name', 'craftReinforcedLeatherJacket')->first();
        if (!$taskRow) {
            $newTaskId = $this->taskModel->insert([
                'name'                       => 'craftReinforcedLeatherJacket',
                'name_rus'                   => 'Крафт Усиленной кожаной куртки',
                'description'                => 'Процесс крафта ReinforcedLeatherJacket',
                'min_duration'               => 10,
                'max_duration'               => 30,
                'type'                       => 'craft',
                'difficulty_level'           => 5,
                'execution_limit'            => 0,
                'parallel_execution_allowed' => 1,
                'interruptible'              => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $taskRow = ['id' => $newTaskId, 'name' => 'craftReinforcedLeatherJacket'];
        }

        // === Проверка на уже активный крафт (status=in_work) этой же задачи ===
        $activeTask = $this->characterTaskModel
            ->where('character_id', $character['id'])
            ->where('task_id', $taskRow['id'])
            ->where('status', 'in_work')
            ->first();
        if ($activeTask) {
            return $this->sendError(
                $chatId,
                'У вас уже идёт крафт «Усиленной кожаной куртки»! Дождитесь завершения предыдущего.'
            );
        }

        // Допустим 15 минут за 1 шт.
        $timeForOne = 15;
        $totalTime  = $timeForOne * $this->quantity;
        $startTime  = new \DateTime();
        $endTime    = (clone $startTime)->add(new \DateInterval("PT{$totalTime}M"));

        // Вставляем задачу
        $this->characterTaskModel->insert([
            'character_id'     => $character['id'],
            'telegram_user_id' => $user['id'],
            'task_id'          => $taskRow['id'],
            'start_time'       => $startTime->format('Y-m-d H:i:s'),
            'end_time'         => $endTime->format('Y-m-d H:i:s'),
            'status'           => 'in_work',
            'task_settings'    => json_encode([
                'item_crafted' => 'ReinforcedLeatherJacket',
                'quantity'     => $this->quantity,
            ]),
        ]);

        // Снимаем "часики"
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);

        // Итоговое сообщение
        $scope      = new \App\Services\Tasks\ActionScopeService();
        $background = $scope->isBackground($taskRow['parallel_execution_allowed'] ?? 1);
        $text = "*Начат крафт {$this->quantity} шт. «Усиленной кожаной куртки»!*\n\n"
            . $scope->startedBlock(\App\Services\Tasks\ActionScopeService::KIND_CRAFT, $background) . "\n\n"
            . "Общее время: ~{$totalTime} мин.\n"
            . "По завершении вы получите результат.\n";

        $imagePath = base_url('uploads/telegram/craft/standard/reinforced_leather_jacket.jpg');
        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
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
