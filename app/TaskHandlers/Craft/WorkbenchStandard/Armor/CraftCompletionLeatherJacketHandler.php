<?php

namespace App\TaskHandlers\Craft\WorkbenchStandard\Armor;

use App\Models\CharacterModel;
use App\Models\CharactersOutfitsModel;
use App\Models\CharacterTaskModel;
use App\Models\OutfitModel;
use App\Models\TelegramUserModel;
use App\TaskHandlers\BaseTaskHandler;

/**
 * Хендлер, завершающий задачу "craftLeatherJacket".
 *  1) Закрывает задачу (status=completed).
 *  2) Добавляет (или увеличивает) запись в characters_outfits для LeatherJacket.
 *  3) Даёт персонажу прирост (+0.05 ловкости, +0.02 интеллекта).
 *  4) Уведомляет игрока в Telegram.
 *
 * v0.51.40 (F2.9 batch-5): extends BaseTaskHandler + PSR-4 namespace casing fix.
 */
class CraftCompletionLeatherJacketHandler extends BaseTaskHandler
{
    protected $characterModel;
    protected $characterTaskModel;
    protected $outfitModel;
    protected $charactersOutfitsModel;
    protected $telegramUserModel;

    public function __construct()
    {
        $this->characterModel         = new CharacterModel();
        $this->characterTaskModel     = new CharacterTaskModel();
        $this->outfitModel            = new OutfitModel();
        $this->charactersOutfitsModel = new CharactersOutfitsModel();
        $this->telegramUserModel      = new TelegramUserModel();
    }

    /**
     * @param array<string,mixed> $task Строка из character_tasks (status in_work -> completed).
     */
    public function handle(array $task = []): void
    {
        // 1) закрываем задачу
        $this->characterTaskModel->update($task['id'], ['status' => 'completed']);

        // 2) ищем LeatherJacket в таблице outfits
        $outfit = $this->outfitModel->where('name_en', 'LeatherJacket')->first();
        if (!$outfit) {
            log_message('error', 'Outfit "LeatherJacket" not found in table outfits.');
            return;
        }

        // 3) узнаём, сколько штук крафтилось (quantity) из task_settings
        $quantity = $this->getQuantity($task);

        // 4) добавляем / обновляем в characters_outfits
        $this->updateOrCreateOutfit($task['character_id'], $outfit['id'], $quantity);

        // 5) даём персонажу +0.05 ловкости, +0.02 интеллекта
        $this->characterModel->updateAgilityAndIntellect($task['character_id'], 0.05, 0.02);

        // 6) уведомляем игрока в Telegram
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

    private function updateOrCreateOutfit(int $characterId, int $outfitId, int $qty): void
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
                'current_durability' => 100,
                'equipped'           => 0,
                'slot'               => 'body',
            ]);
        }
    }

    private function notifyUser(int $telegramUserId, array $outfit, int $characterId, int $qtyAdded): void
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

        $row = $this->charactersOutfitsModel
            ->where('character_id', $characterId)
            ->where('outfit_id', $outfit['id'])
            ->first();
        $total = $row ? (int)$row['quantity'] : $qtyAdded;

        $nameRus = $outfit['name'] ?? 'Кожаная куртка';
        $text = "🎉 *Крафт завершён!* \n\n"
            . "Ты создал: 🧥 *{$nameRus}* x{$qtyAdded}\n\n"
            . "Теперь у тебя *{$total}* шт. этого предмета.\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory']
                ]
            ]
        ];

        $this->safeSendPhoto(
            $telegramId,
            base_url('uploads/telegram/craft/standard/leather_jacket.jpg'),
            $text,
            ['parse_mode' => 'Markdown', 'reply_markup' => json_encode($keyboard)]
        );
    }
}
