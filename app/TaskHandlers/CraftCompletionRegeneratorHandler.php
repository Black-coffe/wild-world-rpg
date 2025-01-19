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

class CraftCompletionRegeneratorHandler extends Controller
{
    protected $characterModel;
    protected $characterTaskModel;
    protected $craftedItemsModel;
    protected $craftedItemsLogModel;
    protected $telegramUserModel;
    private $telegram;

    public function __construct()
    {
        $this->characterModel = new CharacterModel();
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
        $craftedItem = $this->craftedItemsModel->where('name_eng', 'Regenerator')->first();

        if (!$craftedItem) {
            // Ошибка, если предмет не найден
            log_message('error', 'Crafted item not found in the database.');
            return;
        }

        // Проверка, существует ли уже такой предмет в логе
        $existingLog = $this->craftedItemsLogModel->where([
            'character_id' => $task['character_id'],
            'crafted_item_id' => $craftedItem['id']
        ])->first();

        if ($existingLog) {
            // Увеличиваем количество, если предмет уже есть в логе
            $this->craftedItemsLogModel->update($existingLog['id'], [
                'quantity' => $existingLog['quantity'] + 1
            ]);
        } else {
            // Добавляем новую запись, если предмета еще нет
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

        // Обновление атрибутов персонажа после крафта
        $this->characterModel->updateAgilityAndIntellect(
            $task['character_id'],
            0.05, // увеличение ловкости
            0.05  // увеличение интеллекта
        );

        // Отправка уведомления в Telegram
        $this->notifyUser($task['telegram_user_id'], $craftedItem, $task['character_id']);
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
            . "🔋 *{$craftedItem['name_rus']}*\n\n"
            . "В наличии: *{$quantity} шт.*\n\n"
            . "Зона применения: *медицина* 💊";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔄 Крафтить еще', 'callback_data' => 'craftRegenerator'],
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                ]
            ]
        ];
        $imagePath = base_url('uploads/telegram/craft/health_and_strength_regenerator.jpg');

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
