<?php

namespace App\TaskHandlers\Craft\WorkbenchStandard\Weapons;

use App\Models\CharacterModel;
use App\Models\CharactersWeaponsModel;   // <-- Вместо CharactersOutfitsModel
use App\Models\CharacterTaskModel;
use App\Models\WeaponModel;             // <-- Вместо OutfitModel
use App\Models\TelegramUserModel;
use CodeIgniter\Controller;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

/**
 * Хендлер, завершающий задачу "craftMetalSpear" (из character_tasks).
 *  1) Меняет status задачи на completed.
 *  2) Добавляет (или увеличивает) оружие в characters_weapons.
 *  3) Даёт персонажу небольшой бонус к статам.
 *  4) Уведомляет игрока по Telegram.
 */
class CraftCompletionMetalSpearHandler extends Controller
{
    protected $characterModel;
    protected $characterTaskModel;
    protected $weaponModel;               // Вместо OutfitModel, т.к. это оружие
    protected $charactersWeaponsModel;    // Вместо CharactersOutfitsModel
    protected $telegramUserModel;

    private $telegram;

    public function __construct()
    {
        $this->characterModel         = new CharacterModel();
        $this->characterTaskModel     = new CharacterTaskModel();
        $this->weaponModel            = new WeaponModel();
        $this->charactersWeaponsModel = new CharactersWeaponsModel();
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
     * Главный метод: завершает задачу крафта (in_work -> completed),
     * добавляет оружие в инвентарь и уведомляет игрока.
     *
     * @param array $task строка из character_tasks (в воркере, когда end_time <= now).
     */
    public function handle(array $task)
    {
        // 1) Закрываем задачу (status -> completed)
        $this->characterTaskModel->update($task['id'], ['status' => 'completed']);

        // 2) Ищем оружие "MetalSpear" в таблице weapons (по name_en)
        $weapon = $this->weaponModel->where('name_en', 'MetalSpear')->first();
        if (!$weapon) {
            log_message('error', 'Weapon "MetalSpear" not found in table weapons.');
            return;
        }

        // 3) Узнаём, сколько штук крафтилось (quantity из task_settings)
        $quantity = $this->getQuantity($task);

        // 4) Обновляем или создаём запись в characters_weapons
        $this->updateOrCreateWeapon($task['character_id'], $weapon['id'], $quantity);

        // 5) Даём персонажу бонус (пример: +0.05 силы, +0.01 ловкости)
        // Убедитесь, что у CharacterModel есть метод updateStrengthAndAgility(...)
        $this->characterModel->updateStrengthAndAgility($task['character_id'], 0.05, 0.01);

        // 6) Уведомляем по Telegram
        $this->notifyUser($task['telegram_user_id'], $weapon, $task['character_id'], $quantity);
    }

    /**
     * Распарсить quantity из task_settings.
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
     * Добавить (или увеличить) оружие в таблицу characters_weapons.
     * current_durability=100, equipped=0, slot='hand' (пример).
     */
    private function updateOrCreateWeapon(int $characterId, int $weaponId, int $qty)
    {
        $row = $this->charactersWeaponsModel
            ->where('character_id', $characterId)
            ->where('weapon_id', $weaponId)
            ->first();

        if ($row) {
            $newQty = (int)$row['quantity'] + $qty;
            $this->charactersWeaponsModel->update($row['id'], ['quantity' => $newQty]);
        } else {
            $this->charactersWeaponsModel->insert([
                'character_id'      => $characterId,
                'weapon_id'         => $weaponId,
                'quantity'          => $qty,
                'current_durability'=> 100,
                'equipped'          => 0,
                'slot'              => 'hand', // или любой другой слот
            ]);
        }
    }

    /**
     * Уведомляем игрока (Telegram).
     */
    private function notifyUser(int $telegramUserId, array $weapon, int $characterId, int $qtyAdded)
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
        $row = $this->charactersWeaponsModel
            ->where('character_id', $characterId)
            ->where('weapon_id', $weapon['id'])
            ->first();
        $total = $row ? (int)$row['quantity'] : $qtyAdded;

        // Формируем текст сообщения
        $nameRus = $weapon['name'] ?? 'Металлическое копьё';
        $text = "🎉 *Крафт завершён!* \n\n"
            . "Ты создал(а): 🗡 *{$nameRus}* x{$qtyAdded}\n\n"
            . "Теперь у тебя *{$total}* шт. этого оружия.\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory']
                ]
            ]
        ];

        // Картинка для "Металлическое копьё" (подставьте свою)
        $imagePath = base_url('uploads/telegram/craft/standard/metal_spear.jpg');

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
                'text'    => "Ошибка при отправке уведомления: " . $e->getMessage(),
            ]);
        }
    }
}
