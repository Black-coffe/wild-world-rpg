<?php

namespace app\TaskHandlers\Craft;

use App\Models\CharacterModel;
use App\Models\CharacterTaskModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\TelegramUserModel;
use CodeIgniter\Controller;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

/**
 * Класс, вызываемый при завершении крафта «Проводки» (Wiring).
 * Аналогично примеру с "BasicMedKit", но заточен под name_eng="wiring".
 */
class CraftCompletionWiringHandler extends Controller
{
    protected $characterModel;
    protected $characterTaskModel;
    protected $craftedItemsModel;
    protected $craftedItemsLogModel;
    protected $telegramUserModel;
    private   $telegram;

    public function __construct()
    {
        $this->characterModel       = new CharacterModel();
        $this->characterTaskModel   = new CharacterTaskModel();
        $this->craftedItemsModel    = new CraftedItemsModel();    // или CraftedItemModel, если у вас другое название
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
     * Метод handle(...) вызывается, когда задача крафта "Проводка" завершается (например, по CRON).
     * @param array $task Данные о задаче из character_tasks (task_id, task_settings, character_id и т.д.)
     */
    public function handle($task)
    {
        // 1. Закрываем задачу, переводим в 'completed'
        $this->characterTaskModel->update($task['id'], ['status' => 'completed']);

        // 2. Ищем сам предмет "Проводка" (wiring) в таблице crafted_items
        $craftedItem = $this->craftedItemsModel->where('name_eng', 'wiring')->first();
        if (!$craftedItem) {
            log_message('error', 'Crafted item (wiring) not found in the database.');
            return;
        }

        // 3. Получаем кол-во (quantity) из task_settings
        $quantityToAdd = $this->getQuantityFromTaskSettings($task);

        // 4. Добавляем (или обновляем) запись в crafted_items_log
        $existingLog = $this->craftedItemsLogModel->where([
            'character_id'    => $task['character_id'],
            'crafted_item_id' => $craftedItem['id']
        ])->first();

        if ($existingLog) {
            $newQty = $existingLog['quantity'] + $quantityToAdd;
            $this->craftedItemsLogModel->update($existingLog['id'], [
                'quantity' => $newQty
            ]);
        } else {
            // Вставляем новую запись
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

        // 5. Немного повысим персонажу характеристики, например интеллекта и инженерии
        //    Допустим, у вас метод updateAgilityAndIntellect(...), либо любой другой:
        $this->characterModel->updateAgilityAndIntellect(
            $task['character_id'],
            0.02,    // агилити не трогаем
            0.03     // интеллекта +0.10
        );

        // 6. Уведомляем игрока в Telegram
        $this->notifyUser($task['telegram_user_id'], $craftedItem, $task['character_id'], $quantityToAdd);
    }

    /**
     * Извлекает "quantity" из поля task_settings, если есть. Иначе возвращает 1.
     */
    private function getQuantityFromTaskSettings(array $task): int
    {
        if (!empty($task['task_settings'])) {
            $decoded = json_decode($task['task_settings'], true);
            if (!empty($decoded['quantity']) && is_numeric($decoded['quantity'])) {
                return (int)$decoded['quantity'];
            }
        }
        return 1;
    }

    /**
     * Отправляем пользователю сообщение об окончании крафта: сколько шт. получено и итого.
     */
    private function notifyUser(int $telegramUserId, array $craftedItem, int $characterId, int $quantityAdded)
    {
        // 1) Ищем userRow, чтобы узнать telegram_id
        $row = $this->telegramUserModel->where('id', $telegramUserId)->first();
        if (!$row) {
            log_message('error', "User not found: ID = {$telegramUserId}");
            return;
        }
        $telegramId = $row['telegram_id'] ?? null;
        if (!$telegramId) {
            log_message('error', "No telegram_id for user ID = {$telegramUserId}");
            return;
        }

        // 2) Узнаём, сколько теперь всего Проводки у персонажа
        $logEntry = $this->craftedItemsLogModel->where([
            'character_id'    => $characterId,
            'crafted_item_id' => $craftedItem['id']
        ])->first();
        $totalNow = $logEntry ? (int)$logEntry['quantity'] : 0;

        // 3) Русское название (или fallback)
        $itemNameRus = $craftedItem['name_rus'] ?? "Проводка";

        // 4) Формируем текст
        $text = "📌 *Крафт завершён!*\n\n"
            . "Ты создал: 🔌 *{$itemNameRus}* x{$quantityAdded} шт.\n\n"
            . "Теперь у тебя *{$totalNow} шт.* этого предмета в инвентаре.\n\n"
            . "_Используется для соединения электрических цепей._";

        // 5) Кнопки: к примеру «Крафтить еще» и «Инвентарь»
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔄 Крафтить ещё', 'callback_data' => 'craftWiring_1'],
                    ['text' => '🎒 Инвентарь',    'callback_data' => 'inventory'],
                ]
            ]
        ];

        // 6) Отправляем фото + текст
        $imagePath = base_url('uploads/telegram/craft/components/wiring_craft.jpg');

        // Необязательно отвечать на callbackQuery (так как это завершается по CRON),
        // но если хотите, можно попробовать:
        try {
            Request::answerCallbackQuery(['callback_query_id' => $telegramId]);
        } catch (TelegramException $e) {
            log_message('error', "answerCallbackQuery error: " . $e->getMessage());
        }

        try {
            Request::sendPhoto([
                'chat_id'    => $telegramId,
                'photo'      => Request::encodeFile($imagePath),
                'caption'    => $text,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Telegram API error: " . $e->getMessage());
            // fallback
            Request::sendMessage([
                'chat_id' => $telegramId,
                'text'    => "Произошла ошибка: " . $e->getMessage(),
            ]);
        }
    }
}
