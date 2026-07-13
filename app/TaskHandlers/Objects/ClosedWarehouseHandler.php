<?php

namespace App\TaskHandlers\Objects;

use App\Attributes\HandlerKey;
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
 *
 * v0.51.39 (F2.9 batch-4): extends BaseObjectHandler. Раніше manual Telegram
 * init у constructor + 2× broken `Request::answerCallbackQuery(['callback_query_id' => ''])`
 * (empty string — invalid argument завжди, fires every discovery silently).
 * Markdown parse_mode передається через extra щоб не override на 'HTML' у safeSendPhoto.
 */
#[HandlerKey(
    key: 'world_object_closed_warehouse',
    displayName: 'World-object: Закрытый склад',
    description: 'Discovery handler для world_objects.name_en="Closed warehouse". Discovery: проверяет инструменты, формирует кнопки «Взломать/Пройти мимо» (вскрытие — в ObjectCloseWarehouseAction).',
)]
class ClosedWarehouseHandler extends BaseObjectHandler implements ObjectHandlerInterface
{
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
    }

    /**
     * Главный метод, вызывается при обнаружении склада.
     */
    public function handle($object, $cell, $character)
    {
        // 1) Парсим инструменты из JSON
        $requiredTools = json_decode($object['discovery_tools'], true);

        // 2) Немного прокачиваем персонажа — атомарный relative-UPDATE от свежих
        //    значений (CharacterStatsService, fix lost-update 2026-07-13).
        (new \App\Services\Player\CharacterStatsService())->adjust((int) $character['id'], [
            'experience' => 0.25,
            'strength'   => 0.11,
            'agility'    => 0.01,
            'intellect'  => 0.16,
        ]);

        // 3) Проверяем наличие инструментов (только чтобы показать игроку "Можешь взломать" или "Нет")
        //    Если нет — выводим InsufficientTools и прерываем.
        if (!empty($requiredTools[0])) {
            $rawCharId = $character['id'] ?? null;
            $charId    = is_numeric($rawCharId) ? (int) $rawCharId : 0;
            foreach ($requiredTools[0] as $itemName => $quantity) {
                $item = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($itemName, $charId);
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
    private function sendActionMessage($object, $character): void
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

        // Формируем callback_data для экшена "ObjectCloseWarehouseAction".
        // Раніше було `$object['map_id'] ?? $cell['map_id'] ?? 0` — latent bug: $cell
        // не передавався у sendActionMessage, тому $cell['map_id'] викликав би TypeError
        // на null. Спрощено до прямого lookup в $object.
        $objId    = $object['world_object_id'] ?? $object['id'];
        $mapId    = $object['map_id'] ?? 0;
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

        $this->safeSendPhoto(
            $chatId,
            base_url('uploads/telegram/objects/an-old-long-abandoned-warehouse.jpg'),
            $messageText,
            ['parse_mode' => 'Markdown', 'reply_markup' => json_encode($keyboard)]
        );
    }

    /**
     * Если инструментов недостаточно, говорим пользователю, что склад "закрыт" и чего не хватает.
     */
    private function sendInsufficientToolsMessage($character, array $requiredTools): void
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

        $this->safeSendPhoto(
            $chatId,
            base_url('uploads/telegram/objects/an-old-long-abandoned-warehouse.jpg'),
            $messageText,
            ['parse_mode' => 'Markdown', 'reply_markup' => json_encode($keyboard)]
        );
    }
}
