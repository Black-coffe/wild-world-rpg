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

class CraftCompletionStimulatorHandler extends Controller
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

    public function handle($task)
    {
        // 1. Закрываем задачу, переводим статус в completed
        $this->characterTaskModel->update($task['id'], ['status' => 'completed']);

        // 2. Ищем предмет по name_eng = 'Stimulator'
        $craftedItem = $this->craftedItemsModel->where('name_eng', 'Stimulator')->first();
        if (!$craftedItem) {
            log_message('error', 'Crafted item "Stimulator" not found in the database.');
            return;
        }

        // 3. Извлекаем количество из task_settings (по умолчанию 1)
        $quantityToAdd = $this->getQuantityFromTaskSettings($task);

        // 4. Обновляем или создаём запись в crafted_items_log
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
            // Если запись отсутствует, создаём новую
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

        // 5. Даём игроку бонус к атрибутам (пример: +0.05 ловкости/интеллекта)
        $this->characterModel->updateAgilityAndIntellect(
            $task['character_id'],
            0.05, // увеличение ловкости
            0.05  // увеличение интеллекта
        );

        // 6. Уведомляем игрока
        $this->notifyUser($task['telegram_user_id'], $craftedItem, $task['character_id'], $quantityToAdd);
    }

    /**
     * Извлекаем 'quantity' из поля task_settings.
     * Если оно не задано или некорректно, возвращаем 1.
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
     * Уведомляем игрока о завершении крафта:
     * сколько добавлено, сколько теперь всего.
     */
    private function notifyUser(int $telegramUserId, array $craftedItem, int $characterId, int $quantityAdded)
    {
        // Получаем telegram_id пользователя
        $row = $this->telegramUserModel->where('id', $telegramUserId)->first();
        if (!$row) {
            log_message('error', "Telegram user with ID {$telegramUserId} not found.");
            return;
        }
        $telegramId = $row['telegram_id'] ?? null;

        if (!$telegramId) {
            log_message('error', "No telegram_id found for user ID {$telegramUserId}.");
            return;
        }

        // Узнаём, сколько всего теперь у игрока
        $updatedLog = $this->craftedItemsLogModel->where([
            'character_id'    => $characterId,
            'crafted_item_id' => $craftedItem['id']
        ])->first();

        $totalNow = $updatedLog ? (int)$updatedLog['quantity'] : 0;

        // Русское название предмета (если есть), иначе задаём дефолт
        $itemNameRus = $craftedItem['name_rus'] ?? "Стимулятор";

        // Формируем текст сообщения
        $text = "📌 *Крафт завершён!*\n\n"
            . "Ты создал: 💉 *{$itemNameRus}* x{$quantityAdded} шт.\n\n"
            . "Теперь у тебя *{$totalNow} шт.* в инвентаре.\n\n"
            . "Зона применения: *медицина* 💊";

        // Кнопка "Крафтить ещё" -> `craftStimulator_1`
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔄 Крафтить еще', 'callback_data' => 'craftStimulator_1'],
                    ['text' => '🎒 Инвентарь',     'callback_data' => 'inventory'],
                ]
            ]
        ];

        $imagePath = base_url('uploads/telegram/craft/liquid_mixture_of_very_invigorating_acid-green_beverage.jpg');

        // Попытка ответить на callbackQuery (если актуально)
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
            Request::sendMessage([
                'chat_id' => $telegramId,
                'text'    => "Произошла ошибка: " . $e->getMessage(),
            ]);
        }
    }
}
