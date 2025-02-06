<?php

namespace app\TaskHandlers\Craft\WorkbenchStandard;

use App\Models\CharacterModel;
use App\Models\CharacterTaskModel;
use App\Models\CraftedItemsModel;
use App\Models\CraftedItemsLogModel;
use App\Models\TelegramUserModel;
use CodeIgniter\Controller;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

/**
 * Закрытие задачи крафта "Рюкзак телепорт" (TeleportBackpack).
 * Аналогично CraftCompletionTeleportBeaconBasicHandler,
 * но для другого предмета (рюкзак).
 */
class CraftCompletionTeleportBackpackHandler extends Controller
{
    protected $characterModel;
    protected $characterTaskModel;
    protected $craftedItemsModel;
    protected $craftedItemsLogModel;
    protected $telegramUserModel;
    private   $telegram;

    public function __construct()
    {
        $this->characterModel      = new CharacterModel();
        $this->characterTaskModel  = new CharacterTaskModel();
        $this->craftedItemsModel   = new CraftedItemsModel();
        $this->craftedItemsLogModel= new CraftedItemsLogModel();
        $this->telegramUserModel   = new TelegramUserModel();

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
     * Основной метод, вызываемый воркером для закрытия задачи.
     * @param array $task данные строки из character_tasks
     */
    public function handle($task)
    {
        // 1) Ставим задаче статус 'completed'
        $this->characterTaskModel->update($task['id'], ['status' => 'completed']);

        // 2) Проверяем предмет "TeleportBackpack" в crafted_items
        //    Если нет, создаём новую запись
        $craftedItem = $this->craftedItemsModel
            ->where('name_eng', 'TeleportBackpack')
            ->first();

        if (!$craftedItem) {
            // Создаём запись для "Рюкзака телепорта"
            // Подставьте нужные поля:
            $newItemId = $this->craftedItemsModel->insert([
                'name_rus'             => 'Рюкзак телепорт',
                'name_eng'             => 'TeleportBackpack',
                'type'                 => 'teleport',
                'direction_craft'      => 'teleporter',
                'effect'               => 'Позволяет мгновенно вернуться на базу из любой точки',
                'crafting_time'        => 45, // пример
                'crafting_location'    => 'anywhere',
                'durability_count'     => 100,
                'durability_time'      => null,
                'damage'               => 0,
                'armor'                => 0,
                'hp'                   => 150,
                'character_boost'      => null,
                'weight'               => 200,
                'price'                => 90000,
                'stack_size'           => 9999,
                'required_level'       => 1,
                'required_skills'      => null,
                'required_resources'   => null,
                'description'          => 'Позволяет носить спецмодуль для мгновенного возвращения на базу',
                'created_at'           => date('Y-m-d H:i:s'),
                'updated_at'           => date('Y-m-d H:i:s'),
            ]);

            if ($newItemId) {
                $craftedItem = $this->craftedItemsModel->find($newItemId);
            }
            if (!$craftedItem) {
                log_message('error', 'Не удалось создать запись TeleportBackpack в crafted_items.');
                return;
            }
        }

        // 3) Добавляем/увеличиваем запись в crafted_items_log
        $this->updateCraftLog($task, $craftedItem);

        // 4) (опционально) увеличиваем атрибуты персонажа
        // (пример - ловкость/интеллект, при желании замените)
        $this->characterModel->updateAgilityAndIntellect(
            $task['character_id'],
            0.05, // прирост ловкости
            0.05  // прирост интеллекта
        );

        // 5) Отправляем уведомление в Telegram
        $this->notifyUser($task['telegram_user_id'], $craftedItem, $task['character_id']);
    }

    /**
     * Добавление (или инкремент) предмета в crafted_items_log для данного персонажа.
     */
    private function updateCraftLog($task, $craftedItem)
    {
        $existingLog = $this->craftedItemsLogModel->where([
            'character_id'    => $task['character_id'],
            'crafted_item_id' => $craftedItem['id'],
        ])->first();

        if ($existingLog) {
            // Уже есть запись — увеличиваем qty
            $this->craftedItemsLogModel->update($existingLog['id'], [
                'quantity' => $existingLog['quantity'] + 1
            ]);
        } else {
            // Вставляем новую строку
            $this->craftedItemsLogModel->insert([
                'character_id'     => $task['character_id'],
                'task_id'          => $task['task_id'],
                'crafted_item_id'  => $craftedItem['id'],
                'type'             => $craftedItem['type'],
                'direction_craft'  => $craftedItem['direction_craft'],
                'crafting_location'=> $craftedItem['crafting_location'],
                'durability_count' => $craftedItem['durability_count'],
                'durability_time'  => null,
                'quantity'         => 1,
            ]);
        }
    }

    /**
     * Уведомляем пользователя (telegram_user_id) через Telegram API
     */
    private function notifyUser($telegramUserId, $craftedItem, $characterId)
    {
        // 1) Получаем настоящий telegram_id
        $telegramUser = $this->telegramUserModel->find($telegramUserId);
        if (!$telegramUser) {
            log_message('error', "Не найден TelegramUser c ID={$telegramUserId}");
            return;
        }
        $tgId = $telegramUser['telegram_id'];

        // 2) Сколько рюкзаков у игрока теперь
        $existingLog = $this->craftedItemsLogModel->where([
            'character_id'    => $characterId,
            'crafted_item_id' => $craftedItem['id'],
        ])->first();
        $quantity = $existingLog ? $existingLog['quantity'] : 1;

        // 3) Формируем текст сообщения
        $itemNameRus = $craftedItem['name_rus'] ?? 'Рюкзак телепорт';
        $text = "🏭 *Крафт завершён!*\n\n"
            . "Вы создали предмет:\n"
            . "🎒 *{$itemNameRus}*\n\n"
            . "Теперь у вас *{$quantity}* шт.\n"
            . "В любой момент можете активировать рюкзак и вернуться на базу!";

        // 4) Кнопки
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔄 Крафтить ещё', 'callback_data' => 'teleportBackpack2'],
                    ['text' => '🎒 Инвентарь',     'callback_data' => 'inventory'],
                ],
            ]
        ];
        $imagePath = base_url('uploads/telegram/craft/standard/backpack_craft.jpg');

        // 5) Отправляем сообщение с фото
        try {
            return Request::sendPhoto([
                'chat_id'    => $tgId,
                'photo'      => Request::encodeFile($imagePath),
                'caption'    => $text,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Telegram API error: " . $e->getMessage());
            return Request::sendMessage([
                'chat_id' => $tgId,
                'text'    => "Произошла ошибка при отправке сообщения: " . $e->getMessage(),
            ]);
        }
    }
}