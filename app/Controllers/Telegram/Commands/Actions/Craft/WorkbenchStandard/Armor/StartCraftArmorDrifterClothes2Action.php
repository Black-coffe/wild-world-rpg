<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Armor;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
use App\Models\CharacterTaskModel;
use App\Models\ClaimedCellModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\TaskModel;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * Аналог класса StartCraftArmorRaggedShirt2Action, но для "Одежды бродяги" (DrifterClothes).
 * Содержит проверку, чтобы нельзя было запустить второй крафт "Одежды бродяги", если один уже выполняется.
 */
class StartCraftArmorDrifterClothes2Action extends BaseAction
{
    protected $characterResourceModel;
    protected $characterModel;
    protected $buildingModel;
    protected $characterBuildingModel;
    protected $claimedCellModel;
    protected $craftedItemsModel;
    protected $craftedItemsLogModel;
    protected $characterTaskModel;
    protected $taskModel;

    private int $quantity = 1;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);

        $this->characterResourceModel   = new CharacterResourceModel();
        $this->characterModel           = new CharacterModel();
        $this->buildingModel            = new BuildingModel();
        $this->characterBuildingModel   = new CharacterBuildingModel();
        $this->claimedCellModel         = new ClaimedCellModel();
        $this->craftedItemsModel        = new CraftedItemsModel();
        $this->craftedItemsLogModel     = new CraftedItemsLogModel();
        $this->characterTaskModel       = new CharacterTaskModel();
        $this->taskModel                = new TaskModel();

        // Разбираем кол-во из callback_data (например: startCraftDrifterClothes2_10)
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
            return $this->sendError($chatId, 'Пользователь или персонаж не найден.');
        }

        // Проверка активного переезда
        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $chatId
        )) {
            return Request::emptyResponse();
        }

        // Проверяем наличие базы
        $base = $this->claimedCellModel->where('character_id', $character['id'])->first();
        if (!$base) {
            return $this->sendError($chatId, 'У вас нет построенной базы.');
        }

        // Проверяем наличие верстака 1 уровня (пример)
        $workbenchItem = $this->craftedItemsModel->where('name_eng', 'WorkbenchOne')->first();
        if (!$workbenchItem) {
            return $this->sendError($chatId, 'Не найдено "WorkbenchOne" в crafted_items!');
        }
        $wbLog = $this->craftedItemsLogModel
            ->where('character_id', $character['id'])
            ->where('crafted_item_id', $workbenchItem['id'])
            ->first();
        if (!$wbLog || $wbLog['quantity'] < 1) {
            return $this->sendError($chatId, 'У вас нет Верстака 1-го уровня (WorkbenchOne).');
        }

        // Требования (за 1 шт.) — см. ArmorDrifterClothes2Action
        $requiredGold = 500;
        $requiredComponents = [
            'Ткань' => 8,
            'Складной нож' => 1,
        ];

        // Считаем итог
        $totalGold = $requiredGold * $this->quantity;
        $goldAmount = (int) $character['gold'];
        if ($goldAmount < $totalGold) {
            return $this->sendError(
                $chatId,
                "Недостаточно золота для {$this->quantity} шт. Нужно {$totalGold}, а у вас {$goldAmount}."
            );
        }

        // Проверяем крафтовые предметы
        foreach ($requiredComponents as $itemName => $reqPerOne) {
            $needed = $reqPerOne * $this->quantity;
            $craftedItem = $this->craftedItemsModel->getCraftedItemByName($itemName);
            if (!$craftedItem) {
                return $this->sendError($chatId, "Предмет «{$itemName}» не найден в crafted_items!");
            }
            $logRow = $this->craftedItemsLogModel
                ->where('character_id', $character['id'])
                ->where('crafted_item_id', $craftedItem['id'])
                ->first();
            $haveQty = $logRow ? $logRow['quantity'] : 0;
            if ($haveQty < $needed) {
                return $this->sendError(
                    $chatId,
                    "Недостаточно «{$itemName}»: нужно {$needed}, а есть {$haveQty}."
                );
            }
        }

        // Получаем (или создаём) сам taskRow
        $taskRow = $this->taskModel->where('name', 'craftArmorDrifterClothes')->first();
        if (!$taskRow) {
            $newTaskId = $this->taskModel->insert([
                'name'                       => 'craftArmorDrifterClothes',
                'name_rus'                   => 'Крафт Одежды бродяги',
                'description'                => 'Процесс крафта Одежды бродяги',
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
            $taskRow = [
                'id' => $newTaskId,
                'name' => 'craftArmorDrifterClothes',
            ];
        }

        // --- Новая проверка --- Нельзя создавать вторую задачу крафта "Одежда бродяги",
        // если уже есть in_work
        $existingInWork = $this->characterTaskModel
            ->where('character_id', $character['id'])
            ->where('task_id', $taskRow['id']) // в том же task_id
            ->where('status', 'in_work')
            ->first();

        if ($existingInWork) {
            return $this->sendError(
                $chatId,
                'У вас уже идёт крафт "Одежды бродяги"! Дождитесь завершения прежде чем начинать новый.'
            );
        }

        // --- Списываем золото и предметы ---
        $this->characterModel
            ->where('id', $character['id'])
            ->set('gold', 'gold - ' . $totalGold, false)
            ->update();

        // Списываем крафтовые компоненты
        foreach ($requiredComponents as $itemName => $reqPerOne) {
            $needed = $reqPerOne * $this->quantity;
            $craftedItem = $this->craftedItemsModel->getCraftedItemByName($itemName);
            $logRow = $this->craftedItemsLogModel
                ->where('character_id', $character['id'])
                ->where('crafted_item_id', $craftedItem['id'])
                ->first();
            $newQty = $logRow['quantity'] - $needed;
            $this->craftedItemsLogModel->update($logRow['id'], ['quantity' => $newQty]);
        }

        // Создаём задачу
        $timeForOne = 8; // 8 мин на 1 шт
        $totalTime  = $timeForOne * $this->quantity;
        $startTime = new \DateTime();
        $endTime   = (clone $startTime)->add(new \DateInterval("PT{$totalTime}M"));

        $this->characterTaskModel->insert([
            'character_id'     => $character['id'],
            'telegram_user_id' => $user['id'],
            'task_id'          => $taskRow['id'],
            'start_time'       => $startTime->format('Y-m-d H:i:s'),
            'end_time'         => $endTime->format('Y-m-d H:i:s'),
            'status'           => 'in_work',
            'task_settings'    => json_encode([
                'item_crafted' => 'DrifterClothes',
                'quantity'     => $this->quantity,
            ]),
        ]);

        // Убираем "часики" на inline-кнопке
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);

        $scope      = new \App\Services\Tasks\ActionScopeService();
        $background = $scope->isBackground($taskRow['parallel_execution_allowed'] ?? 1);
        $text = "*Начат крафт {$this->quantity} шт. «Одежды бродяги»*\n\n"
            . $scope->startedBlock(\App\Services\Tasks\ActionScopeService::KIND_CRAFT, $background) . "\n\n"
            . "Общее время крафта: ~{$totalTime} минут.\n"
            . "По завершении вы получите {$this->quantity} шт.\n";

        $imagePath = base_url('uploads/telegram/craft/standard/drifter_clothes.jpg');
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
