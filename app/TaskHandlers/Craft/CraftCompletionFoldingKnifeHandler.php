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

class CraftCompletionFoldingKnifeHandler extends Controller
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
     * Основной метод, вызываемый при завершении задачи (CRON).
     * @param array $task Запись из character_tasks (со статусом in_work, потом становится completed).
     */
    public function handle($task)
    {
        // 1. Переводим задачу в статус completed
        $this->characterTaskModel->update($task['id'], ['status' => 'completed']);

        // 2. Ищем предмет "FoldingKnife" (name_eng='FoldingKnife')
        $craftedItem = $this->craftedItemsModel->where('name_eng', 'FoldingKnife')->first();
        if (!$craftedItem) {
            log_message('error', 'Crafted item "FoldingKnife" not found in the database.');
            return;
        }

        // 3. Получаем quantity из task_settings
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
            // Если предмета ещё нет, создаём запись
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

        // 5. Повышаем атрибуты персонажа (пример: +0.05 ловкости, +0.05 интеллекта)
        $this->characterModel->updateAgilityAndIntellect(
            $task['character_id'],
            0.05,
            0.05
        );

        // 6. Уведомляем пользователя
        $this->notifyUser($task['telegram_user_id'], $craftedItem, $task['character_id'], $quantityToAdd);
    }

    /**
     * Считываем "quantity" из поля task_settings (JSON).
     * Если нет / некорректно — возвращаем 1.
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
     * Уведомляем игрока об окончании крафта: сколько ножей добавлено, сколько всего теперь.
     */
    private function notifyUser(int $telegramUserId, array $craftedItem, int $characterId, int $quantityAdded)
    {
        // Получаем telegram_id
        $userRow = $this->telegramUserModel->where('id', $telegramUserId)->first();
        if (!$userRow) {
            log_message('error', "Telegram user not found: ID {$telegramUserId}");
            return;
        }
        $telegramId = $userRow['telegram_id'] ?? null;
        if (!$telegramId) {
            log_message('error', "No telegram_id found for user ID {$telegramUserId}.");
            return;
        }

        // Узнаём, сколько теперь всего
        $updatedLog = $this->craftedItemsLogModel->where([
            'character_id'    => $characterId,
            'crafted_item_id' => $craftedItem['id']
        ])->first();
        $totalNow = $updatedLog ? (int)$updatedLog['quantity'] : 0;

        // Русское название, если есть
        $itemNameRus = $craftedItem['name_rus'] ?? "Складной нож";

        $text = "📌 *Крафт завершён!*\n\n"
            . "Ты создал: 🔪 *{$itemNameRus}* x{$quantityAdded} шт.\n\n"
            . "Теперь у тебя *{$totalNow} шт.* в инвентаре.\n\n"
            . "Зона применения: *Инструменты* 🛠️";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔄 Крафтить еще', 'callback_data' => 'craftFoldingKnife_1'],
                    ['text' => '🎒 Инвентарь',     'callback_data' => 'inventory'],
                ]
            ]
        ];
        $imagePath = base_url('uploads/telegram/craft/an-old-but-sharp-folding-knife.jpg');

        // Пытаемся ответить на callbackQuery (если оно ещё актуально)
        try {
            Request::answerCallbackQuery(['callback_query_id' => $telegramId]);
        } catch (TelegramException $e) {
            log_message('error', "answerCallbackQuery error: ".$e->getMessage());
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
            log_message('error', "Telegram API error: ".$e->getMessage());
            // fallback
            Request::sendMessage([
                'chat_id' => $telegramId,
                'text'    => "Произошла ошибка: ".$e->getMessage(),
            ]);
        }
    }
}
