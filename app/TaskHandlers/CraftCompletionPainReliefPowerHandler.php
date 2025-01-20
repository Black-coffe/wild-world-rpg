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

class CraftCompletionPainReliefPowerHandler extends Controller
{
    protected $characterTaskModel;
    protected $craftedItemsModel;
    protected $craftedItemsLogModel;
    protected $telegramUserModel;
    protected $characterModel;
    private $telegram;

    public function __construct()
    {
        $this->characterModel         = new CharacterModel();
        $this->characterTaskModel     = new CharacterTaskModel();
        $this->craftedItemsModel      = new CraftedItemsModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();
        $this->telegramUserModel      = new TelegramUserModel();

        $API_KEY       = getenv('telegram.API_KEY');
        $BOT_USERNAME  = getenv('telegram.BOT_USERNAME');

        try {
            $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
            Request::initialize($this->telegram);
        } catch (TelegramException $e) {
            log_message('error', $e->getMessage());
        }
    }

    /**
     * Основной метод, вызываемый при завершении задачи крафта.
     * @param array $task Запись из character_tasks
     */
    public function handle($task)
    {
        // 1. Закрываем задачу
        $this->characterTaskModel->update($task['id'], ['status' => 'completed']);

        // 2. Ищем инфу о предмете (PainReliefPower = "AnalgesicPowder" в name_eng)
        $craftedItem = $this->craftedItemsModel->where('name_eng', 'AnalgesicPowder')->first();
        if (!$craftedItem) {
            log_message('error', 'Crafted item (AnalgesicPowder) not found in the database.');
            return;
        }

        // 3. Вытаскиваем, сколько штук нужно добавить (из task_settings)
        $quantityToAdd = $this->getQuantityFromTaskSettings($task);

        // 4. Обновляем / создаём запись в crafted_items_log
        $existingLog = $this->craftedItemsLogModel->where([
            'character_id'    => $task['character_id'],
            'crafted_item_id' => $craftedItem['id'],
        ])->first();

        if ($existingLog) {
            $newQuantity = $existingLog['quantity'] + $quantityToAdd;
            $this->craftedItemsLogModel->update($existingLog['id'], [
                'quantity' => $newQuantity,
            ]);
        } else {
            // Если предмета вообще не было
            $this->craftedItemsLogModel->insert([
                'character_id'      => $task['character_id'],
                'task_id'           => $task['task_id'],
                'crafted_item_id'   => $craftedItem['id'],
                'type'              => $craftedItem['type'],
                'direction_craft'   => $craftedItem['direction_craft'],
                'crafting_location' => $craftedItem['crafting_location'],
                'durability_count'  => $craftedItem['durability_count'],
                'durability_time'   => null,
                'quantity'          => $quantityToAdd,
            ]);
        }

        // 5. Прокачиваем персонажу атрибуты (пример — +0.05 ловкости/интеллекта)
        $this->characterModel->updateAgilityAndIntellect(
            $task['character_id'],
            0.05,
            0.05
        );

        // 6. Уведомляем пользователя
        $this->notifyUser($task['telegram_user_id'], $craftedItem, $task['character_id'], $quantityToAdd);
    }

    /**
     * Извлекает 'quantity' из поля task_settings (JSON),
     * если нет или некорректно — по умолчанию 1.
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
     * Уведомляем пользователя о завершении крафта.
     */
    private function notifyUser(int $telegramUserId, array $craftedItem, int $characterId, int $quantityAdded)
    {
        // Получаем chat_id
        $row = $this->telegramUserModel->where('id', $telegramUserId)->first();
        if (!$row) {
            log_message('error', "Telegram user row not found for ID: {$telegramUserId}");
            return;
        }
        $telegramId = $row['telegram_id'] ?? null;

        if (!$telegramId) {
            log_message('error', "No 'telegram_id' found for user ID: {$telegramUserId}");
            return;
        }

        // Смотрим, сколько теперь всего у игрока
        $updatedLog = $this->craftedItemsLogModel->where([
            'character_id'    => $characterId,
            'crafted_item_id' => $craftedItem['id'],
        ])->first();

        $totalNow = $updatedLog ? (int)$updatedLog['quantity'] : 0;

        // Русское название предмета
        $itemNameRus = $craftedItem['name_rus'] ?? 'Обезболивающий порошок';

        // Собираем текст
        $text = "📌 *Крафт завершён!*\n\n"
            . "Ты изготовил: 🌡️ *{$itemNameRus}* x{$quantityAdded} шт.\n\n"
            . "Теперь у тебя *{$totalNow} шт.* этого предмета в инвентаре.\n\n"
            . "Медицинское назначение: помогает при боли, даёт +12 HP, но уменьшает выносливость.\n"
            . "Используй с умом!";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔄 Крафтить еще', 'callback_data' => 'craftPainReliefPower_1'],
                    ['text' => '🎒 Инвентарь',     'callback_data' => 'inventory'],
                ],
            ],
        ];

        $imagePath = base_url('uploads/telegram/craft/analgesic_powder.jpg');

        // Попытка ответить на callbackQuery (не всегда актуально при CRON)
        try {
            Request::answerCallbackQuery(['callback_query_id' => $telegramId]);
        } catch (TelegramException $e) {
            log_message('error', "answerCallbackQuery error: " . $e->getMessage());
        }

        // Отправляем фото с подписью
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
            // Резервный вариант
            Request::sendMessage([
                'chat_id' => $telegramId,
                'text'    => "Произошла ошибка при отправке фото: " . $e->getMessage(),
            ]);
        }
    }
}
