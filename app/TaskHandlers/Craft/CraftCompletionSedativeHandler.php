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

class CraftCompletionSedativeHandler extends Controller
{
    protected $characterModel;
    protected $characterTaskModel;
    protected $craftedItemsModel;
    protected $craftedItemsLogModel;
    protected $telegramUserModel;
    private $telegram;

    public function __construct()
    {
        $this->characterModel         = new CharacterModel();
        $this->characterTaskModel     = new CharacterTaskModel();
        $this->craftedItemsModel      = new CraftedItemsModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();
        $this->telegramUserModel      = new TelegramUserModel();

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
     * Метод, который вызывается при завершении задачи крафта (через CRON или планировщик).
     *
     * @param array $task Запись из таблицы character_tasks, в т.ч. с task_settings.
     */
    public function handle($task)
    {
        // 1. Закрываем задачу
        $this->characterTaskModel->update($task['id'], ['status' => 'completed']);

        // 2. Ищем предмет "Sedative" (англ. name_eng='Sedative')
        $craftedItem = $this->craftedItemsModel->where('name_eng', 'Sedative')->first();
        if (!$craftedItem) {
            log_message('error', 'Crafted item "Sedative" not found in the database.');
            return;
        }

        // 3. Извлекаем кол-во из task_settings (если пусто или ошибка — вернём 1)
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
            // Если предмета не было, создаём запись
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

        // 5. Обновляем атрибуты персонажа (пример: +0.05 к ловкости/интеллекту)
        $this->characterModel->updateAgilityAndIntellect(
            $task['character_id'],
            0.03,
            0.01
        );

        // 6. Уведомляем пользователя
        $this->notifyUser($task['telegram_user_id'], $craftedItem, $task['character_id'], $quantityToAdd);
    }

    /**
     * Парсим из task_settings кол-во ("quantity").
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
     * Уведомляем пользователя о завершении крафта:
     * - сколько добавлено
     * - сколько всего в наличии
     */
    private function notifyUser(int $telegramUserId, array $craftedItem, int $characterId, int $quantityAdded)
    {
        // Получаем telegram_id
        $userRow = $this->telegramUserModel->where('id', $telegramUserId)->first();
        if (!$userRow) {
            log_message('error', "Telegram user with ID {$telegramUserId} not found.");
            return;
        }
        $telegramId = $userRow['telegram_id'] ?? null;

        if (!$telegramId) {
            log_message('error', "No telegram_id found for user ID {$telegramUserId}.");
            return;
        }

        // Сколько теперь всего у персонажа
        $updatedLog = $this->craftedItemsLogModel->where([
            'character_id'    => $characterId,
            'crafted_item_id' => $craftedItem['id']
        ])->first();
        $totalNow = $updatedLog ? (int)$updatedLog['quantity'] : 0;

        // Русское название (если есть) или задаём дефолт
        $itemNameRus = $craftedItem['name_rus'] ?? "Успокоительное";

        $text = "📌 *Крафт завершён!*\n\n"
            . "Ты создал: 🫖 *{$itemNameRus}* x{$quantityAdded} шт.\n\n"
            . "Теперь у тебя *{$totalNow} шт.* в инвентаре.\n\n"
            . "Зона применения: *медицина* 💊";

        // Кнопка "Крафтить ещё 1"
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔄 Крафтить еще', 'callback_data' => 'craftSedative_1'],
                    ['text' => '🎒 Инвентарь',    'callback_data' => 'inventory'],
                ]
            ]
        ];
        $imagePath = base_url('uploads/telegram/craft/dry_herb_tea.jpg');

        // Пытаемся ответить на callbackQuery
        try {
            Request::answerCallbackQuery(['callback_query_id' => $telegramId]);
        } catch (TelegramException $e) {
            log_message('error', "answerCallbackQuery error: " . $e->getMessage());
        }

        // Отправляем сообщение
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
