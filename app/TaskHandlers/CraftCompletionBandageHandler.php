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

class CraftCompletionBandageHandler extends Controller
{
    protected $characterModel;
    protected $characterTaskModel;
    protected $craftedItemsModel;
    protected $craftedItemsLogModel;
    protected $telegramUserModel;
    private $telegram;

    public function __construct()
    {
        $this->characterModel        = new CharacterModel();
        $this->characterTaskModel    = new CharacterTaskModel();
        $this->craftedItemsModel     = new CraftedItemsModel();
        $this->craftedItemsLogModel  = new CraftedItemsLogModel();
        $this->telegramUserModel     = new TelegramUserModel();

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
     * Основной метод, вызываемый при завершении крафта.
     *
     * @param array $task Запись из character_tasks (с полями task_settings, start_time и т.д.)
     */
    public function handle($task)
    {
        // 1. Переводим задачу в статус 'completed'
        $this->characterTaskModel->update($task['id'], ['status' => 'completed']);

        // 2. Находим предмет "Bandage" (англ. name_eng='Bandage')
        $craftedItem = $this->craftedItemsModel->where('name_eng', 'Bandage')->first();
        if (!$craftedItem) {
            log_message('error', 'Crafted item "Bandage" not found in the database.');
            return;
        }

        // 3. Извлекаем количество (quantity) из task_settings
        $quantityToAdd = $this->getQuantityFromTaskSettings($task);

        // 4. Обновляем / создаём запись в crafted_items_log
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
            // Если ещё нет записи в логе
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

        // 5. Улучшаем статы персонажа (пример: +0.05 ловкости и интеллекта)
        $this->characterModel->updateAgilityAndIntellect(
            $task['character_id'],
            0.05,
            0.05
        );

        // 6. Уведомляем пользователя
        $this->notifyUser($task['telegram_user_id'], $craftedItem, $task['character_id'], $quantityToAdd);
    }

    /**
     * Извлекаем "quantity" из task_settings (JSON).
     * Если нет или некорректно, возвращаем 1.
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
     * Уведомление игрока: сколько повязок добавлено и сколько всего стало.
     */
    private function notifyUser(int $telegramUserId, array $craftedItem, int $characterId, int $quantityAdded)
    {
        // Получаем Telegram ID (chat_id)
        $userRow = $this->telegramUserModel->where('id', $telegramUserId)->first();
        if (!$userRow) {
            log_message('error', "Telegram user row not found for ID: {$telegramUserId}");
            return;
        }

        $telegramId = $userRow['telegram_id'] ?? null;
        if (!$telegramId) {
            log_message('error', "No 'telegram_id' found for user ID: {$telegramUserId}");
            return;
        }

        // Сколько теперь всего у игрока "Bandage"
        $updatedLog = $this->craftedItemsLogModel->where([
            'character_id'    => $characterId,
            'crafted_item_id' => $craftedItem['id']
        ])->first();
        $totalNow = $updatedLog ? (int)$updatedLog['quantity'] : 0;

        // Русское название из поля name_rus (если есть) или задаём вручную
        $itemNameRus = $craftedItem['name_rus'] ?? "Повязка";

        // Формируем текст
        $text = "📌 *Крафт завершён!*\n\n"
            . "Ты создал: 🩹 *{$itemNameRus}* x{$quantityAdded} шт.\n\n"
            . "Теперь у тебя *{$totalNow} шт.* в инвентаре.\n\n"
            . "Зона применения: *медицина* 💊";

        // Кнопка "Крафтить ещё" теперь указывает на колбэк "craftBandage_1" —
        // если игрок захочет мгновенно начать ещё 1 шт. (или настроить свой вариант).
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔄 Крафтить еще', 'callback_data' => 'craftBandage_1'],
                    ['text' => '🎒 Инвентарь',     'callback_data' => 'inventory'],
                ]
            ]
        ];
        $imagePath = base_url('uploads/telegram/craft/bandage_that_is_made_in_the_wild.jpg');

        // Попытка ответить на колбэк (если он ещё актуален)
        try {
            Request::answerCallbackQuery(['callback_query_id' => $telegramId]);
        } catch (TelegramException $e) {
            log_message('error', "answerCallbackQuery error: " . $e->getMessage());
        }

        // Отправляем фото с описанием
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
            // fallback
            Request::sendMessage([
                'chat_id' => $telegramId,
                'text'    => "Произошла ошибка при отправке фото: " . $e->getMessage(),
            ]);
        }
    }
}
