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

class CraftCompletionSoilHandler extends Controller
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
        // 1. Переводим статус задачи в "completed"
        $this->characterTaskModel->update($task['id'], ['status' => 'completed']);

        // 2. Ищем сам предмет "Soil" (name_eng='Soil') в таблице crafted_items
        $craftedItem = $this->craftedItemsModel->where('name_eng', 'Soil')->first();
        if (!$craftedItem) {
            log_message('error', 'Crafted item "Soil" not found in the database.');
            return;
        }

        // 3. Считываем, сколько штук было заказано (из task_settings)
        $quantity = $this->getQuantityFromTask($task);
        if ($quantity < 1) {
            // Если вдруг quantity не удалось получить (ошибка) — по умолчанию добавим 1
            $quantity = 1;
        }

        // 4. Проверяем, есть ли уже запись в crafted_items_log
        $existingLog = $this->craftedItemsLogModel->where([
            'character_id'    => $task['character_id'],
            'crafted_item_id' => $craftedItem['id']
        ])->first();

        if ($existingLog) {
            // 4.a Увеличиваем количество на $quantity
            $newQty = $existingLog['quantity'] + $quantity;
            $this->craftedItemsLogModel->update($existingLog['id'], [
                'quantity' => $newQty
            ]);
        } else {
            // 4.b Создаем новую запись
            $this->craftedItemsLogModel->insert([
                'character_id'     => $task['character_id'],
                'task_id'          => $task['task_id'],
                'crafted_item_id'  => $craftedItem['id'],
                'type'             => $craftedItem['type'],
                'direction_craft'  => $craftedItem['direction_craft'],
                'crafting_location'=> $craftedItem['crafting_location'],
                'durability_count' => $craftedItem['durability_count'],
                'durability_time'  => null,
                'quantity'         => $quantity
            ]);
        }

        // 5. Повышаем атрибуты (пример: +0.05 ловкости и интеллекта)
        $this->characterModel->updateAgilityAndIntellect(
            $task['character_id'],
            0.05,  // + ловкость
            0.05   // + интеллект
        );

        // 6. Отправляем уведомление игроку
        $this->notifyUser($task['telegram_user_id'], $craftedItem, $task['character_id'], $quantity);
    }

    /**
     * Извлекает "quantity" из task_settings, если там прописано {"quantity":...}
     */
    private function getQuantityFromTask(array $task): int
    {
        if (!empty($task['task_settings'])) {
            $settings = json_decode($task['task_settings'], true);
            if (json_last_error() === JSON_ERROR_NONE && isset($settings['quantity'])) {
                return (int)$settings['quantity'];
            }
        }
        return 0;
    }

    /**
     * Уведомление игрока о завершении крафта
     */
    private function notifyUser(
        int $telegramUserId,
        array $craftedItem,
        int $characterId,
        int $craftedQty
    ): \Longman\TelegramBot\Entities\ServerResponse
    {
        // 1. Получаем telegram_id из telegram_users
        $userRow = $this->telegramUserModel->where('id', $telegramUserId)->first();
        if (!$userRow || empty($userRow['telegram_id'])) {
            log_message('error', "Telegram ID not found for user_id=$telegramUserId");
            return Request::emptyResponse();
        }
        $telegram_id = $userRow['telegram_id'];

        // 2. Узнаём текущее суммарное количество
        $existingLog = $this->craftedItemsLogModel->where([
            'character_id'    => $characterId,
            'crafted_item_id' => $craftedItem['id']
        ])->first();

        $totalQty = $existingLog ? (int)$existingLog['quantity'] : $craftedQty;

        // 3. Формируем сообщение
        $text = "📌 *Крафт завершён!*\n\n"
            . "Ты получил: *{$craftedItem['name_rus']}* (x{$craftedQty} шт.)\n\n"
            . "Теперь у тебя их *{$totalQty} шт.*\n\n"
            . "Зона применения: *Производство* 🏭";

        // 4. Формируем клавиатуру
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔄 Крафтить еще', 'callback_data' => 'craftSoil'],
                    ['text' => '🎒 Инвентарь',    'callback_data' => 'inventory'],
                ],
            ]
        ];

        // 5. Отправляем фото
        $imagePath = base_url('uploads/telegram/craft/components/craftSoil.jpg');

        Request::answerCallbackQuery([
            'callback_query_id' => $telegram_id,
            // если callback_query_id == $telegram_id, это обычно неверно:
            //   callback_query_id нужен непосредственно для answerCallbackQuery
            //   Но если хочется закрыть "часики" в ЛС,
            //   нужно иметь актуальный callbackQueryId.
            //   В Cron/TaskHandler обычно callbackQueryId недоступен.
        ]);

        try {
            return Request::sendPhoto([
                'chat_id'    => $telegram_id,
                'photo'      => Request::encodeFile($imagePath),
                'caption'    => $text,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Telegram API error: " . $e->getMessage());
            return Request::sendMessage([
                'chat_id' => $telegram_id,
                'text'    => "Произошла ошибка при отправке сообщения: " . $e->getMessage(),
            ]);
        }
    }
}
