<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Weapons;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\WeaponModel;
use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
use App\Models\CraftedItemsModel;
use App\Models\CraftedItemsLogModel;
use App\Models\TaskModel;
use App\Models\ResourceModel;
use App\Models\CharacterTaskModel;
use App\Models\ClaimedCellModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * Класс запуска задачи крафта "Металлическое копьё" (MetalSpear).
 *  1) Повторные проверки (сила/уровень, золото, ресурсы, предметы),
 *  2) Списывает всё необходимое,
 *  3) Создаёт запись в `character_tasks` (чтобы крафт шёл N минут),
 *  4) Возвращает сообщение о старте крафта.
 */
class StartCraftMetalSpear2Action extends BaseAction
{
    protected $weaponModel;
    protected $characterModel;
    protected $charResourceModel;
    protected $craftedItemsModel;
    protected $craftedItemsLogModel;
    protected $taskModel;
    protected $characterTaskModel;
    protected $resourceModel;
    protected $claimModel;

    private int $quantity = 1;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);

        // Модели
        $this->weaponModel         = new WeaponModel();
        $this->characterModel      = new CharacterModel();
        $this->charResourceModel   = new CharacterResourceModel();
        $this->craftedItemsModel   = new CraftedItemsModel();
        $this->craftedItemsLogModel= new CraftedItemsLogModel();
        $this->taskModel           = new TaskModel();
        $this->characterTaskModel  = new CharacterTaskModel();
        $this->claimModel          = new ClaimedCellModel();
        $this->resourceModel       = new ResourceModel();

        // Разбираем callback_data, напр. "startCraftMetalSpear_10" => quantity=10
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
            return $this->sendError($chatId, 'Персонаж или пользователь не найден.');
        }

        // 1) Проверка переезда
        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $chatId
        )) {
            return Request::emptyResponse();
        }

        // 2) Проверка базы
        $base = $this->claimModel->where('character_id', $character['id'])->first();
        if (!$base) {
            return $this->sendError($chatId, 'У вас нет базы. Крафт невозможен.');
        }

        // 3) Ищем «MetalSpear» в таблице weapons, чтобы взять динамические req
        $weapon = $this->weaponModel->getByEnglishName('MetalSpear');
        if (!$weapon) {
            return $this->sendError($chatId, "«MetalSpear» не найден в weapons!");
        }

        // Достаём силу/уровень из weapon, но гарантируем минимум (3/1)
        $requiredStrength = max(3, (int)$weapon['required_strength']);
        $requiredLevel    = max(1, (int)$weapon['required_level']);

        // 4) Проверка силы/уровня
        if ($character['strength'] < $requiredStrength) {
            return $this->sendError(
                $chatId,
                "Недостаточная сила: требуется {$requiredStrength}, у вас {$character['strength']}."
            );
        }
        if ($character['level'] < $requiredLevel) {
            return $this->sendError(
                $chatId,
                "Недостаточный уровень: требуется {$requiredLevel}, у вас {$character['level']}."
            );
        }

        // 5) Допустим, золота нужно 200 за штуку
        $requiredGold = 200;
        $totalGoldNeeded = $requiredGold * $this->quantity;
        if ($character['gold'] < $totalGoldNeeded) {
            return $this->sendError(
                $chatId,
                "Недостаточно золота: нужно {$totalGoldNeeded}, у вас {$character['gold']}."
            );
        }

        // 6) Крафтовые предметы
        //  Пример: "Монтировка" => 1, "Ткань" => 2, "Металл фрагменты" => 2
        $craftedNeed = [
            'Монтировка'        => 1,
            'Ткань'             => 2,
            'Металл фрагменты'  => 2,
        ];
        foreach ($craftedNeed as $itemName => $qtyPerOne) {
            $needed = $qtyPerOne * $this->quantity;
            $craftedItem = $this->craftedItemsModel->getCraftedItemByName($itemName);
            if (!$craftedItem) {
                return $this->sendError($chatId, "«{$itemName}» не найдено в crafted_items!");
            }
            $logRow = $this->craftedItemsLogModel
                ->where('character_id', $character['id'])
                ->where('crafted_item_id', $craftedItem['id'])
                ->first();
            $haveQty = $logRow ? (int)$logRow['quantity'] : 0;
            if ($haveQty < $needed) {
                return $this->sendError(
                    $chatId,
                    "Недостаточно «{$itemName}»: нужно {$needed}, а есть {$haveQty}."
                );
            }
        }

        // 7) Сырьевые ресурсы
        //  Пример: 3xДревесина, 2xРедкие металлы
        $rawNeeded = [
            ['name' => 'Древесина',      'qty' => 3],
            ['name' => 'Редкие металлы', 'qty' => 2],
        ];
        foreach ($rawNeeded as $res) {
            $needed = $res['qty'] * $this->quantity;
            $resourceRow = (new ResourceModel())->getResourceByName($res['name']);
            if (!$resourceRow) {
                return $this->sendError($chatId, "Ресурс «{$res['name']}» не найден!");
            }
            $charRes = $this->charResourceModel
                ->where('id_characters', $character['id'])
                ->where('id_resources', $resourceRow['id'])
                ->first();
            $haveQty = $charRes ? (int)$charRes['quantity'] : 0;
            if ($haveQty < $needed) {
                return $this->sendError(
                    $chatId,
                    "Недостаточно «{$res['name']}»: нужно {$needed}, у вас {$haveQty}."
                );
            }
        }

        // 8) Списываем золото
        $this->characterModel
            ->where('id', $character['id'])
            ->set('gold', 'gold - '.$totalGoldNeeded, false)
            ->update();

        // 9) Списываем крафтовые предметы
        foreach ($craftedNeed as $itemName => $qtyPerOne) {
            $needed = $qtyPerOne * $this->quantity;
            $craftedItem = $this->craftedItemsModel->getCraftedItemByName($itemName);
            $logRow = $this->craftedItemsLogModel
                ->where('character_id', $character['id'])
                ->where('crafted_item_id', $craftedItem['id'])
                ->first();
            $newQty = $logRow['quantity'] - $needed;
            $this->craftedItemsLogModel->update($logRow['id'], ['quantity' => $newQty]);
        }

        // 10) Списываем сырьевые ресурсы
        foreach ($rawNeeded as $res) {
            $needed = $res['qty'] * $this->quantity;
            $resourceRow = (new ResourceModel())->getResourceByName($res['name']);
            $this->charResourceModel
                ->where('id_characters', $character['id'])
                ->where('id_resources', $resourceRow['id'])
                ->set('quantity', 'quantity - ' . $needed, false)
                ->update();
        }

        // 11) Ищем/создаём задачу craftMetalSpear
        $taskRow = $this->taskModel->where('name', 'craftMetalSpear')->first();
        if (!$taskRow) {
            $newTaskId = $this->taskModel->insert([
                'name'                       => 'craftMetalSpear',
                'name_rus'                   => 'Крафт Металлического копья',
                'description'                => 'Создание копья из металла',
                'min_duration'               => 3,
                'max_duration'               => 10,
                'type'                       => 'craft',
                'difficulty_level'           => 2,
                'execution_limit'            => 0,
                'parallel_execution_allowed' => 1,
                'interruptible'              => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $taskRow = ['id' => $newTaskId, 'name' => 'craftMetalSpear'];
        }

        // 12) Проверяем, нет ли уже активного крафта копья
        $activeTask = $this->characterTaskModel
            ->where('character_id', $character['id'])
            ->where('task_id', $taskRow['id'])
            ->where('status', 'in_work')
            ->first();
        if ($activeTask) {
            return $this->sendError(
                $chatId,
                'У вас уже идёт крафт «Металлического копья»! Дождитесь завершения старого.'
            );
        }

        // 13) Определяем время крафта (допустим 5 мин за 1 шт.)
        $timeForOne = 5;
        $totalTime  = $timeForOne * $this->quantity;
        $startTime  = new \DateTime();
        $endTime    = (clone $startTime)->add(new \DateInterval("PT{$totalTime}M"));

        // 14) Вставляем запись в character_tasks
        $this->characterTaskModel->insert([
            'character_id'     => $character['id'],
            'telegram_user_id' => $user['id'],
            'task_id'          => $taskRow['id'],
            'start_time'       => $startTime->format('Y-m-d H:i:s'),
            'end_time'         => $endTime->format('Y-m-d H:i:s'),
            'status'           => 'in_work',
            'task_settings'    => json_encode([
                'item_crafted' => 'MetalSpear',
                'quantity'     => $this->quantity,
            ]),
        ]);

        // 15) Убираем "часики"
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);

        // 16) Отправляем сообщение об успехе
        $text = "*Начат крафт {$this->quantity} шт. «Металлического копья»!*\n"
            . "Общее время: ~{$totalTime} мин.\n"
            . "По завершении вы получите результат.\n";

        $imagePath = base_url('uploads/telegram/craft/standard/metal_spear.jpg');
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
