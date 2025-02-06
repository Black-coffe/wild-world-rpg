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
 * Класс, вызываемый при завершении крафта «Электронных компонентов» (electronicComponents).
 * Аналогичен примеру для проводки, но заточен под name_eng="electronicComponents".
 */
class CraftCompletionElectronicComponentsHandler extends Controller
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
     * Вызывается, когда задача крафта "electronicComponents" завершается (например, по CRON).
     * @param array $task Данные о задаче из character_tasks (включая task_settings, character_id, telegram_user_id, ...)
     */
    public function handle(array $task)
    {
        // 1. Завершаем задачу
        $this->characterTaskModel->update($task['id'], ['status' => 'completed']);

        // 2. Ищем предмет "Электронные компоненты" (electronicComponents) в таблице crafted_items
        $craftedItem = $this->craftedItemsModel->where('name_eng', 'electronicComponents')->first();
        if (!$craftedItem) {
            log_message('error', 'Crafted item (electronicComponents) not found in the database.');
            return;
        }

        // 3. Считываем, сколько штук было крафтится (quantity) из task_settings
        $quantityToAdd = $this->getQuantityFromTaskSettings($task);

        // 4. Обновляем (или вставляем) запись в crafted_items_log
        $existingLog = $this->craftedItemsLogModel->where([
            'character_id'    => $task['character_id'],
            'crafted_item_id' => $craftedItem['id']
        ])->first();

        if ($existingLog) {
            // Увеличиваем количество
            $newQty = $existingLog['quantity'] + $quantityToAdd;
            $this->craftedItemsLogModel->update($existingLog['id'], ['quantity' => $newQty]);
        } else {
            // Создаём новую запись
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

        // 5. Немного прокачаем персонажу интеллект (или другое)
        $this->characterModel->updateAgilityAndIntellect(
            $task['character_id'],
            0.01,   // agility: не трогаем
            0.02    // intellect: +0.15 (пример)
        );

        // 6. Уведомляем игрока
        $this->notifyUser($task['telegram_user_id'], $craftedItem, $task['character_id'], $quantityToAdd);
    }

    /**
     * Достаёт quantity из task_settings, если оно там прописано. Иначе 1.
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
     * Уведомляем игрока о завершении крафта: сколько шт. получилось, сколько теперь всего.
     */
    private function notifyUser(int $telegramUserId, array $craftedItem, int $characterId, int $qtyAdded)
    {
        // Ищем telegram_id
        $row = $this->telegramUserModel->where('id', $telegramUserId)->first();
        if (!$row) {
            log_message('error', "User not found: ID={$telegramUserId}");
            return;
        }
        $telegramId = $row['telegram_id'] ?? null;
        if (!$telegramId) {
            log_message('error', "No telegram_id for user ID={$telegramUserId}");
            return;
        }

        // Смотрим, сколько всего предмета теперь
        $logEntry = $this->craftedItemsLogModel->where([
            'character_id'    => $characterId,
            'crafted_item_id' => $craftedItem['id']
        ])->first();
        $totalNow = $logEntry ? (int)$logEntry['quantity'] : 0;

        // Название (русское) из таблицы
        $itemNameRus = $craftedItem['name_rus'] ?? "Электронные компоненты";

        // Формируем текст
        $text = "📌 *Крафт завершён!*\n\n"
            . "Ты создал: 💻 *{$itemNameRus}* x{$qtyAdded} шт.\n\n"
            . "Теперь у тебя *{$totalNow} шт.* этого предмета в инвентаре.\n\n"
            . "_Применяются для сборки электроники и механизмов._";

        // Inline-кнопки
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔄 Крафтить ещё', 'callback_data' => 'craftElectronicComponents_1'],
                    ['text' => '🎒 Инвентарь',    'callback_data' => 'inventory'],
                ]
            ]
        ];

        // Путь к изображению
        $imagePath = base_url('uploads/telegram/craft/components/electronic_components.jpg');

        // Пробуем ответить на callbackQuery
        try {
            Request::answerCallbackQuery(['callback_query_id' => $telegramId]);
        } catch (TelegramException $e) {
            log_message('error', "answerCallbackQuery error: " . $e->getMessage());
        }

        // Отправляем сообщение с фото
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
