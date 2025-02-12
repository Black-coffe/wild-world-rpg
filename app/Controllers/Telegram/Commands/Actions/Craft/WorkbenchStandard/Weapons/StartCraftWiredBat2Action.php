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
 * Класс запуска задачи крафта "Усовершенствованная бита" (WiredBat).
 * 1) Повторные проверки (сила/уровень, золото, ресурсы, предметы),
 * 2) Списывает всё необходимое,
 * 3) Создаёт запись в `character_tasks` (статус in_work),
 * 4) Возвращает сообщение о старте крафта.
 */
class StartCraftWiredBat2Action extends BaseAction
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

        $this->weaponModel         = new WeaponModel();
        $this->characterModel      = new CharacterModel();
        $this->charResourceModel   = new CharacterResourceModel();
        $this->craftedItemsModel   = new CraftedItemsModel();
        $this->craftedItemsLogModel= new CraftedItemsLogModel();
        $this->taskModel           = new TaskModel();
        $this->characterTaskModel  = new CharacterTaskModel();
        $this->claimModel          = new ClaimedCellModel();
        $this->resourceModel       = new ResourceModel();

        // Разбираем callback_data вида "startCraftWiredBat_10"
        $data  = $callbackQuery->getData();
        $parts = explode('_', $data);
        if (isset($parts[1]) && is_numeric($parts[1])) {
            $this->quantity = (int)$parts[1];
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

        // 2) Проверяем базу
        $base = $this->claimModel->where('character_id', $character['id'])->first();
        if (!$base) {
            return $this->sendError($chatId, 'У вас нет базы, крафт невозможен.');
        }

        // 3) Ищем «WiredBat» в weapons
        $weapon = $this->weaponModel->getByEnglishName('EnhancedBat');
        if (!$weapon) {
            return $this->sendError($chatId, "«EnhancedBat» не найден в weapons!");
        }

        // Требования (как в WeaponWiredBat2Action)
        $strengthRequired = max(5, (int)$weapon['required_strength']);
        $levelRequired    = max(2, (int)$weapon['required_level']);

        if ($character['strength'] < $strengthRequired) {
            return $this->sendError(
                $chatId,
                "Недостаточная сила: требуется {$strengthRequired}, у вас {$character['strength']}."
            );
        }
        if ($character['level'] < $levelRequired) {
            return $this->sendError(
                $chatId,
                "Недостаточный уровень: требуется {$levelRequired}, у вас {$character['level']}."
            );
        }

        // Золото
        $requiredGold = 250;
        $totalGoldNeeded = $requiredGold * $this->quantity;
        if ($character['gold'] < $totalGoldNeeded) {
            return $this->sendError(
                $chatId,
                "Недостаточно золота: нужно {$totalGoldNeeded}, у вас {$character['gold']}."
            );
        }

        // Крафтовые предметы
        // (пример)
        $craftedNeed = [
            'Ткань'  => 3,
            'Металл фрагменты' => 3,
            'Монтировка' => 1,
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
                    "Недостаточно «{$itemName}»: нужно {$needed}, у вас {$haveQty}."
                );
            }
        }

        // Сырьевые ресурсы
        $rawNeeded = [
            ['name' => 'Кости животных', 'qty' => 2],
            ['name' => 'Кожа животных', 'qty' => 2],
            ['name' => 'Смола деревьев', 'qty' => 2],
            ['name' => 'Базальт', 'qty' => 1],
            ['name' => 'Древесина', 'qty' => 16],
        ];
        foreach ($rawNeeded as $res) {
            $needed = $res['qty'] * $this->quantity;
            $resourceRow = $this->resourceModel->getResourceByName($res['name']);
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
            ->set('gold', 'gold - ' . $totalGoldNeeded, false)
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
            $resourceRow = $this->resourceModel->getResourceByName($res['name']);
            $this->charResourceModel
                ->where('id_characters', $character['id'])
                ->where('id_resources', $resourceRow['id'])
                ->set('quantity', 'quantity - ' . $needed, false)
                ->update();
        }

        // 11) Ищем/создаём задачу craftWiredBat
        $taskRow = $this->taskModel->where('name', 'craftWiredBat')->first();
        if (!$taskRow) {
            $newTaskId = $this->taskModel->insert([
                'name'                       => 'craftWiredBat',
                'name_rus'                   => 'Крафт Усоверш. биты',
                'description'                => 'Создание упрочнённой биты с проволокой',
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
            $taskRow = ['id' => $newTaskId, 'name' => 'craftWiredBat'];
        }

        // 12) Проверяем, нет ли уже активной задачи "craftWiredBat"
        $activeTask = $this->characterTaskModel
            ->where('character_id', $character['id'])
            ->where('task_id', $taskRow['id'])
            ->where('status', 'in_work')
            ->first();
        if ($activeTask) {
            return $this->sendError(
                $chatId,
                'У вас уже идёт крафт «Усовершенствованной биты»! Дождитесь завершения старого.'
            );
        }

        // 13) Определяем время крафта (допустим, 6 мин на 1 шт.)
        $timeForOne = 6;
        $totalTime  = $timeForOne * $this->quantity;
        $startTime  = new \DateTime();
        $endTime    = (clone $startTime)->add(new \DateInterval("PT{$totalTime}M"));

        // 14) Создаём запись в character_tasks
        $this->characterTaskModel->insert([
            'character_id'     => $character['id'],
            'telegram_user_id' => $user['id'],
            'task_id'          => $taskRow['id'],
            'start_time'       => $startTime->format('Y-m-d H:i:s'),
            'end_time'         => $endTime->format('Y-m-d H:i:s'),
            'status'           => 'in_work',
            'task_settings'    => json_encode([
                'item_crafted' => 'WiredBat',
                'quantity'     => $this->quantity,
            ]),
        ]);

        // 15) Ответ «часики»
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);

        // 16) Сообщение игроку
        $text = "*Начат крафт {$this->quantity} шт. «Усовершенствованной биты»!*\n"
            . "Общее время: ~{$totalTime} мин.\n"
            . "По завершении вы получите результат.\n";

        $imagePath = base_url('uploads/telegram/craft/standard/wired_bat.jpg');
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
