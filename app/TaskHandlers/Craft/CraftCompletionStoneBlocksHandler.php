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

class CraftCompletionStoneBlocksHandler extends Controller
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

        $API_KEY       = getenv('telegram.API_KEY');
        $BOT_USERNAME  = getenv('telegram.BOT_USERNAME');

        try {
            $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
            Request::initialize($this->telegram);
        } catch (TelegramException $e) {
            log_message('error', $e->getMessage());
        }
    }

    public function handle($task)
    {
        // 1) Закрываем задачу (статус = 'completed')
        $this->characterTaskModel->update($task['id'], ['status' => 'completed']);

        // 2) Получаем информацию о крафтимом предмете (name_eng = 'stoneBlocks')
        $craftedItem = $this->craftedItemsModel->where('name_eng', 'stoneBlocks')->first();
        if (!$craftedItem) {
            log_message('error', 'Crafted item "stoneBlocks" not found in the database.');
            return;
        }

        // 3) Определяем, сколько штук крафтилось:
        //    Декодируем task_settings (если есть), берем field "quantity".
        $taskSettings = !empty($task['task_settings']) ? json_decode($task['task_settings'], true) : [];
        $quantity     = isset($taskSettings['quantity']) ? (int)$taskSettings['quantity'] : 1;
        if ($quantity < 1) {
            $quantity = 1;
        }

        // 4) Смотрим, есть ли уже запись в логе crafted_items_log
        $existingLog = $this->craftedItemsLogModel
            ->where('character_id',    $task['character_id'])
            ->where('crafted_item_id', $craftedItem['id'])
            ->first();

        // 5) Если запись уже есть, прибавляем $quantity, иначе создаём новую
        if ($existingLog) {
            $newQty = (int)$existingLog['quantity'] + $quantity;
            $this->craftedItemsLogModel->update($existingLog['id'], ['quantity' => $newQty]);
        } else {
            $this->craftedItemsLogModel->insert([
                'character_id'       => $task['character_id'],
                'task_id'            => $task['task_id'],
                'crafted_item_id'    => $craftedItem['id'],
                'type'               => $craftedItem['type'],
                'direction_craft'    => $craftedItem['direction_craft'],
                'crafting_location'  => $craftedItem['crafting_location'],
                'durability_count'   => $craftedItem['durability_count'],
                'durability_time'    => null,
                'quantity'           => $quantity,
            ]);
        }

        // 6) Прокачиваем ловкость/интеллект персонажа
        $this->characterModel->updateAgilityAndIntellect(
            $task['character_id'],
            0.05, // +0.05 ловкости
            0.05  // +0.05 интеллекта
        );

        // 7) Уведомляем игрока
        $this->notifyUser($task['telegram_user_id'], $craftedItem, $task['character_id'], $quantity);
    }

    /**
     * Отправляем игроку сообщение о завершении крафта.
     * Передаём $quantity, чтобы показать, сколько скрафчено.
     */
    private function notifyUser($telegramUserId, $craftedItem, $characterId, int $quantity)
    {
        // Находим Telegram ID
        $telegramUser = $this->telegramUserModel->where('id', $telegramUserId)->first();
        if (!$telegramUser) {
            log_message('error', "TelegramUser not found, ID = $telegramUserId");
            return Request::emptyResponse();
        }

        $telegramId = $telegramUser['telegram_id'];

        // Снова достанем итоговое кол-во предмета:
        $existingLog = $this->craftedItemsLogModel->where([
            'character_id'    => $characterId,
            'crafted_item_id' => $craftedItem['id']
        ])->first();
        $totalQty = $existingLog ? (int)$existingLog['quantity'] : $quantity;

        // Формируем сообщение
        $text = "📌 *Крафт завершён!*\n\n"
            . "Ты создал: *" . $craftedItem['name_rus'] . " x{$quantity}* шт.\n\n"
            . "Теперь у тебя в наличии: *{$totalQty}* шт.\n\n"
            . "Зона применения: *Производство* 🏭";

        // Кнопки
        $keyboard = [
            'inline_keyboard' => [
                [
                    // Колбек можно "расщепить" на количество, если нужно
                    ['text' => '🔄 Крафтить еще', 'callback_data' => 'craftStoneBlocks'],
                    ['text' => '🎒 Инвентарь',    'callback_data' => 'inventory'],
                ]
            ]
        ];

        $imagePath = base_url('uploads/telegram/craft/components/craftStoneBlocks.jpg');

        // Отвечаем на callbackQuery (на всякий случай)
        Request::answerCallbackQuery(['callback_query_id' => $telegramId]);

        // Отправляем фото
        return Request::sendPhoto([
            'chat_id'      => $telegramId,
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
