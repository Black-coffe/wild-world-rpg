<?php

namespace App\TaskHandlers\Craft\WorkbenchStandard;

use App\Models\CharacterModel;
use App\Models\CharacterTaskModel;
use App\Models\TelegramUserModel;
use App\Models\OutfitModel;
use App\Models\CharactersOutfitsModel;
use CodeIgniter\Controller;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

/**
 * Хендлер, завершающий задачу "craftArmorDrifterClothes".
 *  1) Закрывает задачу (status=completed).
 *  2) Добавляет (увеличивает) запись в characters_outfits для DrifterClothes.
 *  3) Даёт персонажу +0.05 ловкости, +0.02 интеллекта (пример).
 *  4) Уведомляет игрока.
 */
class CraftCompletionDrifterClothesHandler extends Controller
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
     * @param array $task Строка из character_tasks (in_work -> completed).
     */
    public function handle(array $task)
    {
        // 1) закрываем задачу
        $this->characterTaskModel->update($task['id'], ['status' => 'completed']);

        // 2) ищем DrifterClothes в outfits
        $outfit = $this->outfitModel->where('name_en', 'WandererClothes')->first();
        if (!$outfit) {
            log_message('error', 'Outfit "WandererClothes" not found in table outfits.');
            return;
        }

        // 3) узнаём кол-во (quantity) из task_settings
        $quantity = $this->getQuantity($task);

        // 4) увеличиваем запись в characters_outfits
        $this->updateOrCreateOutfit($task['character_id'], $outfit['id'], $quantity);

        // 5) даём персонажу +0.05 ловкости, +0.02 интеллекта (пример)
        $this->characterModel->updateAgilityAndIntellect(
            $task['character_id'],
            0.05,
            0.02
        );

        // 6) уведомляем
        $this->notifyUser($task['telegram_user_id'], $outfit, $task['character_id'], $quantity);
    }

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

    private function updateOrCreateOutfit(int $characterId, int $outfitId, int $qty)
    {
        $row = $this->charactersOutfitsModel
            ->where('character_id', $characterId)
            ->where('outfit_id', $outfitId)
            ->first();

        if ($row) {
            $newQty = (int)$row['quantity'] + $qty;
            $this->charactersOutfitsModel->update($row['id'], ['quantity' => $newQty]);
        } else {
            $this->charactersOutfitsModel->insert([
                'character_id'       => $characterId,
                'outfit_id'          => $outfitId,
                'quantity'           => $qty,
                'current_durability' => 100, // или другое значение
                'equipped'           => 0,
                'slot'               => 'body',
            ]);
        }
    }

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

        // сколько всего теперь у персонажа?
        $row = $this->charactersOutfitsModel
            ->where('character_id', $characterId)
            ->where('outfit_id', $outfit['id'])
            ->first();
        $total = $row ? (int)$row['quantity'] : $qtyAdded;

        $nameRus = $outfit['name'] ?? 'Одежда бродяги';
        $text = "🎉 *Крафт завершён!*\n\n"
            . "Ты создал: 🧥 *{$nameRus}* x{$qtyAdded}\n\n"
            . "Теперь у тебя *{$total}* шт. этого предмета.\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory']
                ]
            ]
        ];

        $imagePath = base_url('uploads/telegram/craft/standard/drifter_clothes.jpg');

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
                'text'    => "Ошибка отправки: " . $e->getMessage(),
            ]);
        }
    }
}
