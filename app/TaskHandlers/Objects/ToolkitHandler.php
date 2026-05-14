<?php

namespace App\TaskHandlers\Objects;

use App\Attributes\HandlerKey;
use App\Models\BiomeWorldObjectMapModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\ResourceModel;
use App\Models\TelegramUserModel;

/**
 * v0.51.39 (F2.9 batch-4): extends BaseObjectHandler. Раніше manual Telegram
 * init у constructor + Request::sendPhoto raw call.
 */
#[HandlerKey(
    key: 'world_object_toolkit',
    displayName: 'World-object: Набор инструментов',
    description: 'Discovery handler для world_objects.name_en="Toolkit". Авто-лут: выдаёт инструмент из бага инструмента.',
)]
class ToolkitHandler extends BaseObjectHandler implements ObjectHandlerInterface
{
    protected $telegramUserModel;
    protected $craftedItemsLogModel;
    protected $craftedItemsModel;
    protected $biomeWorldObjectMapModel;
    protected $resourceModel;

    public function __construct()
    {
        $this->telegramUserModel = new TelegramUserModel();
        $this->craftedItemsLogModel = new CraftedItemsLogModel();
        $this->craftedItemsModel = new CraftedItemsModel();
        $this->biomeWorldObjectMapModel = new BiomeWorldObjectMapModel();
        $this->resourceModel = new ResourceModel();
    }

    public function handle($object, $cell, $character) {
        $this->processDiscovery($object, $cell, $character, $requiredTools=null);
    }

    private function processDiscovery($object, $cell, $character, $requiredTools): void {
        // Разбор награды
        $contents = json_decode($object['contents'], true);
        $this->awardContents($character, $contents[0]);

        //Обновление статуса объекта на 'cleared'
        $biomeWorldObjectMapId = $this->biomeWorldObjectMapModel
            ->where('world_object_id', $object['world_object_id'])
            ->where('map_id', $object['map_id'])
            ->first()['id'];

        if (!$this->biomeWorldObjectMapModel->updateStatus($biomeWorldObjectMapId,'cleared')) {
            log_message('error', 'Failed to update status for object ID: ' . $object['id']);
        }
    }

    private function awardContents($character, $contents): void {
        $foundItems = []; // Создаем пустой массив для найденных предметов

        if (isset($contents['resources'])) {
            foreach ($contents['resources'] as $resourceName => $amount) {
                // Генерируем случайное количество ресурса от 1 до $amount
                $randomAmount = rand(1, $amount);
                $this->resourceModel->addOrIncreaseResource($character['id'], $resourceName, $randomAmount);
                $foundItems[$resourceName] = $randomAmount; // Добавляем найденный ресурс в массив
            }
        } elseif (isset($contents['crafted_items'])) {
            foreach ($contents['crafted_items'] as $itemName => $amount) {
                // Генерируем случайное количество крафтового предмета от 1 до $amount
                $randomAmount = rand(1, $amount);
                $this->craftedItemsModel->addOrIncreaseItem($character['id'], $itemName, $randomAmount);
                $foundItems[$itemName] = $randomAmount; // Добавляем найденный крафтовый предмет в массив
            }
        } else {
            foreach ($contents as $itemName => $amount) {
                if ($this->resourceModel->where('name_en', $itemName)->first()) {
                    // Генерируем случайное количество ресурса от 1 до $amount
                    $randomAmount = rand(1, $amount);
                    $this->resourceModel->addOrIncreaseResource($character['id'], $itemName, $randomAmount);
                    $foundItems[$itemName] = $randomAmount; // Добавляем найденный ресурс в массив
                } elseif ($this->craftedItemsModel->where('name_eng', $itemName)->first()) {
                    // Генерируем случайное количество крафтового предмета от 1 до $amount
                    $randomAmount = rand(1, $amount);
                    $this->craftedItemsModel->addOrIncreaseItem($character['id'], $itemName, $randomAmount);
                    $foundItems[$itemName] = $randomAmount; // Добавляем найденный крафтовый предмет в массив
                }
            }
        }

        // Отправка сообщения о награде с найденными предметами
        $this->sendRewardMessage($character, $foundItems);
    }

    private function sendRewardMessage($character, $contents): void {
        $chatId = $this->telegramUserModel->where('id', $character['telegram_user_id'])->first()['telegram_id'];
        $messageText = "🌲 В процессе *Изучения местности* ты нашел схрон:.\n\n";
        $messageText .= "Это ящик с ⚒ *Набором инструментов*\n";
        $messageText .= "_Вот, что было внутри:_\n\n";

        if (isset($contents['resources'])) {
            foreach ($contents['resources'] as $name => $amount) {
                $name = $this->resourceModel->getResourceByNameEn($name)['name'];
                $messageText .= "- *$name:* $amount\n";
            }
        }elseif (isset($contents['crafted_items'])) {
            foreach ($contents['crafted_items'] as $name => $amount) {
                $name = $this->craftedItemsModel->getRowByName($name)['name_rus'];
                $messageText .= "- *$name:* $amount шт.\n";
            }
        }else {
            foreach ($contents as $name => $amount) {
                if($this->resourceModel->where('name_en', $name)->first()) {
                    $name = $this->resourceModel->getResourceByNameEn($name)['name'];
                    $messageText .= "- *$name:* $amount\n";
                }elseif ($this->craftedItemsModel->where('name_eng', $name)->first()){
                    $name = $this->craftedItemsModel->getRowByName($name)['name_rus'];
                    $messageText .= "- *$name:* $amount шт.\n";
                }
            }
        }

        $messageText .= "\nПродолжай исследования, чтобы находить больше полезных ресурсов!";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🗺️ Исследовать далее', 'callback_data' => 'march']
                ]
            ]
        ];

        $this->safeSendPhoto(
            $chatId,
            base_url('uploads/telegram/objects/collection-of-rusted-and-weathered-tools-including.jpg'),
            $messageText,
            ['parse_mode' => 'Markdown', 'reply_markup' => json_encode($keyboard)]
        );
    }
}
