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
 * Закрытие задачи крафта "Базового телепорт-маяка".
 *
 * v0.51.41 (F2.9 batch-6): extends BaseTaskHandler + PSR-4 namespace casing fix.
 * Drop manual Telegram init. Request::sendPhoto try/catch → safeSendPhoto.
 * handle($task) → handle(array $task = []): void.
 */
#[HandlerKey(
    key: 'craft_teleport_beacon_basic',
    displayName: 'Крафт: Телепорт-маяк (базовый)',
    description: 'Завершение крафта TeleportBeaconBasic (WorkbenchStandard, эксклюзив, не через generic_craft).',
)]
class CraftCompletionTeleportBeaconBasicHandler extends BaseTaskHandler
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

        // 2) Проверяем предмет "TeleportBeaconBasic" в crafted_items
        $craftedItem = $this->craftedItemsModel
            ->where('name_eng', 'TeleportBeaconBasic')
            ->first();

        if (!$craftedItem) {
            $newItemId = $this->craftedItemsModel->insert([
                'name_rus'             => 'Телепорт-маяк (базовый)',
                'name_eng'             => 'TeleportBeaconBasic',
                'type'                 => 'teleport',
                'direction_craft'      => 'teleporter',
                'effect'               => 'Позволяет быстро возвращаться в заданные точки',
                'crafting_time'        => 30,
                'crafting_location'    => 'anywhere',
                'durability_count'     => 100,
                'durability_time'      => null,
                'damage'               => 0,
                'armor'                => 0,
                'hp'                   => 120,
                'character_boost'      => null,
                'weight'               => 180,
                'price'                => 65000,
                'stack_size'           => 9999,
                'required_level'       => 1,
                'required_skills'      => null,
                'required_resources'   => null,
                'description'          => 'Базовый телепорт-маяк, упрощающий перемещение по карте',
                'created_at'           => date('Y-m-d H:i:s'),
                'updated_at'           => date('Y-m-d H:i:s'),
            ]);

            if ($newItemId) {
                $craftedItem = $this->craftedItemsModel->find($newItemId);
            }
            if (!$craftedItem) {
                log_message('error', 'Не удалось создать запись TeleportBeaconBasic в crafted_items.');
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

        $itemNameRus = $craftedItem['name_rus'] ?? 'Телепорт-маяк (базовый)';
        $text = "📌 Вы успешно скрафтили предмет:\n\n"
            . "🌀 *{$itemNameRus}*\n\n"
            . "В наличии: *{$quantity} шт.*\n"
            . "Теперь вы можете устанавливать маяк для быстрого перемещения.\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔄 Крафтить ещё', 'callback_data' => 'teleportBeaconBasic2'],
                    ['text' => '🎒 Инвентарь',     'callback_data' => 'inventory'],
                ],
            ]
        ];

        // file_exists на base_url URL не працює (URL ≠ filesystem path) — drop fallback,
        // safeSendPhoto handle missing image gracefully через TelegramException catch.
        $imagePath = base_url('uploads/telegram/craft/standard/beacon_craft.jpg');

        $this->safeSendPhoto(
            $tgId,
            $imagePath,
            $text,
            ['parse_mode' => 'Markdown', 'reply_markup' => json_encode($keyboard)]
        );
    }
}
