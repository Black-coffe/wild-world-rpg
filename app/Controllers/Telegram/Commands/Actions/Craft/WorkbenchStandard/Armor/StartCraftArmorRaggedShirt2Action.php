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
 * Шаг 2 (окончательный): запускаем крафт "Рваная рубаха" (RaggedShirt) — на X штук.
 * С учётом проверки, чтобы нельзя было повторно запустить тот же крафт, если он уже in_work.
 */
class StartCraftArmorRaggedShirt2Action extends BaseAction
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

        // Разбираем callback_data, напр. "startCraftRaggedShirt2_10" => quantity=10
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

        // 1) проверка переезда
        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $chatId
        )) {
            return Request::emptyResponse();
        }

        // 2) наличие базы
        $base = $this->claimedCellModel
            ->where('character_id', $character['id'])
            ->first();
        if (!$base) {
            return $this->sendError($chatId, 'У вас нет построенной базы (лагеря).');
        }

        // 3) проверяем WorkbenchOne (крафтовый предмет)
        $workbenchItem = $this->craftedItemsModel->where('name_eng', 'WorkbenchOne')->first();
        if (!$workbenchItem) {
            return $this->sendError($chatId, 'Не найдена запись о WorkbenchOne (крафтовый предмет)!');
        }
        $wbLog = $this->craftedItemsLogModel
            ->where('character_id', $character['id'])
            ->where('crafted_item_id', $workbenchItem['id'])
            ->first();
        if (!$wbLog || $wbLog['quantity'] < 1) {
            return $this->sendError($chatId, 'У вас нет Верстака 1-го уровня (WorkbenchOne).');
        }

        // 4) требования
        $requiredGold       = 300;
        $requiredComponents = ['Ткань' => 6];

        // умножаем на кол-во
        $totalGold = $requiredGold * $this->quantity;

        // 4.1) золото
        $goldAmount = (int) $character['gold'];
        if ($goldAmount < $totalGold) {
            return $this->sendError(
                $chatId,
                "Недостаточно золота для {$this->quantity} шт. Нужно {$totalGold}, а у вас {$goldAmount}."
            );
        }

        // 4.2) крафтовые компоненты. Story craft-shortfall-buy-13: вместо отказа на
        // первой нехватке собираем ВСЕ недостающие позиции и показываем общий экран
        // нехватки (CraftShortageService), как остальной крафт.
        $missingItems = [];
        foreach ($requiredComponents as $itemName => $reqPerOne) {
            $totalNeeded = $reqPerOne * $this->quantity;
            $craftedItem = $this->craftedItemsModel->getCraftedItemByName($itemName);
            if (!$craftedItem) {
                return $this->sendError($chatId, "Не найден предмет «{$itemName}» в БД!");
            }

            $logRow = $this->craftedItemsLogModel
                ->where('character_id', $character['id'])
                ->where('crafted_item_id', $craftedItem['id'])
                ->first();
            $haveQty = $logRow ? (int) $logRow['quantity'] : 0;
            if ($haveQty < $totalNeeded) {
                $itemEng = is_string($craftedItem['name_eng'] ?? null) ? $craftedItem['name_eng'] : $itemName;
                $nameRus = is_string($craftedItem['name_rus'] ?? null) ? $craftedItem['name_rus'] : $itemName;
                $missingItems[$itemEng] = ['need' => $totalNeeded, 'have' => $haveQty, 'name' => $nameRus];
            }
        }
        if ($missingItems !== []) {
            return $this->shortageScreen($character, [], $missingItems, [
                'item_name_rus' => 'Рваная рубаха',
                'info_callback' => 'armorRaggedShirt',
                'crafted_items' => $requiredComponents,
            ], $chatId);
        }

        // 5) ищем/создаём задачу craftArmorRaggedShirt
        $taskRow = $this->taskModel->where('name', 'craftArmorRaggedShirt')->first();
        if (!$taskRow) {
            // создаём
            $taskData = [
                'name'                       => 'craftArmorRaggedShirt',
                'name_rus'                   => 'Крафт Рваной рубахи',
                'description'                => 'Процесс крафта рубахи',
                'min_duration'               => 3,
                'max_duration'               => 10,
                'type'                       => 'craft',
                'difficulty_level'           => 3,
                'execution_limit'            => 0,
                'parallel_execution_allowed' => 1,
                'interruptible'              => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            $newTaskId = $this->taskModel->insert($taskData);
            if (!$newTaskId) {
                return $this->sendError($chatId, 'Не удалось создать задачу craftArmorRaggedShirt.');
            }
            $taskRow = array_merge($taskData, ['id' => $newTaskId]);
        }

        // === проверка на уже активный крафт рубахи ===
        $activeTask = $this->characterTaskModel
            ->where('character_id', $character['id'])
            ->where('task_id', $taskRow['id'])
            ->where('status', 'in_work')
            ->first();
        if ($activeTask) {
            return $this->sendError($chatId, "У вас уже идёт крафт Рваной рубахи!");
        }

        // 6) списываем золото и предметы
        // 6.1) золото
        $this->characterModel
            ->where('id', $character['id'])
            ->set('gold', 'gold - '.$totalGold, false)
            ->update();

        // 6.2) предметы
        foreach ($requiredComponents as $itemName => $reqPerOne) {
            $totalNeeded = $reqPerOne * $this->quantity;
            $craftedItem = $this->craftedItemsModel->getCraftedItemByName($itemName);

            $logRow = $this->craftedItemsLogModel
                ->where('character_id', $character['id'])
                ->where('crafted_item_id', $craftedItem['id'])
                ->first();
            $newQty = $logRow['quantity'] - $totalNeeded;
            $this->craftedItemsLogModel->update($logRow['id'], ['quantity' => $newQty]);
        }

        // 7) считаем время крафта
        $timeForOne = 5; // 5 мин на 1 шт.
        $totalTime  = $timeForOne * $this->quantity;

        $startTime = new \DateTime();
        $endTime   = (clone $startTime)->add(new \DateInterval("PT{$totalTime}M"));

        // 8) создаём запись в character_tasks
        $this->characterTaskModel->insert([
            'character_id'     => $character['id'],
            'telegram_user_id' => $user['id'],
            'task_id'          => $taskRow['id'],
            'start_time'       => $startTime->format('Y-m-d H:i:s'),
            'end_time'         => $endTime->format('Y-m-d H:i:s'),
            'status'           => 'in_work',
            'task_settings'    => json_encode([
                'item_crafted' => 'RaggedShirt',
                'quantity'     => $this->quantity,
            ]),
        ]);

        // убираем "часики" на кнопке
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);

        // 9) сообщаем успех
        $scope      = new \App\Services\Tasks\ActionScopeService();
        $background = $scope->isBackground($taskRow['parallel_execution_allowed'] ?? 1);
        $text = "*Начат крафт {$this->quantity} шт. «Рваной рубахи»*\n\n"
            . $scope->startedBlock(\App\Services\Tasks\ActionScopeService::KIND_CRAFT, $background) . "\n\n"
            . "Общее время крафта: ~{$totalTime} мин.\n"
            . "После завершения получишь {$this->quantity} шт.\n";

        $imagePath = base_url('uploads/telegram/craft/standard/ragged_shirt.jpg');
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
            'parse_mode' => 'Markdown'
        ]);
    }

    /**
     * ADR-158 / story craft-shortfall-buy-13 — тот же экран нехватки, что видит
     * остальной крафт (`CraftShortageService::describe()`), а не собственный
     * текстовый отказ. Ничего не пересчитывает: только собирает вход по уже
     * посчитанным нехваткам.
     *
     * @param array<string,array{need:int,have:int,name:string}> $missingResources
     * @param array<string,array{need:int,have:int,name:string}> $missingItems
     * @param array<string,mixed> $recipe
     */
    private function shortageScreen(
        \App\Entities\CharacterEntity $character,
        array $missingResources,
        array $missingItems,
        array $recipe,
        int|string $chatId
    ): ServerResponse {
        $shortage = new \App\Services\Craft\CraftShortageService();
        if ($shortage->isEnabled()) {
            $screen = $shortage->describe($character, $missingResources, $missingItems, $this->quantity, $recipe);
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

            return Request::sendMessage([
                'chat_id'      => $chatId,
                'text'         => $screen['text'],
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode($screen['keyboard']),
            ]);
        }

        return $this->sendError($chatId, "Недостаточно ресурсов для крафта {$this->quantity} шт.");
    }
}
