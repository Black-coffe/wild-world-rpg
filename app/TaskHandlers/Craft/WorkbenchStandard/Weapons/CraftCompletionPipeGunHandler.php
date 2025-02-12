<?php

namespace App\TaskHandlers\Craft\WorkbenchStandard\Weapons;

use App\Models\CharacterModel;
use App\Models\CharactersWeaponsModel;
use App\Models\CharacterTaskModel;
use App\Models\WeaponModel;
use App\Models\TelegramUserModel;
use CodeIgniter\Controller;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

/**
 * Хендлер, завершающий задачу "craftPipeGun" (из character_tasks).
 *  1) Меняет status задачи на completed.
 *  2) Добавляет (или увеличивает) оружие "PipeGun" в characters_weapons.
 *  3) Даёт персонажу небольшой бонус к статам (пример: +0.03 силы, +0.01 ловкости).
 *  4) Уведомляет игрока в Telegram.
 */
class CraftCompletionPipeGunHandler extends Controller
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
     * Завершает задачу крафта (in_work -> completed),
     * добавляет оружие, даёт бонус к статам, уведомляет игрока.
     *
     * @param array $task строка из character_tasks (end_time <= now).
     */
    public function handle(array $task)
    {
        // 1) Закрываем задачу (status -> completed)
        $this->characterTaskModel->update($task['id'], ['status' => 'completed']);

        // 2) Ищем "PipeGun" в таблице weapons (по name_en)
        $weapon = $this->weaponModel->where('name_en', 'PipeGun')->first();
        if (!$weapon) {
            log_message('error', 'Weapon "PipeGun" not found in table weapons.');
            return;
        }

        // 3) Сколько штук крафтилось (quantity) из task_settings
        $quantity = $this->getQuantity($task);

        // 4) Добавить/обновить запись в characters_weapons
        $this->updateOrCreateWeapon($task['character_id'], $weapon['id'], $quantity);

        // 5) Даём персонажу бонус (пример: +0.03 силы, +0.01 ловкости)
        // Убедитесь, что у CharacterModel есть метод updateStrengthAndAgility(...).
        $this->characterModel->updateStrengthAndAgility($task['character_id'], 0.03, 0.01);

        // 6) Уведомляем игрока
        $this->notifyUser($task['telegram_user_id'], $weapon, $task['character_id'], $quantity);
    }

    /**
     * Получаем quantity из task_settings (JSON).
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
     * Обновить (или создать) запись в таблице characters_weapons.
     */
    private function updateOrCreateWeapon(int $characterId, int $weaponId, int $qty)
    {
        $row = $this->charactersWeaponsModel
            ->where('character_id', $characterId)
            ->where('weapon_id', $weaponId)
            ->first();

        if ($row) {
            // Если запись уже есть — увеличиваем quantity
            $newQty = (int)$row['quantity'] + $qty;
            $this->charactersWeaponsModel->update($row['id'], ['quantity' => $newQty]);
        } else {
            // Иначе создаём новую строчку
            $this->charactersWeaponsModel->insert([
                'character_id'      => $characterId,
                'weapon_id'         => $weaponId,
                'quantity'          => $qty,
                'current_durability'=> 100,
                'equipped'          => 0,
                'slot'              => 'hand', // пример: слот "hand"
            ]);
        }
    }

    /**
     * Уведомляем игрока в Telegram (фото + текст).
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

        // Сколько всего оружия сейчас
        $row = $this->charactersWeaponsModel
            ->where('character_id', $characterId)
            ->where('weapon_id', $weapon['id'])
            ->first();
        $total = $row ? (int)$row['quantity'] : $qtyAdded;

        $nameRus = $weapon['name'] ?? 'Трубчатый пистолет';
        $text = "🎉 *Крафт завершён!*\n\n"
            . "Ты создал(а): 🔫 *{$nameRus}* x{$qtyAdded}\n\n"
            . "Теперь у тебя *{$total}* шт. этого оружия.\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory']
                ]
            ]
        ];

        // Путь к картинке "pipe_gun.jpg" (замените на актуальную)
        $imagePath = base_url('uploads/telegram/craft/standard/pipe_gun.jpg');

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
