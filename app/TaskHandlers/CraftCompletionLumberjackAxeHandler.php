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

class CraftCompletionLumberjackAxeHandler extends Controller
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

    public function handle($task)
    {
        // 1. Закрываем задачу
        $this->characterTaskModel->update($task['id'], ['status' => 'completed']);

        // 2. Получаем инфо о предмете (name_eng = 'LumberjackAxe')
        $craftedItem = $this->craftedItemsModel->where('name_eng', 'LumberjackAxe')->first();
        if (!$craftedItem) {
            log_message('error', 'Crafted item "LumberjackAxe" not found in the database.');
            return;
        }

        // 3. Извлекаем количество из task_settings
        $quantityToAdd = $this->getQuantityFromTaskSettings($task);

        // 4. Обновляем/создаём запись в crafted_items_log
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

        // 5. Повышаем характеристики персонажа (пример: +0.05 ловкости и интеллекта)
        $this->characterModel->updateAgilityAndIntellect(
            $task['character_id'],
            0.05,
            0.05
        );

        // 6. Уведомляем игрока
        $this->notifyUser($task['telegram_user_id'], $craftedItem, $task['character_id'], $quantityToAdd);
    }

    /**
     * Извлекаем "quantity" из поля task_settings (JSON). Если нет, возвращаем 1.
     */
    private function getQuantityFromTaskSettings(array $task): int
    {
        if (!empty($task['task_settings'])) {
            $decoded = json_decode($task['task_settings'], true);
            if (isset($decoded['quantity']) && is_numeric($decoded['quantity'])) {
                return (int)$decoded['quantity'];
            }
        }
        return 1;
    }

    /**
     * Уведомляем игрока о том, сколько топоров создано и сколько всего теперь у него.
     */
    private function notifyUser(int $telegramUserId, array $craftedItem, int $characterId, int $quantityAdded)
    {
        $row = $this->telegramUserModel->where('id', $telegramUserId)->first();
        if (!$row) {
            log_message('error', "User row not found for ID {$telegramUserId}.");
            return;
        }
        $telegramId = $row['telegram_id'] ?? null;
        if (!$telegramId) {
            log_message('error', "No telegram_id for user ID {$telegramUserId}.");
            return;
        }

        // Узнаём текущее общее кол-во
        $logEntry = $this->craftedItemsLogModel->where([
            'character_id'    => $characterId,
            'crafted_item_id' => $craftedItem['id']
        ])->first();

        $totalNow = $logEntry ? (int)$logEntry['quantity'] : 0;

        // Русское название, если есть
        $itemNameRus = $craftedItem['name_rus'] ?? "Топор дровосека";

        $text = "📌 *Крафт завершён!*\n\n"
            . "Ты создал: 🪓 *{$itemNameRus}* x{$quantityAdded} шт.\n\n"
            . "Теперь у тебя *{$totalNow} шт.* этого инструмента.\n\n"
            . "Зона применения: *Инструменты* 🛠️";

        // Кнопка "Крафтить ещё (1шт.)"
        $keyboard = [
            'inline_keyboard' => [
                [
                    // Пример callback_data => "craftLumberjackAxeCraft1_1"
                    ['text' => '🔄 Крафтить еще', 'callback_data' => 'craftLumberjackAxeCraft1_1'],
                    ['text' => '🎒 Инвентарь',     'callback_data' => 'inventory'],
                ]
            ]
        ];

        $imagePath = base_url('uploads/telegram/craft/old-stone-primitive-axe-of-stone-and-logs.jpg');

        try {
            Request::answerCallbackQuery(['callback_query_id' => $telegramId]);
        } catch (TelegramException $e) {
            log_message('error', "answerCallbackQuery error: " . $e->getMessage());
        }

        try {
            Request::sendPhoto([
                'chat_id'      => $telegramId,
                'photo'        => Request::encodeFile($imagePath),
                'caption'      => $text,
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Telegram API error: " . $e->getMessage());
            Request::sendMessage([
                'chat_id' => $telegramId,
                'text'    => "Произошла ошибка: " . $e->getMessage(),
            ]);
        }
    }
}
