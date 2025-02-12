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
 * Хендлер, завершающий задачу "craftWiredBat" (из character_tasks).
 *  1) Меняет статус задачи на 'completed'.
 *  2) Добавляет/увеличивает оружие "WiredBat" в characters_weapons.
 *  3) Даёт персонажу небольшой бонус к статам (пример: +0.04 силы, +0.01 ловкости).
 *  4) Уведомляет игрока по Telegram.
 */
class CraftCompletionWiredBatHandler extends Controller
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
            // Подставьте свои переменные окружения:
            $API_KEY      = getenv('telegram.API_KEY');
            $BOT_USERNAME = getenv('telegram.BOT_USERNAME');

            $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
            Request::initialize($this->telegram);
        } catch (TelegramException $e) {
            log_message('error', $e->getMessage());
        }
    }

    /**
     * Основной метод, который вызывается кроном/воркером при завершении задачи.
     * @param array $task - строка из character_tasks (end_time <= now).
     */
    public function handle(array $task)
    {
        // 1) Завершаем задачу (status -> completed)
        $this->characterTaskModel->update($task['id'], ['status' => 'completed']);

        // 2) Ищем оружие "WiredBat" (или "EnhancedBat") в таблице weapons
        $weapon = $this->weaponModel->where('name_en', 'EnhancedBat')->first();
        if (!$weapon) {
            log_message('error', 'Weapon "EnhancedBat" not found in table weapons.');
            return;
        }

        // 3) Сколько штук крафтилось?
        $quantity = $this->getQuantity($task);

        // 4) Добавляем/обновляем запись в characters_weapons
        $this->updateOrCreateWeapon($task['character_id'], $weapon['id'], $quantity);

        // 5) Даём небольшой бонус к статам (пример: +0.04 силы, +0.01 ловкости)
        $this->characterModel->updateStrengthAndAgility($task['character_id'], 0.04, 0.01);

        // 6) Шлём уведомление по Telegram
        $this->notifyUser($task['telegram_user_id'], $weapon, $task['character_id'], $quantity);
    }

    /**
     * Извлекаем кол-во из task_settings['quantity'].
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
     * Запись (или обновление) в characters_weapons.
     */
    private function updateOrCreateWeapon(int $characterId, int $weaponId, int $qty)
    {
        // Проверяем, есть ли уже запись
        $row = $this->charactersWeaponsModel
            ->where('character_id', $characterId)
            ->where('weapon_id', $weaponId)
            ->first();

        if ($row) {
            // Увеличиваем quantity
            $newQty = (int)$row['quantity'] + $qty;
            $this->charactersWeaponsModel->update($row['id'], ['quantity' => $newQty]);
        } else {
            // Создаём новую
            $this->charactersWeaponsModel->insert([
                'character_id'      => $characterId,
                'weapon_id'         => $weaponId,
                'quantity'          => $qty,
                'current_durability'=> 100,
                'equipped'          => 0,
                'slot'              => 'hand',
            ]);
        }
    }

    /**
     * Отправляем сообщение игроку о завершении крафта.
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

        // Сколько всего теперь у игрока
        $row = $this->charactersWeaponsModel
            ->where('character_id', $characterId)
            ->where('weapon_id', $weapon['id'])
            ->first();
        $total = $row ? (int)$row['quantity'] : $qtyAdded;

        $nameRus = $weapon['name'] ?? 'Усовершенствованная бита';
        $text = "🎉 *Крафт завершён!*\n\n"
            . "Ты создал(а): 🏏 *{$nameRus}* x{$qtyAdded}\n\n"
            . "Теперь у тебя *{$total}* шт. этого оружия.\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory']
                ]
            ]
        ];

        // Подставьте свою картинку для «Усовершенствованной биты»
        $imagePath = base_url('uploads/telegram/craft/standard/wired_bat.jpg');

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
            // На крайний случай отправим простое текстовое сообщение
            Request::sendMessage([
                'chat_id' => $telegramId,
                'text'    => "Ошибка при отправке уведомления: " . $e->getMessage(),
            ]);
        }
    }
}
