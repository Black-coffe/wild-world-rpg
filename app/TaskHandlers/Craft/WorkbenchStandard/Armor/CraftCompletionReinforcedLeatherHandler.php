<?php

namespace app\TaskHandlers\Craft\WorkbenchStandard\Armor;

use App\Models\CharacterModel;
use App\Models\CharactersOutfitsModel;
use App\Models\CharacterTaskModel;
use App\Models\OutfitModel;
use App\Models\TelegramUserModel;
use CodeIgniter\Controller;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

/**
 * Хендлер, завершающий задачу "craftReinforcedLeatherJacket".
 *  1) Закрывает задачу (status=completed).
 *  2) Добавляет (или увеличивает) запись в characters_outfits для ReinforcedLeatherJacket.
 *  3) Даёт персонажу прирост некоторых характеристик (пример: +0.07 ловкости, +0.03 интеллекта).
 *  4) Уведомляет игрока в Telegram.
 */
class CraftCompletionReinforcedLeatherHandler extends Controller
{
    protected $characterModel;
    protected $characterTaskModel;
    protected $outfitModel;
    protected $charactersOutfitsModel;
    protected $telegramUserModel;

    private $telegram;

    public function __construct()
    {
        $this->characterModel         = new CharacterModel();
        $this->characterTaskModel     = new CharacterTaskModel();
        $this->outfitModel            = new OutfitModel();
        $this->charactersOutfitsModel = new CharactersOutfitsModel();
        $this->telegramUserModel      = new TelegramUserModel();

        try {
            $API_KEY      = getenv('telegram.API_KEY');
            $BOT_USERNAME = getenv('telegram.BOT_USERNAME');
            $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
            Request::initialize($this->telegram);
        } catch (TelegramException $e) {
            log_message('error', $e->getMessage());
        }
    }

    /**
     * @param array $task Строка из character_tasks (c in_work -> completed).
     */
    public function handle(array $task)
    {
        // 1) Закрываем задачу (меняем status -> completed)
        $this->characterTaskModel->update($task['id'], ['status' => 'completed']);

        // 2) Ищем ReinforcedLeatherJacket в таблице outfits (по name_en)
        $outfit = $this->outfitModel->where('name_en', 'ReinforcedLeatherJacket')->first();
        if (!$outfit) {
            log_message('error', 'Outfit "ReinforcedLeatherJacket" not found in table outfits.');
            return;
        }

        // 3) Количество скрафченных штук (quantity) из task_settings
        $quantity = $this->getQuantity($task);

        // 4) Обновляем (или создаём) запись в characters_outfits
        $this->updateOrCreateOutfit($task['character_id'], $outfit['id'], $quantity);

        // 5) Даём персонажу бонус (пример: +0.07 ловкости и +0.03 интеллекта)
        $this->characterModel->updateAgilityAndIntellect($task['character_id'], 0.07, 0.03);

        // 6) Уведомляем игрока в Telegram
        $this->notifyUser($task['telegram_user_id'], $outfit, $task['character_id'], $quantity);
    }

    /**
     * Достаём quantity из task_settings (JSON).
     */
    private function getQuantity(array $task): int
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
     * Добавить (или увеличить) предмет в characters_outfits.
     */
    private function updateOrCreateOutfit(int $characterId, int $outfitId, int $qty)
    {
        $row = $this->charactersOutfitsModel
            ->where('character_id', $characterId)
            ->where('outfit_id', $outfitId)
            ->first();

        if ($row) {
            // Если у персонажа уже есть такая броня, просто увеличиваем кол-во
            $newQty = (int)$row['quantity'] + $qty;
            $this->charactersOutfitsModel->update($row['id'], ['quantity' => $newQty]);
        } else {
            // Вставляем новую строку
            $this->charactersOutfitsModel->insert([
                'character_id'       => $characterId,
                'outfit_id'          => $outfitId,
                'quantity'           => $qty,
                'current_durability' => 100,
                'equipped'           => 0,
                'slot'               => 'body',
            ]);
        }
    }

    /**
     * Уведомляем игрока, что крафт завершён (Telegram).
     */
    private function notifyUser(int $telegramUserId, array $outfit, int $characterId, int $qtyAdded)
    {
        $tgUser = $this->telegramUserModel->find($telegramUserId);
        if (!$tgUser) {
            log_message('error', "No telegram user row #$telegramUserId found.");
            return;
        }

        $telegramId = $tgUser['telegram_id'] ?? null;
        if (!$telegramId) {
            log_message('error', "No telegram_id for user row #$telegramUserId");
            return;
        }

        // Сколько теперь всего у игрока?
        $row = $this->charactersOutfitsModel
            ->where('character_id', $characterId)
            ->where('outfit_id', $outfit['id'])
            ->first();
        $total = $row ? (int)$row['quantity'] : $qtyAdded;

        $nameRus = $outfit['name'] ?? 'Усиленная кожаная куртка';
        $text = "🎉 *Крафт завершён!*\n\n"
            . "Ты создал(а): 🪡 *{$nameRus}* x{$qtyAdded}\n\n"
            . "Теперь у тебя *{$total}* шт. этого предмета.\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory']
                ]
            ]
        ];

        // Путь к картинке «Усиленная кожаная куртка» (поставьте своё)
        $imagePath = base_url('uploads/telegram/craft/standard/reinforced_leather_jacket.jpg');

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
                'text'    => "Ошибка отправки уведомления: " . $e->getMessage(),
            ]);
        }
    }
}
