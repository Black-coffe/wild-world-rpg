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

// Подключение модели персонажа для обновления атрибутов

class CraftCompletionRobotExplorerHandler extends Controller
{
    protected $characterModel; // Добавление свойства модели персонажа
    protected $characterTaskModel;
    protected $craftedItemsModel;
    protected $craftedItemsLogModel;
    protected $telegramUserModel;
    private $telegram;

    public function __construct()
    {
        $this->characterModel = new CharacterModel(); // Инициализация модели персонажа
        $this->characterTaskModel = new CharacterTaskModel();
        $this->craftedItemsModel = new CraftedItemsModel();
        $this->craftedItemsLogModel = new CraftedItemsLogModel();
        $this->telegramUserModel = new TelegramUserModel();

        $API_KEY = getenv('telegram.API_KEY');
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
        // Закрытие задачи
        $this->characterTaskModel->update($task['id'], ['status' => 'completed']);

        // Получение информации о крафтимом предмете
        $craftedItem = $this->craftedItemsModel->where('name_eng', 'RobotExplorer')->first();

        if (!$craftedItem) {
            log_message('error', 'Crafted item not found in the database.');
            return;
        }

        // Обновление или создание лога крафта
        $this->updateCraftLog($task, $craftedItem);

        // Обновление атрибутов персонажа после крафта
        $this->characterModel->updateAgilityAndIntellect(
            $task['character_id'],
            0.75, // увеличение ловкости
            0.55  // увеличение интеллекта
        );

        // Отправка уведомления в Telegram
        $this->notifyUser($task['telegram_user_id'], $craftedItem, $task['character_id']);
    }

    private function updateCraftLog($task, $craftedItem)
    {
        $existingLog = $this->craftedItemsLogModel->where([
            'character_id' => $task['character_id'],
            'crafted_item_id' => $craftedItem['id']
        ])->first();

        if ($existingLog) {
            $this->craftedItemsLogModel->update($existingLog['id'], [
                'quantity' => $existingLog['quantity'] + 1
            ]);
        } else {
            $this->craftedItemsLogModel->insert([
                'character_id' => $task['character_id'],
                'task_id' => $task['task_id'],
                'crafted_item_id' => $craftedItem['id'],
                'type' => $craftedItem['type'],
                'direction_craft' => $craftedItem['direction_craft'],
                'crafting_location' => $craftedItem['crafting_location'],
                'durability_count' => $craftedItem['durability_count'],
                'durability_time' => NULL,
                'quantity' => 1
            ]);
        }
    }

    private function notifyUser($telegramUserId, $craftedItem, $characterId): \Longman\TelegramBot\Entities\ServerResponse
    {
        // Получение Telegram ID пользователя
        $telegram_id = $this->telegramUserModel->where('id', $telegramUserId)->first()['telegram_id'];

        // Получение текущего количества скрафченных предметов
        $existingLog = $this->craftedItemsLogModel->where([
            'character_id' => $characterId,
            'crafted_item_id' => $craftedItem['id']
        ])->first();

        $quantity = $existingLog ? $existingLog['quantity'] : 0;

        $text = "📌 Вы успешно скрафтили предмет:\n\n"
            . "🤖 *{$craftedItem['name_rus']}*\n\n"
            . "В наличии: *{$quantity} шт.*\n\n"
            . "Зона применения: *Биом* 🌳";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔄 Крафтить еще', 'callback_data' => 'craftRobotExplorer2'],
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                ]
            ]
        ];
        $imagePath = base_url('uploads/telegram/craft/standard/robot_explorer.jpg');

        Request::answerCallbackQuery(['callback_query_id' => $telegram_id]);
        try {
            return Request::sendPhoto([
                'chat_id' => $telegram_id,
                'photo'   => Request::encodeFile($imagePath),
                'caption' => $text,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Telegram API error: " . $e->getMessage());
            return Request::sendMessage([
                'chat_id' => $telegram_id,
                'text' => "Произошла ошибка: " . $e->getMessage(),
            ]);
        }
    }

}
