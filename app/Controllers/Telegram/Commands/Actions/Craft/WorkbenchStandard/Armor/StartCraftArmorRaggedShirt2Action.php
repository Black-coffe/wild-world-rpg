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
use Longman\TelegramBot\Request;

/**
 * Шаг 2 (окончательный): запускаем крафт "Рваная рубаха" (RaggedShirt) — на X штук.
 * Аналогично «AntisepticCraftActionStart», но для брони.
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

    /**
     * Сколько штук рубах крафтим, парсим из callback_data (например "startCraftRaggedShirt2_10" => 10).
     */
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

        // Разбираем callback_data, чтобы узнать количество
        $data  = $callbackQuery->getData(); // "startCraftRaggedShirt2_10" например
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

        // Проверка: есть ли база
        $base = $this->claimedCellModel
            ->where('character_id', $character['id'])
            ->first();
        if (!$base) {
            return $this->sendError($chatId, 'У вас нет построенной базы (лагеря).');
        }

        // 1) Проверяем наличие WorkbenchOne в crafted_items
        $workbenchItem = $this->craftedItemsModel->where('name_eng', 'WorkbenchOne')->first();
        if (!$workbenchItem) {
            return $this->sendError($chatId, 'Не найдена запись о WorkbenchOne (крафтовый предмет)!');
        }

        // 2) Проверяем, есть ли этот предмет (кол-во >=1) у игрока
        $wbLog = $this->craftedItemsLogModel
            ->where('character_id', $character['id'])
            ->where('crafted_item_id', $workbenchItem['id'])
            ->first();

        if (!$wbLog || $wbLog['quantity'] < 1) {
            return $this->sendError($chatId, 'У вас нет Верстака 1-го уровня (WorkbenchOne).');
        }

        // Требования (за 1 шт.)
        $requiredGold      = 300;
        $requiredComponents= ['Ткань' => 6];

        // Теперь умножаем на $this->quantity!
        $totalGold = $requiredGold * $this->quantity;

        // Проверка золота
        $goldAmount = (int) $character['gold'];
        if ($goldAmount < $totalGold) {
            return $this->sendError(
                $chatId,
                "Недостаточно золота для крафта {$this->quantity} шт. Нужно {$totalGold}, а у вас {$goldAmount}."
            );
        }

        // Проверяем крафтовые компоненты
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
                return $this->sendError(
                    $chatId,
                    "Недостаточно «{$itemName}». Нужно {$totalNeeded}, а есть {$haveQty}."
                );
            }
        }

        // Проверяем задачу craftArmorRaggedShirt в таблице tasks
        $taskRow = $this->taskModel->where('name', 'craftArmorRaggedShirt')->first();
        if (!$taskRow) {
            // Если нет, создаём
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

        // Проверяем, не крафтит ли уже рубаху
        $activeTask = $this->characterTaskModel
            ->where('character_id', $character['id'])
            ->where('task_id', $taskRow['id'])
            ->where('status', 'in_work')
            ->first();
        if ($activeTask) {
            return $this->sendError($chatId, "У вас уже идёт крафт Рваной рубахи!");
        }

        // --- СПИСЫВАЕМ (золото и крафтовые предметы) ---
        // 1) золото
        $this->characterModel
            ->where('id', $character['id'])
            ->set('gold', 'gold - '.$totalGold, false)
            ->update();

        // 2) предметы
        foreach ($requiredComponents as $itemName => $reqPerOne) {
            $totalNeeded = $reqPerOne * $this->quantity;
            $craftedItem = $this->craftedItemsModel->getCraftedItemByName($itemName);

            $logRow = $this->craftedItemsLogModel
                ->where('character_id', $character['id'])
                ->where('crafted_item_id', $craftedItem['id'])
                ->first();
            if (!$logRow) {
                return $this->sendError($chatId, "Сбой: не найдена запись для {$itemName}, хотя проверялась!");
            }
            $newQty = $logRow['quantity'] - $totalNeeded;
            $this->craftedItemsLogModel->update($logRow['id'], ['quantity' => $newQty]);
        }

        // Определяем время крафта (за 1 шт. = 5 минут). Тогда итого = 5 * $this->quantity
        $timeForOne = 5; // жёстко зашито
        $totalTime  = $timeForOne * $this->quantity;

        $startTime = new \DateTime();
        $endTime   = (clone $startTime)->add(new \DateInterval("PT{$totalTime}M")); // например "PT15M" (15 мин)

        // Создаём запись в character_tasks
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

        // Сообщаем успех
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);

        $text = "*Начат крафт {$this->quantity} шт. «Рваной рубахи»*\n\n"
            . "Общее время крафта: ~{$totalTime} мин.\n"
            . "После завершения получишь {$this->quantity} шт.\n";

        $imagePath = base_url('uploads/telegram/craft/standard/ragged_shirt.jpg');
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
            'parse_mode' => 'Markdown'
        ]);
    }
}
