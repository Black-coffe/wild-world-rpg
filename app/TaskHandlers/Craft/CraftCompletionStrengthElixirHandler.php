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

class CraftCompletionStrengthElixirHandler extends Controller
{
    protected $characterTaskModel;
    protected $craftedItemsModel;
    protected $craftedItemsLogModel;
    protected $telegramUserModel;
    protected $characterModel;
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
     * Основной метод, вызываемый по окончании крафта (CRON или иной механизм).
     * @param array $task Запись из character_tasks
     */
    public function handle($task)
    {
        // 1. Переводим задачу в статус completed
        $this->characterTaskModel->update($task['id'], ['status' => 'completed']);

        // 2. Ищем предмет "Укрепляющий эликсир" (TonicElixir в name_eng)
        $craftedItem = $this->craftedItemsModel->where('name_eng', 'TonicElixir')->first();
        if (!$craftedItem) {
            log_message('error', 'Crafted item "TonicElixir" not found in the database.');
            return;
        }

        // 3. Извлекаем "quantity" из task_settings
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
            // Если предмета ещё нет, вставляем новую запись
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

        // 5. Обновляем характеристики (пример: +0.05 ловкости и интеллекта)
        $this->characterModel->updateAgilityAndIntellect(
            $task['character_id'],
            0.02,
            0.01
        );

        // 6. Уведомляем пользователя
        $this->notifyUser($task['telegram_user_id'], $craftedItem, $task['character_id'], $quantityToAdd);
    }

    /**
     * Извлекаем "quantity" из поля task_settings (JSON).
     * Если нет / некорректно — возвращаем 1 по умолчанию.
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
     * Уведомляем игрока: сколько шт. добавлено и сколько теперь всего у него.
     */
    private function notifyUser(int $telegramUserId, array $craftedItem, int $characterId, int $quantityAdded)
    {
        // Получаем Telegram chat_id
        $userRow = $this->telegramUserModel->where('id', $telegramUserId)->first();
        if (!$userRow) {
            log_message('error', "Telegram user (ID {$telegramUserId}) not found.");
            return;
        }
        $telegramId = $userRow['telegram_id'] ?? null;
        if (!$telegramId) {
            log_message('error', "No telegram_id found for user ID {$telegramUserId}.");
            return;
        }

        // Узнаём, сколько всего стало
        $updatedLog = $this->craftedItemsLogModel->where([
            'character_id'    => $characterId,
            'crafted_item_id' => $craftedItem['id']
        ])->first();
        $totalNow = $updatedLog ? (int)$updatedLog['quantity'] : 0;

        // Формируем сообщение
        $itemNameRus = $craftedItem['name_rus'] ?? "Укрепляющий эликсир";
        $text = "📌 *Крафт завершён!*\n\n"
            . "Ты изготовил: 🧪 *{$itemNameRus}* x{$quantityAdded} шт.\n\n"
            . "Теперь у тебя *{$totalNow} шт.* в инвентаре.\n\n"
            . "Зона применения: *медицина*.\n"
            . "Поднимает здоровье и выносливость, используй с умом!";

        $keyboard = [
            'inline_keyboard' => [
                [
                    // Если хотим крафтить ещё 1 шт. по умолчанию:
                    ['text' => '🔄 Крафтить еще', 'callback_data' => 'craftStrengtheningElixir_1'],
                    ['text' => '🎒 Инвентарь',     'callback_data' => 'inventory'],
                ]
            ]
        ];
        $imagePath = base_url('uploads/telegram/craft/tonic_elixir.jpg');

        // Пытаемся ответить на callbackQuery (если возможно)
        try {
            Request::answerCallbackQuery(['callback_query_id' => $telegramId]);
        } catch (TelegramException $e) {
            log_message('error', "answerCallbackQuery error: " . $e->getMessage());
        }

        // Отправляем фото
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
                'text'    => "Произошла ошибка: " . $e->getMessage(),
            ]);
        }
    }
}
