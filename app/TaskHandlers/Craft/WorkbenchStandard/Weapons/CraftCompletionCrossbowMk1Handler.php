<?php

namespace App\TaskHandlers\Craft\WorkbenchStandard\Weapons;

use App\Models\CharacterModel;
use App\Models\CharactersWeaponsModel;  // <-- ваша модель для хранения оружия у персонажа
use App\Models\CharacterTaskModel;
use App\Models\WeaponModel;
use App\Models\TelegramUserModel;
use CodeIgniter\Controller;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

/**
 * Хендлер для завершения задачи "craftCrossbowMk1".
 *  1) Меняем статус задачи на 'completed'.
 *  2) Добавляем/увеличиваем запись "CrossbowMk1" в characters_weapons.
 *  3) Даём небольшой бонус к статам (пример: +0.02 ловкости, +0.02 силы).
 *  4) Отправляем уведомление игроку в Telegram.
 */
class CraftCompletionCrossbowMk1Handler extends Controller
{
    protected $characterModel;
    protected $characterTaskModel;
    protected $weaponModel;
    protected $charactersWeaponsModel;
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
     * Вызывается кроном/воркером, когда end_time <= now и task.name='craftCrossbowMk1'.
     * @param array $task - запись из character_tasks.
     */
    public function handle(array $task)
    {
        // 1) Меняем статус in_work -> completed
        $this->characterTaskModel->update($task['id'], ['status' => 'completed']);

        // 2) Ищем "CrossbowMk1" в weapons
        $weapon = $this->weaponModel->where('name_en', 'CrossbowMk1')->first();
        if (!$weapon) {
            log_message('error', 'Weapon "CrossbowMk1" not found in weapons.');
            return;
        }

        // 3) Сколько скрафтили?
        $quantity = $this->getQuantity($task);

        // 4) Добавляем/увеличиваем в characters_weapons
        $this->updateOrCreateWeapon($task['character_id'], $weapon['id'], $quantity);

        // 5) Даём бонус к статам (пример: +0.02 ловкости, +0.02 силы)
        // Убедитесь, что в CharacterModel есть метод updateStrengthAndAgility(...)
        $this->characterModel->updateStrengthAndAgility($task['character_id'], 0.02, 0.02);

        // 6) Уведомляем игрока
        $this->notifyUser($task['telegram_user_id'], $weapon, $task['character_id'], $quantity);
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
                'slot'              => 'twohand', // например, арбалет занимает "двуручный" слот
            ]);
        }
    }

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

        // Определяем, сколько всего у игрока
        $row = $this->charactersWeaponsModel
            ->where('character_id', $characterId)
            ->where('weapon_id', $weapon['id'])
            ->first();
        $total = $row ? (int)$row['quantity'] : $qtyAdded;

        $nameRus = $weapon['name'] ?? 'Арбалет Mk.I';
        $text = "🎉 *Крафт завершён!*\n\n"
            . "Ты создал(а): 🏹 *{$nameRus}* x{$qtyAdded}\n\n"
            . "Теперь у тебя *{$total}* шт. этого оружия.\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory']
                ]
            ]
        ];

        // Картинка
        $imagePath = base_url('uploads/telegram/craft/standard/crossbow_mk1.jpg');
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
