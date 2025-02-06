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

class CraftCompletionIronShovelHandler extends Controller
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
     * Основной метод, который вызывается при завершении крафта (через CRON или иной планировщик).
     * Из массива $task мы можем получить task_settings.
     */
    public function handle($task)
    {
        // 1) Закрываем задачу
        $this->characterTaskModel->update($task['id'], ['status' => 'completed']);

        // 2) Находим предмет (IronShovel)
        $craftedItem = $this->craftedItemsModel->where('name_eng', 'IronShovel')->first();
        if (!$craftedItem) {
            log_message('error', 'Crafted item "IronShovel" not found in the database.');
            return;
        }

        // 3) Считываем количество из task_settings
        $quantityToAdd = $this->getQuantityFromTaskSettings($task);

        // 4) Добавляем (или создаём) запись в crafted_items_log
        $existingLog = $this->craftedItemsLogModel->where([
            'character_id'    => $task['character_id'],
            'crafted_item_id' => $craftedItem['id']
        ])->first();

        if ($existingLog) {
            $newQuantity = $existingLog['quantity'] + $quantityToAdd;
            $this->craftedItemsLogModel->update($existingLog['id'], [
                'quantity' => $newQuantity
            ]);
        } else {
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

        // 5) Улучшаем атрибуты персонажа (например, +0.05 к ловкости и интеллекту)
        $this->characterModel->updateAgilityAndIntellect(
            $task['character_id'],
            0.02,
            0.01
        );

        // 6) Уведомляем игрока о результате
        $this->notifyUser($task['telegram_user_id'], $craftedItem, $task['character_id'], $quantityToAdd);
    }

    /**
     * Извлекает "quantity" из task_settings JSON. Если нет, возвращает 1.
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
     * Отправляем уведомление о кол-ве добавленных лопат, итоговом запасе и т. д.
     */
    private function notifyUser(int $telegramUserId, array $craftedItem, int $characterId, int $quantityAdded)
    {
        // Получаем запись о пользователе
        $row = $this->telegramUserModel->where('id', $telegramUserId)->first();
        if (!$row) {
            log_message('error', "User row not found: ID = {$telegramUserId}");
            return;
        }

        $telegramId = $row['telegram_id'] ?? null;
        if (!$telegramId) {
            log_message('error', "No telegram_id found for user ID {$telegramUserId}");
            return;
        }

        // Сколько теперь всего?
        $logRow   = $this->craftedItemsLogModel->where([
            'character_id' => $characterId,
            'crafted_item_id' => $craftedItem['id']
        ])->first();
        $totalNow = $logRow ? (int)$logRow['quantity'] : 0;

        // Русское имя, если есть. Иначе "Железная лопата"
        $itemNameRus = $craftedItem['name_rus'] ?? "Железная лопата";

        $text = "📌 *Крафт завершён!*\n\n"
            . "Ты создал: 🥄 *{$itemNameRus}* x{$quantityAdded} шт.\n\n"
            . "Теперь у тебя *{$totalNow} шт.* в инвентаре.\n\n"
            . "Зона применения: *Инструменты* 🛠️";

        // Кнопка "Крафтить ещё" (1 шт. по умолчанию)
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔄 Крафтить еще', 'callback_data' => 'craftIronShovel_1'],
                    ['text' => '🎒 Инвентарь',    'callback_data' => 'inventory'],
                ]
            ]
        ];

        $imagePath = base_url('uploads/telegram/craft/image-of-a-typical-metal-shovel.jpg');

        // Пытаемся ответить на callback
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
