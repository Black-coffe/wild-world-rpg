<?php

namespace App\TaskHandlers\Craft\WorkbenchStandard;

use App\Attributes\HandlerKey;
use App\Models\CharacterModel;
use App\Models\CharacterTaskModel;
use App\Models\CraftedItemsModel;
use App\Models\CraftedItemsLogModel;
use App\Models\TelegramUserModel;
use App\TaskHandlers\BaseTaskHandler;

/**
 * Закрытие задачи крафта "Рюкзак телепорт" (TeleportBackpack).
 * Аналогично CraftCompletionTeleportBeaconBasicHandler, но для рюкзака.
 *
 * v0.51.41 (F2.9 batch-6): extends BaseTaskHandler + PSR-4 namespace casing fix
 * `app\` → `App\`. Drop manual Telegram init. Request::sendPhoto try/catch →
 * safeSendPhoto. handle($task) → handle(array $task = []): void.
 */
#[HandlerKey(
    key: 'craft_teleport_backpack',
    displayName: 'Крафт: Рюкзак телепорта',
    description: 'Завершение крафта TeleportBackpack (WorkbenchStandard, эксклюзив, не через generic_craft).',
)]
class CraftCompletionTeleportBackpackHandler extends BaseTaskHandler
{
    protected $characterModel;
    protected $characterTaskModel;
    protected $craftedItemsModel;
    protected $craftedItemsLogModel;
    protected $telegramUserModel;

    public function __construct()
    {
        $this->characterModel      = new CharacterModel();
        $this->characterTaskModel  = new CharacterTaskModel();
        $this->craftedItemsModel   = new CraftedItemsModel();
        $this->craftedItemsLogModel= new CraftedItemsLogModel();
        $this->telegramUserModel   = new TelegramUserModel();
    }

    /**
     * @param array<string,mixed> $task данные строки из character_tasks
     */
    public function handle(array $task = []): void
    {
        // 1) Ставим задаче статус 'completed'
        $this->characterTaskModel->update($task['id'], ['status' => 'completed']);

        // 2) Проверяем предмет "TeleportBackpack" в crafted_items
        //    Если нет, создаём новую запись
        $craftedItem = $this->craftedItemsModel
            ->where('name_eng', 'TeleportBackpack')
            ->first();

        if (!$craftedItem) {
            $newItemId = $this->craftedItemsModel->insert([
                'name_rus'             => 'Рюкзак телепорт',
                'name_eng'             => 'TeleportBackpack',
                'type'                 => 'teleport',
                'direction_craft'      => 'teleporter',
                'effect'               => 'Позволяет мгновенно вернуться на базу из любой точки',
                'crafting_time'        => 45,
                'crafting_location'    => 'anywhere',
                'durability_count'     => 100,
                'durability_time'      => null,
                'damage'               => 0,
                'armor'                => 0,
                'hp'                   => 150,
                'character_boost'      => null,
                'weight'               => 200,
                'price'                => 90000,
                'stack_size'           => 9999,
                'required_level'       => 1,
                'required_skills'      => null,
                'required_resources'   => null,
                'description'          => 'Позволяет носить спецмодуль для мгновенного возвращения на базу',
                'created_at'           => date('Y-m-d H:i:s'),
                'updated_at'           => date('Y-m-d H:i:s'),
            ]);

            if ($newItemId) {
                $craftedItem = $this->craftedItemsModel->find($newItemId);
            }
            if (!$craftedItem) {
                log_message('error', 'Не удалось создать запись TeleportBackpack в crafted_items.');
                return;
            }
        }

        // 3) Добавляем/увеличиваем запись в crafted_items_log
        $this->updateCraftLog($task, $craftedItem);

        // 4) Увеличиваем атрибуты персонажа
        $this->characterModel->updateAgilityAndIntellect(
            $task['character_id'],
            0.05,
            0.05
        );

        // 5) Отправляем уведомление в Telegram
        $this->notifyUser($task['telegram_user_id'], $craftedItem, $task['character_id']);
    }

    /**
     * Добавление (или инкремент) предмета в crafted_items_log для данного персонажа.
     */
    private function updateCraftLog(array $task, $craftedItem): void
    {
        $existingLog = $this->craftedItemsLogModel->where([
            'character_id'    => $task['character_id'],
            'crafted_item_id' => $craftedItem['id'],
        ])->first();

        if ($existingLog) {
            $this->craftedItemsLogModel->update($existingLog['id'], [
                'quantity' => $existingLog['quantity'] + 1
            ]);
        } else {
            $this->craftedItemsLogModel->insert([
                'character_id'     => $task['character_id'],
                'task_id'          => $task['task_id'],
                'crafted_item_id'  => $craftedItem['id'],
                'type'             => $craftedItem['type'],
                'direction_craft'  => $craftedItem['direction_craft'],
                'crafting_location'=> $craftedItem['crafting_location'],
                'durability_count' => $craftedItem['durability_count'],
                'durability_time'  => null,
                'quantity'         => 1,
            ]);
        }
    }

    private function notifyUser($telegramUserId, $craftedItem, $characterId): void
    {
        $telegramUser = $this->telegramUserModel->find($telegramUserId);
        if (!$telegramUser) {
            log_message('error', "Не найден TelegramUser c ID={$telegramUserId}");
            return;
        }
        $tgId = $telegramUser['telegram_id'];

        $existingLog = $this->craftedItemsLogModel->where([
            'character_id'    => $characterId,
            'crafted_item_id' => $craftedItem['id'],
        ])->first();
        $quantity = $existingLog ? $existingLog['quantity'] : 1;

        $itemNameRus = $craftedItem['name_rus'] ?? 'Рюкзак телепорт';
        $text = "🏭 *Крафт завершён!*\n\n"
            . "Вы создали предмет:\n"
            . "🎒 *{$itemNameRus}*\n\n"
            . "Теперь у вас *{$quantity}* шт.\n"
            . "В любой момент можете активировать рюкзак и вернуться на базу!";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔄 Крафтить ещё', 'callback_data' => 'teleportBackpack2'],
                    ['text' => '🎒 Инвентарь',     'callback_data' => 'inventory'],
                ],
            ]
        ];

        $this->safeSendPhoto(
            $tgId,
            base_url('uploads/telegram/craft/standard/backpack_craft.jpg'),
            $text,
            ['parse_mode' => 'Markdown', 'reply_markup' => json_encode($keyboard)]
        );
    }
}
