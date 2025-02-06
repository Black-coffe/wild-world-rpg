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

class CraftCompletionGlassBagsHandler extends Controller
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
        // 1) Закрываем задачу
        $this->characterTaskModel->update($task['id'], ['status' => 'completed']);

        // 2) Получаем информацию о предмете (GlassBags)
        $craftedItem = $this->craftedItemsModel->where('name_eng', 'GlassBags')->first();
        if (!$craftedItem) {
            log_message('error', 'Crafted item "GlassBags" not found in DB.');
            return;
        }

        // 3) Извлекаем количество из task_settings
        $quantityToAdd = $this->getQuantityFromTaskSettings($task);

        // 4) Обновляем (или создаём) запись crafted_items_log
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

        // 5) Прокачиваем персонажа (+0.15 к ловкости и интеллекту, как в примере)
        $this->characterModel->updateAgilityAndIntellect(
            $task['character_id'],
            0.02,
            0.01
        );

        // 6) Уведомляем игрока
        $this->notifyUser($task['telegram_user_id'], $craftedItem, $task['character_id'], $quantityToAdd);
    }

    /**
     * Извлекаем 'quantity' из task_settings (JSON). По умолчанию 1.
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

    private function notifyUser(int $telegramUserId, array $craftedItem, int $characterId, int $qtyAdded)
    {
        // Находим Telegram ID пользователя
        $userRow = $this->telegramUserModel->find($telegramUserId);
        if (!$userRow) {
            log_message('error', "User row not found for ID {$telegramUserId}.");
            return;
        }

        $telegramId = $userRow['telegram_id'] ?? null;
        if (!$telegramId) {
            log_message('error', "No telegram_id for user ID {$telegramUserId}.");
            return;
        }

        // Текущее общее количество стеклопакетов
        $existingLog = $this->craftedItemsLogModel->where([
            'character_id'    => $characterId,
            'crafted_item_id' => $craftedItem['id']
        ])->first();
        $totalNow = $existingLog ? (int)$existingLog['quantity'] : 0;

        $itemNameRus = $craftedItem['name_rus'] ?? 'Стеклопакеты';

        $text = "📌 *Крафт завершён!*\n\n"
            . "Ты создал: 🪟 *{$itemNameRus}* x{$qtyAdded} шт.\n\n"
            . "Теперь у тебя *{$totalNow} шт.*\n\n"
            . "Зона применения: *Производство* 🏭";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔄 Крафтить еще', 'callback_data' => 'craftGlassBags_1'],
                    ['text' => '🎒 Инвентарь',    'callback_data' => 'inventory'],
                ]
            ]
        ];

        $imagePath = base_url('uploads/telegram/craft/components/craftGlassBags.jpg');

        // Закрываем "часики"
        Request::answerCallbackQuery(['callback_query_id' => $telegramId]);

        try {
            Request::sendPhoto([
                'chat_id' => $telegramId,
                'photo'   => Request::encodeFile($imagePath),
                'caption' => $text,
                'parse_mode' => 'Markdown',
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
