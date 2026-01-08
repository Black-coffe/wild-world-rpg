<?php

namespace app\TaskHandlers\Craft\WorkbenchStandard;

use App\Models\CharacterModel;
use App\Models\CharacterTaskModel;
use App\Models\TelegramUserModel;
use App\Models\OutfitModel;            // <-- Чтобы взять RaggedShirt из outfits
use App\Models\CharactersOutfitsModel; // <-- Индивидуальные записи амуниции
use CodeIgniter\Controller;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

/**
 * Класс, завершающий крафт "Рваной рубахи" (RaggedShirt).
 *
 * Шаги:
 *  1) Закрываем задачу (character_tasks).
 *  2) Ищем предмет "RaggedShirt" (name_en='RaggedShirt') в outfits.
 *  3) Извлекаем кол-во (quantity) из task_settings.
 *  4) Обновляем/создаём запись в таблице characters_outfits (увеличиваем quantity).
 *  5) Даём персонажу +0.03 ловкости / +0.01 интеллекта (пример).
 *  6) Уведомляем пользователя в Telegram, показывая сколько всего у него таких рубах.
 */
class CraftCompletionRaggedShirtHandler extends Controller
{
    protected $characterModel;
    protected $characterTaskModel;
    protected $outfitModel;
    protected $charactersOutfitsModel;
    protected $telegramUserModel;

    /** @var Telegram|null $telegram */
    private $telegram;

    public function __construct()
    {
        // Инициализация моделей
        $this->characterModel         = new CharacterModel();
        $this->characterTaskModel     = new CharacterTaskModel();
        $this->outfitModel            = new OutfitModel();
        $this->charactersOutfitsModel = new CharactersOutfitsModel();
        $this->telegramUserModel      = new TelegramUserModel();

        // Настройка Telegram SDK
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
     * Основной метод обработки завершения крафта.
     * @param array $task Строка из таблицы character_tasks
     */
    public function handle(array $task)
    {
        // 1. Закрываем задачу
        $this->characterTaskModel->update($task['id'], ['status' => 'completed']);

        // 2. Ищем "RaggedShirt" в outfits (по name_en='RaggedShirt')
        $outfit = $this->outfitModel->where('name_en', 'RaggedShirt')->first();
        if (!$outfit) {
            log_message('error', 'Outfit "RaggedShirt" not found in table "outfits".');
            return;
        }

        // 3. Извлекаем количество из task_settings
        $quantityToAdd = $this->getQuantityFromTaskSettings($task);

        // 4. Обновляем/создаём запись в characters_outfits
        $this->updateOrCreateOutfit($task['character_id'], $outfit['id'], $quantityToAdd);

        // 5. Прокачиваем персонажа (пример: +0.03 ловкости, +0.01 интеллекта)
        $this->characterModel->updateAgilityAndIntellect(
            $task['character_id'],
            0.03,
            0.01
        );

        // 6. Уведомляем пользователя
        $this->notifyUser($task['telegram_user_id'], $outfit, $task['character_id'], $quantityToAdd);
    }

    /**
     * Извлекаем 'quantity' из JSON-поля task_settings.
     * По умолчанию — 1.
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
     * Создаём или увеличиваем запись о "RaggedShirt" в таблице characters_outfits.
     */
    private function updateOrCreateOutfit(int $characterId, int $outfitId, int $qty)
    {
        // Ищем запись, где character_id + outfit_id
        $row = $this->charactersOutfitsModel
            ->where('character_id', $characterId)
            ->where('outfit_id', $outfitId)
            ->first();

        if ($row) {
            // Если уже есть - увеличиваем quantity
            $newQty = (int)$row['quantity'] + $qty;
            // current_durability можно выставлять по желанию (например, max 100)
            $this->charactersOutfitsModel->update($row['id'], [
                'quantity' => $newQty
            ]);
        } else {
            // Создаём новую запись
            $this->charactersOutfitsModel->insert([
                'character_id'       => $characterId,
                'outfit_id'          => $outfitId,
                'quantity'           => $qty,
                // Допустим, при создании ставим current_durability = 100 (полная прочность)
                'current_durability' => 100,
                'equipped'           => 0,
                'slot'               => 'body',
            ]);
        }
    }

    /**
     * Отправка уведомления пользователю через Telegram.
     *
     * Показываем, сколько добавлено рубах и сколько всего у него есть теперь.
     */
    private function notifyUser(int $telegramUserId, array $outfit, int $characterId, int $qtyAdded)
    {
        // Находим telegram_id
        $tgUserRow = $this->telegramUserModel->find($telegramUserId);
        if (!$tgUserRow) {
            log_message('error', "TelegramUser with ID=$telegramUserId not found.");
            return;
        }
        $telegramId = $tgUserRow['telegram_id'] ?? null;
        if (!$telegramId) {
            log_message('error', "No telegram_id for user row ID=$telegramUserId.");
            return;
        }

        // Сколько теперь всего у персонажа данного outfit_id
        $row = $this->charactersOutfitsModel
            ->where('character_id', $characterId)
            ->where('outfit_id', $outfit['id'])
            ->first();
        $total = $row ? (int)$row['quantity'] : $qtyAdded;

        $itemNameRus = $outfit['name'] ?? 'Рваная рубаха';
        $text = "🎉 *Крафт завершён!*\n\n"
            . "Ты создал: 👕 *{$itemNameRus}* x{$qtyAdded} шт.\n\n"
            . "Теперь у тебя *{$total} шт.* этого предмета.\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔄 Крафтить еще', 'callback_data' => 'startCraftRaggedShirt2'],
                    ['text' => '🎒 Инвентарь',     'callback_data' => 'inventory'],
                ],
            ]
        ];
        // Путь к картинке рубашки (меняется на ваш)
        $imagePath = base_url('uploads/telegram/craft/standard/ragged_shirt.jpg');

        // Можно попробовать ответить на callbackQuery (но у нас нет callback_query_id).
        // Если нужно, передавайте callback_query_id. Иначе просто шлём сообщение.

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
            Request::sendMessage([
                'chat_id' => $telegramId,
                'text'    => "Ошибка отправки сообщения: " . $e->getMessage(),
            ]);
        }
    }
}
