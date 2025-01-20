<?php

namespace App\Controllers\Telegram\Commands\Actions\Objects;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\BiomeModel;
use App\Models\CharacterModel;
use App\Models\MapModel;
use App\Models\ResourceModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Telegram;

use App\Models\TelegramUserModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\BiomeWorldObjectMapModel;
use App\Models\WorldObjectModel;

/**
 * Action-класс, вызываемый при нажатии "Взломать склад"
 * (callbackData вида objectActionClosedWarehouse_objectId|X#objectMapId|Y).
 * Здесь происходит финальная проверка:
 * - статус объекта (active)
 * - рядом ли персонаж
 * - хватает ли инструментов
 * - шанс пустоты (15%)
 * - выдача лута.
 */
class ObjectCloseWarehouseAction extends BaseAction
{
    private $telegram;
    protected $telegramUserModel;
    protected $craftedItemsLogModel;
    protected $craftedItemsModel;
    protected $biomeWorldObjectMapModel;
    protected $resourceModel;
    protected $worldObjectModel;
    protected $mapModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);

        $this->telegramUserModel         = new TelegramUserModel();
        $this->craftedItemsLogModel      = new CraftedItemsLogModel();
        $this->craftedItemsModel         = new CraftedItemsModel();
        $this->biomeWorldObjectMapModel  = new BiomeWorldObjectMapModel();
        $this->resourceModel             = new ResourceModel();
        $this->worldObjectModel          = new WorldObjectModel();
        $this->mapModel                  = new MapModel();

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
     * Главный метод. Парсим callbackData, проверяем условия и "вскрываем" склад.
     */
    public function handle(): ServerResponse
    {
        $cbQuery   = $this->callbackQuery;
        $cbId      = $cbQuery->getId();
        $callbackData = $cbQuery->getData();

        // Парсим objectId и objectMapId
        $matches = [];
        preg_match('/objectId\|(\d+)#objectMapId\|(\d+)/', $callbackData, $matches);
        if (count($matches) !== 3) {
            // Ошибка, не сможем продолжить
            log_message('error', "ObjectCloseWarehouseAction: Failed to parse objectId/mapId from callback: $callbackData");
            Request::answerCallbackQuery([
                'callback_query_id' => $cbId,
                'text'             => 'Ошибка. Неверные данные склада.',
                'show_alert'       => true
            ]);
            return Request::emptyResponse();
        }

        $objectId   = (int) $matches[1];
        $objectMapId= (int) $matches[2];

        // Ищем user + character
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            Request::answerCallbackQuery([
                'callback_query_id' => $cbId,
                'text'             => 'Персонаж не найден.',
                'show_alert'       => true
            ]);
            return Request::emptyResponse();
        }

        // 1) Ищем запись в biome_world_object_map, чтобы проверить статус.
        $bwoRow = $this->biomeWorldObjectMapModel
            ->where('world_object_id', $objectId)
            ->where('map_id', $objectMapId)
            ->where('status', 'active')
            ->first();
        if (!$bwoRow) {
            // Либо статус != active, либо нет такой записи
            Request::answerCallbackQuery([
                'callback_query_id' => $cbId,
                'text'             => 'Этот склад уже недоступен!',
                'show_alert'       => true
            ]);
            return Request::emptyResponse();
        }

        // 2) Загружаем сам объект (world_objects).
        $worldObject = $this->worldObjectModel->find($bwoRow['world_object_id']);
        if (!$worldObject) {
            Request::answerCallbackQuery([
                'callback_query_id' => $cbId,
                'text'             => 'Ошибка. Склад не найден.',
                'show_alert'       => true
            ]);
            return Request::emptyResponse();
        }

        // Узнаем клетку, где склад
        $mapRow = $this->mapModel->find($bwoRow['map_id']);
        if (!$mapRow) {
            Request::answerCallbackQuery([
                'callback_query_id' => $cbId,
                'text'             => 'Ошибка карты склада.',
                'show_alert'       => true
            ]);
            return Request::emptyResponse();
        }
        $objectCellNumber = $mapRow['cell_number'];

        // 3) Проверяем, находится ли персонаж рядом (или в той же клетке).
        //    Если НЕ рядом → выводим сообщение / можно добавить show_alert
        if (!$this->isCharacterNearCell($character, $objectCellNumber)) {
            Request::answerCallbackQuery([
                'callback_query_id' => $cbId,
                'text'             => 'Ты слишком далеко от склада!',
                'show_alert'       => true
            ]);
            return Request::emptyResponse();
        }

        // 4) Проверяем инструменты. Если не хватает → сообщение и return.
        $requiredTools = json_decode($worldObject['discovery_tools'], true);
        if (!empty($requiredTools[0])) {
            foreach ($requiredTools[0] as $itemName => $quantity) {
                $item = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($itemName, $character['id']);
                if (!$item || $item['quantity'] < $quantity) {
                    // Нет инструмента -> сообщение + return
                    $this->sendInsufficientToolsMessage($character, $requiredTools[0], $cbId);
                    return Request::emptyResponse();
                }
            }
        }

        // 5) Списываем инструменты
        if (!empty($requiredTools[0])) {
            foreach ($requiredTools[0] as $toolName => $qty) {
                // Если вдруг subtractItem не сработает (мало предметов) -> log
                $ok = $this->craftedItemsLogModel->subtractItem($character['id'], $toolName, $qty);
                if (!$ok) {
                    log_message('error', "Failed to subtract $qty of $toolName for char#{$character['id']}");
                }
            }
        }

        // 6) Шанс, что склад пуст (15%)
        if (mt_rand(1, 100) <= 15) {
            $this->sendEmptyWarehouseMessage($character, $cbId);
            return Request::emptyResponse();
        }

        // 7) Выдача лута
        $contents = json_decode($worldObject['contents'], true);
        if (!empty($contents[0])) {
            $this->awardContents($character, $contents[0], $cbId);
        }

        // 8) (Дополнительно) обновляем статус склада на "cleared", если надо
        //    Закомментировано — раскомментируйте, если нужно
        /*
        $bwoId = $bwoRow['id'];
        $this->biomeWorldObjectMapModel->update($bwoId, ['status' => 'cleared']);
        */

        return Request::emptyResponse();
    }

    /**
     * Проверяем, находится ли персонаж в той же клетке или в соседней.
     */
    private function isCharacterNearCell(array $character, int $objectCellNumber): bool
    {
        // Текущий cell_number игрока
        $charCell = (int) $character['cell_number'];
        if ($charCell === $objectCellNumber) {
            return true;
        }

        // Смотрим 8 соседних ячеек
        $neighbors = $this->mapModel->getNeighboringCells($charCell);
        foreach ($neighbors as $nCell) {
            if ((int)$nCell['cell_number'] === $objectCellNumber) {
                return true;
            }
        }
        return false;
    }

    /**
     * Сообщение, если у игрока не хватает инструментов.
     */
    private function sendInsufficientToolsMessage($character, array $requiredTools, $callbackId)
    {
        $chatId = $this->telegramUserModel->find($character['telegram_user_id'])['telegram_id'] ?? 0;
        Request::answerCallbackQuery([
            'callback_query_id' => $callbackId,
            'text'             => 'У тебя не хватает инструментов!',
            'show_alert'       => true
        ]);

        $msg  = "🏚️ Склад закрыт. Не хватает инструментов:\n\n";
        foreach ($requiredTools as $itemName => $qtyNeeded) {
            $row = $this->craftedItemsModel->getRowByName($itemName);
            $rus = $row ? $row['name_rus'] : $itemName;
            $has = $this->craftedItemsLogModel
                ->where('crafted_item_id', $row['id'] ?? 0)
                ->where('character_id', $character['id'])
                ->first();

            $count = $has ? $has['quantity'] : 0;
            $msg .= "*$rus:* нужно $qtyNeeded, есть $count\n";
        }

        $msg .= "\nПопробуй скрафтить или купить инструменты и вернуться.\n";

        // Пример кнопок
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия', 'callback_data' => 'characterActions'],
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ['text' => '🛒 Магазин', 'callback_data' => 'shop'],
                ]
            ]
        ];

        try {
            Request::sendMessage([
                'chat_id'      => $chatId,
                'text'         => $msg,
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode($keyboard)
            ]);
        } catch (TelegramException $e) {
            log_message('error', "sendInsufficientToolsMessage: " . $e->getMessage());
        }
    }

    /**
     * Склад пуст (15% шанс) — отправляем отдельное сообщение.
     */
    private function sendEmptyWarehouseMessage($character, $callbackId)
    {
        $chatId = $this->telegramUserModel->find($character['telegram_user_id'])['telegram_id'] ?? 0;

        Request::answerCallbackQuery([
            'callback_query_id' => $callbackId,
            'text'             => 'Склад оказался пустым...',
            'show_alert'       => false
        ]);

        $msg  = "😭 Ты потратил силы и время, но склад *оказался пустым!*\n";
        $msg .= "Вероятность 15% — тебе не повезло.\n\n";
        $msg .= "Зато это урок: не все склады везучие. Продолжай выживать!\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия', 'callback_data' => 'characterActions'],
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                ],
            ]
        ];

        try {
            Request::sendMessage([
                'chat_id'      => $chatId,
                'text'         => $msg,
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode($keyboard)
            ]);
        } catch (TelegramException $e) {
            log_message('error', "sendEmptyWarehouseMessage: " . $e->getMessage());
        }
    }

    /**
     * Выдача лута из contents.
     * contents[0] -> {'resources': {...}, 'crafted_items': {...}}
     * Генерируем случайное кол-во в пределах заявленного, добавляем в инвентарь.
     */
    private function awardContents($character, array $contents, $callbackId)
    {
        $chatId = $this->telegramUserModel->find($character['telegram_user_id'])['telegram_id'] ?? 0;

        Request::answerCallbackQuery([
            'callback_query_id' => $callbackId,
            'text'             => 'Взлом успешен, добыча получена!',
            'show_alert'       => false
        ]);

        $msg  = "😎 *Отличная работа*\n\n";
        $msg .= "🏚️ Ты вскрыл склад и нашел:\n";

        // 1) Ресурсы
        if (!empty($contents['resources']) && is_array($contents['resources'])) {
            foreach ($contents['resources'] as $resNameEn => $maxQty) {
                $awarded = mt_rand(1, (int)$maxQty);
                if ($awarded > 0) {
                    // Преобразуем в удобоваримое имя
                    $r = $this->resourceModel->getResourceByNameEn($resNameEn);
                    $displayName = $r ? $r['name'] : $resNameEn;
                    $msg .= "- *{$displayName}:* {$awarded}\n";
                    // Записываем в инвентарь
                    $this->resourceModel->addOrIncreaseResource($character['id'], $resNameEn, $awarded);
                }
            }
        }

        // 2) Крафтовые предметы
        if (!empty($contents['crafted_items']) && is_array($contents['crafted_items'])) {
            foreach ($contents['crafted_items'] as $itemNameEng => $maxQty) {
                $awarded = mt_rand(1, (int)$maxQty);
                if ($awarded > 0) {
                    // Смотрим, что за предмет, чтобы вывести русское название
                    $ciRow = $this->craftedItemsModel->getRowByName($itemNameEng);
                    $rusName = $ciRow ? $ciRow['name_rus'] : $itemNameEng;
                    $msg .= "- *{$rusName}:* {$awarded} шт.\n";

                    // Записываем в crafted_items_log
                    $this->craftedItemsModel->addOrIncreaseItem($character['id'], $itemNameEng, $awarded);
                }
            }
        }

        $msg .= "\n_Продолжай поиски — в мире много чего интересного!_\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия',       'callback_data' => 'characterActions'],
                    ['text' => '🗺️ Исследовать ещё', 'callback_data' => 'explore']
                ]
            ]
        ];

        try {
            Request::sendMessage([
                'chat_id'      => $chatId,
                'text'         => $msg,
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode($keyboard)
            ]);
        } catch (TelegramException $e) {
            log_message('error', "awardContents sendMessage: " . $e->getMessage());
        }
    }
}
