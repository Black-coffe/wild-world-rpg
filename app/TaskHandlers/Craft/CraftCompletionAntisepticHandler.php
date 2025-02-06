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

class CraftCompletionAntisepticHandler extends Controller
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
     * Основной метод, который вызывается CRON-скриптом (или иным обработчиком),
     * когда время крафта истекает и задачу нужно завершить.
     *
     * @param array $task Запись из таблицы character_tasks
     */
    public function handle($task)
    {
        // 1. Закрываем задачу (ставим статус completed).
        $this->characterTaskModel->update($task['id'], ['status' => 'completed']);

        // 2. Получаем информацию о том, какой предмет крафтился: для Антисептика name_eng='Antiseptic'.
        //    (Опционально можно вынести в логику, если планируется универсальный Handler.)
        $craftedItem = $this->craftedItemsModel->where('name_eng', 'Antiseptic')->first();
        if (!$craftedItem) {
            log_message('error', 'Crafted item Antiseptic not found in the database.');
            return;
        }

        // 3. Извлекаем количество крафта из task_settings (JSON).
        $quantityToAdd = $this->getQuantityFromTaskSettings($task);

        // 4. Обновляем / создаём запись в crafted_items_log
        $this->updateCraftLog($task, $craftedItem, $quantityToAdd);

        // 5. Даём персонажу бонусы (пример: +0.05 к ловкости/интеллекту).
        $this->characterModel->updateAgilityAndIntellect($task['character_id'], 0.01, 0.02);

        // 6. Уведомляем пользователя в Telegram
        $this->notifyUser($task['telegram_user_id'], $craftedItem, $task['character_id'], $quantityToAdd);
    }

    /**
     * Извлекает количество предметов крафта из поля task_settings.
     * Если значение не найдено/парсинг не удался, возвращает 1 по умолчанию.
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
     * Обновляет (или добавляет) запись в crafted_items_log, увеличивая количество на $quantityToAdd.
     */
    private function updateCraftLog(array $task, array $craftedItem, int $quantityToAdd)
    {
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
                'character_id'     => $task['character_id'],
                'task_id'          => $task['task_id'],
                'crafted_item_id'  => $craftedItem['id'],
                'type'             => $craftedItem['type'],
                'direction_craft'  => $craftedItem['direction_craft'],
                'crafting_location'=> $craftedItem['crafting_location'],
                'durability_count' => $craftedItem['durability_count'],
                'durability_time'  => null,
                'quantity'         => $quantityToAdd,
            ]);
        }
    }

    /**
     * Уведомляет игрока о завершении крафта и выводит итоговое количество данного предмета в инвентаре.
     */
    private function notifyUser(int $telegramUserId, array $craftedItem, int $characterId, int $craftedAmount)
    {
        // Получаем Telegram ID (chat_id)
        $telegramRow = $this->telegramUserModel->where('id', $telegramUserId)->first();
        if (!$telegramRow) {
            log_message('error', "Telegram user with ID {$telegramUserId} not found.");
            return;
        }

        $telegram_id = $telegramRow['telegram_id'] ?? null;
        if (!$telegram_id) {
            log_message('error', "No telegram_id found for user ID {$telegramUserId}.");
            return;
        }

        // Проверяем, сколько теперь у игрока всего Антисептиков
        $existingLog = $this->craftedItemsLogModel->where([
            'character_id'    => $characterId,
            'crafted_item_id' => $craftedItem['id']
        ])->first();

        $totalQuantity = $existingLog ? (int)$existingLog['quantity'] : 0;

        $itemNameRus = $craftedItem['name_rus'] ?: 'Антисептик';
        $text  = "📌 Вы успешно закончили крафт предмета:\n\n"
            . "🧴 *{$itemNameRus}*\n"
            . "Создано: *{$craftedAmount} шт.*\n"
            . "Теперь всего в инвентаре: *{$totalQuantity} шт.*\n\n"
            . "Зона применения: *медицина* 💊";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔄 Крафтить еще', 'callback_data' => 'antiseptic'],
                    ['text' => '🎒 Инвентарь',     'callback_data' => 'inventory'],
                ]
            ]
        ];
        $imagePath = base_url('uploads/telegram/craft/antiseptic_craft.jpg');

        // Попытка ответа (answerCallbackQuery) на case, который уже неактуален (т.к. это завершение) может дать ошибку,
        // но можно проигнорировать или убрать. Оставим try-catch.
        try {
            Request::answerCallbackQuery(['callback_query_id' => $telegram_id]);
        } catch (TelegramException $e) {
            log_message('error', "answerCallbackQuery error: " . $e->getMessage());
        }

        // Отправляем сообщение о результате крафта
        try {
            Request::sendPhoto([
                'chat_id'      => $telegram_id,
                'photo'        => Request::encodeFile($imagePath),
                'caption'      => $text,
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Telegram API error: " . $e->getMessage());
            // fallback
            Request::sendMessage([
                'chat_id' => $telegram_id,
                'text'    => "Произошла ошибка при отправке фото: " . $e->getMessage(),
            ]);
        }
    }
}
