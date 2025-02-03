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
 * Закрытие задачи крафта "Базового телепорт-маяка".
 * Аналогично CraftCompletionRobot..., но с учётом,
 * что у нас может не быть предмета в 'crafted_items'.
 */
class CraftCompletionTeleportBeaconBasicHandler extends Controller
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

        // 2) Проверяем предмет "TeleportBeaconBasic" в crafted_items
        //    Если нет, создаём новую запись
        $craftedItem = $this->craftedItemsModel
            ->where('name_eng', 'TeleportBeaconBasic')
            ->first();

        if (!$craftedItem) {
            // Создаём запись для "Базового телепорт-маяка"
            // Подставьте нужные поля, например type='Beacon', direction_craft='teleportBeacon', etc.
            $newItemId = $this->craftedItemsModel->insert([
                'name_rus'             => 'Телепорт-маяк (базовый)',
                'name_eng'             => 'TeleportBeaconBasic',
                'type'                 => 'teleport', // или 'teleportBeacon', под свой тип
                'direction_craft'      => 'teleporter', // или что у вас
                'effect'               => 'Позволяет быстро возвращаться в заданные точки',
                'crafting_time'        => 30, // например, 30
                'crafting_location'    => 'anywhere',
                'durability_count'     => 100,   // если нужна "прочность"
                'durability_time'      => null,
                'damage'               => 0,
                'armor'                => 0,
                'hp'                   => 120,
                'character_boost'      => null,
                'weight'               => 180,
                'price'                => 65000,
                'stack_size'           => 9999,
                'required_level'       => 1,
                'required_skills'      => null,
                'required_resources'   => null, // JSON или null
                'description'          => 'Базовый телепорт-маяк, упрощающий перемещение по карте',
                'created_at'           => date('Y-m-d H:i:s'),
                'updated_at'           => date('Y-m-d H:i:s'),
            ]);

            if ($newItemId) {
                $craftedItem = $this->craftedItemsModel->find($newItemId);
            }
            if (!$craftedItem) {
                // Если даже после вставки не нашли — ошибка
                log_message('error', 'Не удалось создать запись TeleportBeaconBasic в crafted_items.');
                return;
            }
        }

        // 3) Добавляем/увеличиваем запись в crafted_items_log
        $this->updateCraftLog($task, $craftedItem);

        // 4) (опционально) увеличиваем атрибуты персонажа (ловкость, интеллект...)
        // Вы можете выбрать другие показатели или не делать ничего
        $this->characterModel->updateAgilityAndIntellect(
            $task['character_id'],
            0.55, // прирост ловкости
            0.55  // прирост интеллекта
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

        // 2) Сколько маяков у игрока теперь
        $existingLog = $this->craftedItemsLogModel->where([
            'character_id'    => $characterId,
            'crafted_item_id' => $craftedItem['id'],
        ])->first();
        $quantity = $existingLog ? $existingLog['quantity'] : 1;

        // 3) Формируем текст сообщения
        $itemNameRus = $craftedItem['name_rus'] ?? 'Телепорт-маяк (базовый)';
        $text = "📌 Вы успешно скрафтили предмет:\n\n"
            . "🌀 *{$itemNameRus}*\n\n"
            . "В наличии: *{$quantity} шт.*\n"
            . "Теперь вы можете устанавливать маяк для быстрого перемещения.\n";

        // 4) Кнопки
        $keyboard = [
            'inline_keyboard' => [
                [
                    // Можно вернуть на "кнопку" крафта
                    ['text' => '🔄 Крафтить ещё', 'callback_data' => 'teleportBeaconBasic2'],
                    ['text' => '🎒 Инвентарь',     'callback_data' => 'inventory'],
                ],
            ]
        ];
        $imagePath = base_url('uploads/telegram/craft/standard/beacon_craft.jpg');
        if (!file_exists($imagePath)) {
            $imagePath = base_url('uploads/telegram/craft/standard/default_beacon.jpg');
        }

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