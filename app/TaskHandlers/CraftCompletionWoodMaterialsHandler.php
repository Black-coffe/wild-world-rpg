<?php

namespace App\TaskHandlers;

use App\Models\CharacterModel;
use App\Models\CharacterTaskModel;
use App\Models\CraftedItemsModel;
use App\Models\CraftedItemsLogModel;
use App\Models\TelegramUserModel;
use Longman\TelegramBot\Request;
use CodeIgniter\Controller;
use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\Exception\TelegramException;

/**
 * Обработчик завершения крафта «Древесные материалы».
 * Вызывается, когда время end_time для задачи 'craftWoodMaterials' истекает.
 */
class CraftCompletionWoodMaterialsHandler extends Controller
{
    protected $characterModel;
    protected $characterTaskModel;
    protected $craftedItemsModel;
    protected $craftedItemsLogModel;
    protected $telegramUserModel;
    private $telegram;

    public function __construct()
    {
        $this->characterModel       = new CharacterModel();
        $this->characterTaskModel   = new CharacterTaskModel();
        $this->craftedItemsModel    = new CraftedItemsModel();
        $this->craftedItemsLogModel = new CraftedItemsLogModel();
        $this->telegramUserModel    = new TelegramUserModel();

        $API_KEY      = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');

        try {
            $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
            Request::initialize($this->telegram);
        } catch (TelegramException $e) {
            log_message('error', $e->getMessage());
        }
    }

    /**
     * Метод handle($task) вызывается системой TaskHandler,
     * когда наступает время завершения крафта (end_time <= текущее).
     */
    public function handle($task)
    {
        // 1) Закрываем задачу (меняем статус на completed).
        $this->characterTaskModel->update($task['id'], ['status' => 'completed']);

        // 2) Находим информацию о предмете "WoodMaterials" в таблице crafted_items
        $craftedItem = $this->craftedItemsModel->where('name_eng', 'WoodMaterials')->first();
        if (!$craftedItem) {
            log_message('error', 'Crafted item "WoodMaterials" not found in the database.');
            return;
        }

        // 3) Считываем количество (quantity) из task_settings
        $quantityToAdd = $this->getQuantityFromTaskSettings($task);

        // 4) Обновляем (или создаём) запись в crafted_items_log
        $existingLog = $this->craftedItemsLogModel->where([
            'character_id'    => $task['character_id'],
            'crafted_item_id' => $craftedItem['id']
        ])->first();

        if ($existingLog) {
            // Если запись уже была, увеличиваем на $quantityToAdd
            $newQty = $existingLog['quantity'] + $quantityToAdd;
            $this->craftedItemsLogModel->update($existingLog['id'], [
                'quantity' => $newQty
            ]);
        } else {
            // Если предмета нет в логе, создаём новую запись
            $this->craftedItemsLogModel->insert([
                'character_id'      => $task['character_id'],
                'task_id'           => $task['task_id'],
                'crafted_item_id'   => $craftedItem['id'],
                'type'              => $craftedItem['type'],
                'direction_craft'   => $craftedItem['direction_craft'],
                'crafting_location' => $craftedItem['crafting_location'],
                'durability_count'  => $craftedItem['durability_count'],
                'durability_time'   => null,
                'quantity'          => $quantityToAdd
            ]);
        }

        // 5) Поднимаем характеристики персонажа (пример: +0.05 ловкости и интеллекта)
        $this->characterModel->updateAgilityAndIntellect(
            $task['character_id'],
            0.05,
            0.05
        );

        // 6) Отправляем уведомление пользователю
        $this->notifyUser($task['telegram_user_id'], $craftedItem, $task['character_id'], $quantityToAdd);
    }

    /**
     * Достаёт из task_settings (JSON) поле "quantity". Если нет — возвращает 1.
     */
    private function getQuantityFromTaskSettings(array $task): int
    {
        if (!empty($task['task_settings'])) {
            $decoded = json_decode($task['task_settings'], true);
            if (isset($decoded['quantity']) && is_numeric($decoded['quantity'])) {
                return (int) $decoded['quantity'];
            }
        }
        return 1;
    }

    /**
     * Шлёт уведомление о завершённом крафте, указывая сколько штук создано.
     */
    private function notifyUser(int $telegramUserId, array $craftedItem, int $characterId, int $qtyCreated)
    {
        // Получаем запись из telegram_user (телеграм-id)
        $userRow = $this->telegramUserModel->find($telegramUserId);
        if (!$userRow) {
            log_message('error', "User row not found for ID {$telegramUserId}.");
            return;
        }

        $telegram_id = $userRow['telegram_id'] ?? null;
        if (!$telegram_id) {
            log_message('error', "No telegram_id found for user ID {$telegramUserId}.");
            return;
        }

        // Смотрим, сколько теперь всего в логе
        $existingLog = $this->craftedItemsLogModel->where([
            'character_id'    => $characterId,
            'crafted_item_id' => $craftedItem['id']
        ])->first();
        $totalNow = $existingLog ? (int)$existingLog['quantity'] : 0;

        // Русское название предмета
        $itemNameRus = $craftedItem['name_rus'] ?? 'Древесные материалы';

        // Текст сообщения
        $text = "📌 *Крафт завершён!*\n\n"
            . "Ты создал: 🪵 *{$itemNameRus}* x{$qtyCreated} шт.\n\n"
            . "Теперь у тебя *{$totalNow} шт.*\n\n"
            . "Зона применения: *Производство* 🏭";

        // Клавиатура
        // Кнопка "Крафтить еще" с callback_data на 1 шт. (например: craftWoodMaterials_1)
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔄 Крафтить еще', 'callback_data' => 'craftWoodMaterials_1'],
                    ['text' => '🎒 Инвентарь',    'callback_data' => 'inventory'],
                ],
            ],
        ];

        $imagePath = base_url('uploads/telegram/craft/components/craftWoodMaterials.jpg');

        // Обычно answerCallbackQuery без "callback_query_id" не имеет смысла,
        // потому что у нас нет актуального callbackQueryId при завершении задачи.
        // Можно либо убрать, либо оставить так.
        // Request::answerCallbackQuery(['callback_query_id' => ???]);

        try {
            Request::sendPhoto([
                'chat_id'    => $telegram_id,
                'photo'      => Request::encodeFile($imagePath),
                'caption'    => $text,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Telegram API error: " . $e->getMessage());
            Request::sendMessage([
                'chat_id' => $telegram_id,
                'text'    => "Произошла ошибка: " . $e->getMessage(),
            ]);
        }
    }
}
