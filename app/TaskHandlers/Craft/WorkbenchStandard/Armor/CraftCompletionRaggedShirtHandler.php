<?php

namespace App\TaskHandlers\Craft\WorkbenchStandard\Armor;

use App\Attributes\HandlerKey;
use App\Models\CharacterModel;
use App\Models\CharactersOutfitsModel;
use App\Models\CharacterTaskModel;
use App\Models\OutfitModel;
use App\Models\TelegramUserModel;
use App\TaskHandlers\BaseTaskHandler;

/**
 * Класс, завершающий крафт "Рваной рубахи" (RaggedShirt).
 *
 * Шаги:
 *  1) Закрываем задачу (character_tasks).
 *  2) Ищем предмет "RaggedShirt" (name_en='RaggedShirt') в outfits.
 *  3) Извлекаем кол-во (quantity) из task_settings.
 *  4) Обновляем/создаём запись в таблице characters_outfits (увеличиваем quantity).
 *  5) Даём персонажу +0.03 ловкости / +0.01 интеллекта.
 *  6) Уведомляем пользователя в Telegram.
 *
 * v0.51.40 (F2.9 batch-5): extends BaseTaskHandler. PSR-4 namespace casing fix
 * `app\` → `App\`. Drop manual Telegram init у constructor + Request::sendPhoto
 * try/catch wrap → safeSendPhoto. handle(array $task) → handle(array $task = []): void.
 */
#[HandlerKey(
    key: 'craft_armor_ragged_shirt',
    displayName: 'Крафт: Рваная рубаха',
    description: 'Завершение крафта RaggedShirt (outfits table, WorkbenchStandard/Armor).',
)]
class CraftCompletionRaggedShirtHandler extends BaseTaskHandler
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
     * @param array<string,mixed> $task Строка из таблицы character_tasks
     */
    public function handle(array $task = []): void
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

        // 5. Прокачиваем персонажа (+0.03 ловкости, +0.01 интеллекта)
        $cidRaw      = $task['character_id'] ?? null;
        $characterId = is_numeric($cidRaw) ? (int) $cidRaw : 0;
        $this->characterModel->updateAgilityAndIntellect(
            $characterId,
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
    private function updateOrCreateOutfit(int $characterId, int $outfitId, int $qty): void
    {
        $row = $this->charactersOutfitsModel
            ->where('character_id', $characterId)
            ->where('outfit_id', $outfitId)
            ->first();

        if ($row) {
            $newQty = (int)$row['quantity'] + $qty;
            $this->charactersOutfitsModel->update($row['id'], [
                'quantity' => $newQty
            ]);
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

    /**
     * Отправка уведомления пользователю через Telegram.
     */
    private function notifyUser(int $telegramUserId, array $outfit, int $characterId, int $qtyAdded): void
    {
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

        $this->safeSendPhoto(
            $telegramId,
            base_url('uploads/telegram/craft/standard/ragged_shirt.jpg'),
            $text,
            ['parse_mode' => 'Markdown', 'reply_markup' => json_encode($keyboard)]
        );
    }
}
