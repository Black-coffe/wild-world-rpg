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

/**
 * Обработчик, вызываемый при "первичной" встрече со складом (на этапе "ты нашел склад").
 * Он лишь проверяет инструменты и формирует кнопки:
 * - Пройти мимо
 * - Взломать
 *
 * Само "вскрытие" происходит в другом классе (ObjectCloseWarehouseAction).
 */
class ClosedWarehouseHandler implements ObjectHandlerInterface
{
    private $telegram;
    protected $telegramUserModel;
    protected $craftedItemsLogModel;
    protected $craftedItemsModel;
    protected $biomeWorldObjectMapModel;
    protected $resourceModel;
    protected $characterModel;

    public function __construct()
    {
        $this->telegramUserModel       = new TelegramUserModel();
        $this->craftedItemsLogModel    = new CraftedItemsLogModel();
        $this->craftedItemsModel       = new CraftedItemsModel();
        $this->biomeWorldObjectMapModel= new BiomeWorldObjectMapModel();
        $this->resourceModel           = new ResourceModel();
        $this->characterModel          = new CharacterModel();

        $API_KEY      = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');

        try {
            $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
            // Инициализируем объект Telegram внутри Request
            Request::initialize($this->telegram);
        } catch (TelegramException $e) {
            log_message('error', $e->getMessage());
        }
    }

    /**
     * Главный метод, вызывается при обнаружении склада.
     */
    public function handle($object, $cell, $character)
    {
        // 1) Парсим инструменты из JSON
        $requiredTools = json_decode($object['discovery_tools'], true);

        // 2) Немного прокачиваем персонажа (пример)
        $this->characterModel->update($character['id'], [
            'experience' => $character['experience'] + 0.25,
            'strength'   => $character['strength'] + 0.11,
            'agility'    => $character['agility'] + 0.01,
            'intellect'  => $character['intellect'] + 0.16,
        ]);

        // 3) Проверяем наличие инструментов (только чтобы показать игроку "Можешь взломать" или "Нет")
        //    Если нет — выводим InsufficientTools и прерываем.
        if (!empty($requiredTools[0])) {
            foreach ($requiredTools[0] as $itemName => $quantity) {
                $item = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($itemName, $character['id']);
                if (!$item || $item['quantity'] < $quantity) {
                    $this->sendInsufficientToolsMessage($character, $requiredTools[0]);
                    return;
                }
            }
        }

        // 4) Если инструменты есть → предлагаем выбор "Взломать / Пройти мимо"
        $this->sendActionMessage($object, $character);
    }

    /**
     * Показать сообщение с кнопкой "Взломать склад" либо "Пройти мимо".
     */
    private function sendActionMessage($object, $character)
    {
        $telegramUser = $this->telegramUserModel->find($character['telegram_user_id']);
        if (!$telegramUser) {
            log_message('error', "Can't find telegram user for character ID: {$character['id']}");
            return;
        }
        $chatId = $telegramUser['telegram_id'];

        // Подпись
        $messageText  = "🌲 В процессе *Изучения местности*:\n";
        $messageText .= "🏚️ Ты нашел *Старый, заброшенный склад*!\n\n";
        $messageText .= "_У тебя выбор:_\n\n";
        $messageText .= "1️⃣ Взломать склад (используя инструменты)\n";
        $messageText .= "2️⃣ Забыть и пройти мимо\n\n";
        $messageText .= "🎓 _Важно: нужно оставаться на месте. Если кто-то другой взломает склад раньше, ты можешь остаться без лута!_\n\n";

        // Формируем callback_data для экшена "ObjectCloseWarehouseAction"
        // (ВНИМАНИЕ: проверяем, что в $object['world_object_id'] есть нужные данные)
        $objId    = $object['world_object_id'] ?? $object['id'];
        $mapId    = $object['map_id'] ?? $cell['map_id'] ?? 0; // иногда берут из параметра $cell
        $callback = "objectActionClosedWarehouse_objectId|{$objId}#objectMapId|{$mapId}";

        // Собираем inline-кнопки
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🚶 Пройти мимо',    'callback_data' => 'character'],
                    ['text' => '🔗 Взломать склад', 'callback_data' => $callback]
                ]
            ]
        ];

        // Важно отвечать на колбэк (если есть).
        // Но здесь handle() вызывается без CallbackQuery, значит ответ может быть пустым:
        Request::answerCallbackQuery([
            'callback_query_id' => '', // Или передаем реальный ID, если доступен
            'text'             => '',
            'show_alert'       => false
        ]);

        // Отправляем фото + текст
        try {
            Request::sendPhoto([
                'chat_id'    => $chatId,
                'photo'      => Request::encodeFile(base_url('uploads/telegram/objects/an-old-long-abandoned-warehouse.jpg')),
                'caption'    => $messageText,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard)
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Failed to send warehouse message: " . $e->getMessage());
        }
    }

    /**
     * Если инструментов недостаточно, говорим пользователю, что склад "закрыт" и чего не хватает.
     */
    private function sendInsufficientToolsMessage($character, array $requiredTools)
    {
        $telegramUser = $this->telegramUserModel->find($character['telegram_user_id']);
        if (!$telegramUser) {
            log_message('error', "Can't find telegram user for character ID: {$character['id']} (insufficient tools msg).");
            return;
        }
        $chatId = $telegramUser['telegram_id'];

        $messageText  = "🌲 При исследовании ты обнаружил склад, но он *закрыт*.\n\n";
        $messageText .= "🛠️ _Для проникновения внутрь нужны инструменты:_\n\n";
        foreach ($requiredTools as $itemName => $quantity) {
            $itemRow  = $this->craftedItemsModel->getRowByName($itemName);
            $itemNameRus = $itemRow ? $itemRow['name_rus'] : $itemName;
            $inStock = $this->craftedItemsLogModel
                ->where('crafted_item_id', $itemRow['id'] ?? 0)
                ->where('character_id',     $character['id'])
                ->countAllResults();

            $messageText .= "*{$itemNameRus}: {$quantity} шт.* | _в наличии:_ *{$inStock}*\n";
        }

        $messageText .= "\n❌ К сожалению, пока ты не можешь его вскрыть...\n";
        $messageText .= "🛒 _Попробуй купить/скрафтить инструменты._\n\n";
        $messageText .= "Если решишь вернуться, повторно запусти *Изучение местности*.\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🎒 Инвентарь',  'callback_data' => 'inventory'],
                    ['text' => '🛒 Магазин',    'callback_data' => 'shop'],
                ],
            ]
        ];

        try {
            // Ответ на колбэк (если есть).
            Request::answerCallbackQuery([
                'callback_query_id' => '',
                'text'             => '',
                'show_alert'       => false
            ]);

            Request::sendPhoto([
                'chat_id'    => $chatId,
                'photo'      => Request::encodeFile(base_url('uploads/telegram/objects/an-old-long-abandoned-warehouse.jpg')),
                'caption'    => $messageText,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Failed to send insufficient tools message: " . $e->getMessage());
        }
    }
}
