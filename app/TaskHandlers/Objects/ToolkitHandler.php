<?php

namespace App\TaskHandlers\Objects;

use App\Models\BiomeWorldObjectMapModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\ResourceModel;
use App\Models\TelegramUserModel;
use App\TaskHandlers\Objects\ObjectHandlerInterface;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

class ToolkitHandler implements ObjectHandlerInterface {
    private $telegram;
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
        $this->processDiscovery($object, $cell, $character, $requiredTools=null);
    }

    private function processDiscovery($object, $cell, $character, $requiredTools) {
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

    private function awardContents($character, $contents) {
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

    private function sendRewardMessage($character, $contents) {
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
                    ['text' => '🗺️ Исследовать далее', 'callback_data' => 'explore']
                ]
            ]
        ];

        try {
            Request::sendPhoto([
                'chat_id' => $chatId,
                'photo'   => Request::encodeFile(base_url('uploads/telegram/objects/collection-of-rusted-and-weathered-tools-including.jpg')),
                'caption' => $messageText,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard)
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Failed to send message: " . $e->getMessage());
        }
    }

}
