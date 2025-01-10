<?php

namespace App\TaskHandlers\Objects;

use App\TaskHandlers\Objects\ObjectHandlerInterface;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use App\Models\TelegramUserModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\BiomeWorldObjectMapModel;
use App\Models\ResourceModel;
use App\Models\CharacterModel;

class ClosedWarehouseHandler implements ObjectHandlerInterface {

    private $telegram;
    protected $telegramUserModel;
    protected $craftedItemsLogModel;
    protected $craftedItemsModel;
    protected $biomeWorldObjectMapModel;
    protected $resourceModel;
    protected $characterModel;

    public function __construct()
    {
        $this->telegramUserModel = new TelegramUserModel();
        $this->craftedItemsLogModel = new CraftedItemsLogModel();
        $this->craftedItemsModel = new CraftedItemsModel();
        $this->biomeWorldObjectMapModel = new BiomeWorldObjectMapModel();
        $this->resourceModel = new ResourceModel();
        $this->characterModel = new CharacterModel();

        $API_KEY = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');

        try {
            $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
            // Инициализируем объект Telegram в Request
            Request::initialize($this->telegram);
        } catch (TelegramException $e) {
            // Обработка исключений при инициализации бота
            log_message('error', $e->getMessage());
        }
    }
    public function handle($object, $cell, $character) {
        // Декодирование необходимых инструментов
        $requiredTools = json_decode($object['discovery_tools'], true);

        // Обновляем характеристики персонажа
        $this->characterModel->update($character['id'], [
            'experience' => $character['experience'] + 1.25,
            'strength' => $character['strength'] + 1.11,
            'agility' => $character['agility'] + 1.01,
            'intellect' => $character['intellect'] + 0.86,
        ]);

        // Проверка наличия каждого инструмента
        foreach ($requiredTools[0] as $itemName => $quantity) {
            $item = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($itemName, $character['id']);
            if (!$item || $item['quantity'] < $quantity) {
                // Недостаточно инструментов, отправка сообщения и выход
                $this->sendInsufficientToolsMessage($character, $requiredTools[0]);
                return;
            }
        }

        $this->sendActionMessage($object, $character);
    }

    private function sendActionMessage($object, $character) {
        $chatId = $this->telegramUserModel->where('id', $character['telegram_user_id'])->first()['telegram_id'];
        $messageText = "🌲 В процессе *Изучения местности*:\n";
        $messageText .= "🏚️ ты нашел *Старый, заброшенный склад*!\n\n";
        $messageText .= "_У тебя выбор:_\n\n";
        $messageText .= "1️⃣ Взломать склад использовав инструменты\n";
        $messageText .= "2️⃣ Забыть и пройти мимо\n\n";
        $messageText .= "🎓 _Если ты решишь взламывать склад, помни: тебе нужно оставаться на месте, не переезжать и надеяться,что пока ты думаешь или ищешь инструмент, кто-то  другой не сделает это первее тебя!_\n\n";

        $callbackData = "objectActionClosedWarehouse_objectId|" . $object['world_object_id'] . "#objectMapId|" . $object['map_id'];

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🚶🏽 Пройти мимо', 'callback_data' => 'character'],
                    ['text' => '🔗 Взломать склад', 'callback_data' => $callbackData]
                ]
            ]
        ];
        Request::answerCallbackQuery(['callback_query_id' => $chatId]);
        try {
            Request::sendPhoto([
                'chat_id' => $chatId,
                'photo'   => Request::encodeFile(base_url('uploads/telegram/objects/an-old-long-abandoned-warehouse.jpg')),
                'caption' => $messageText,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard)
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Failed to send message: " . $e->getMessage());
        }
    }

    private function sendInsufficientToolsMessage($character, $requiredTools) {
        $chatId = $this->telegramUserModel->where('id', $character['telegram_user_id'])->first()['telegram_id'];

        $messageText = "🌲 В процессе *Изучения местности* ты сделал открытие:.\n\n";
        $messageText .= "🏚️ Нашел старый заброшенный склад, но он закрыт.\n\n";
        $messageText .= "🛠️ _Для проникновения внутрь необходимы следующие инструменты:_\n\n";
        foreach ($requiredTools as $itemName => $quantity) {
            $item = $this->craftedItemsModel->getRowByName($itemName);
            $inStock = $this->craftedItemsLogModel
                ->where('crafted_item_id', $item['id'])
                ->where('character_id', $character['id'])
                ->countAllResults();
            $messageText .= "*{$item['name_rus']}: {$quantity}* _шт._ | _в наличии:_ *{$inStock}*\n";
        }

        $messageText .= "\n❌ К сожалению, их у тебя нет...\n\n";
        $messageText .= "🛒 Оставайся на этой территории, приобрети или скрафти необходимые инструменты, и возвращайся.\n\n";
        $messageText .= "📝 *P.S.* Чтобы повторно вскрыть склад, еще раз запусти *Изучение местности*. _И помни, склад может оказаться пустым, а ресурсы потратишь, или же наоборот сорвешь большой куш. Тебе решать!_";
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                ],
                [
                    ['text' => '🛒 Магазин', 'callback_data' => 'shop'],
                    ['text' => '🛠️ Крафт', 'callback_data' => 'crafting']
                ],
            ]
        ];
        $imagePath = base_url('uploads/telegram/objects/an-old-long-abandoned-warehouse.jpg');

        try {
            Request::answerCallbackQuery(['callback_query_id' => $chatId]);
            return Request::sendPhoto([
                'chat_id' => $chatId,
                'photo'   => Request::encodeFile($imagePath),
                'caption' => $messageText,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Failed to send message: " . $e->getMessage());
        }
    }
}
