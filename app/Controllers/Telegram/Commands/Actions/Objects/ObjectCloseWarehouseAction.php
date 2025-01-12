<?php

namespace App\Controllers\Telegram\Commands\Actions\Objects;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\BiomeModel;
use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
use App\Models\MapModel;
use App\Models\ResourceModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use App\TaskHandlers\Objects\ObjectHandlerInterface;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Telegram;
use App\Models\TelegramUserModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\BiomeWorldObjectMapModel;
use App\Models\WorldObjectModel;

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
        $this->telegramUserModel = new TelegramUserModel();
        $this->craftedItemsLogModel = new CraftedItemsLogModel();
        $this->craftedItemsModel = new CraftedItemsModel();
        $this->biomeWorldObjectMapModel = new BiomeWorldObjectMapModel();
        $this->resourceModel = new ResourceModel();
        $this->worldObjectModel = new WorldObjectModel();
        $this->mapModel = new MapModel();

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

    public function handle(): ServerResponse
    {
        $callbackData = $this->callbackQuery->getData();
        $matches = [];
        preg_match('/objectId\|(\d+)#objectMapId\|(\d+)/', $callbackData, $matches);

        if (count($matches) === 3) {
            $objectId = $matches[1];
            $objectMapId = $matches[2];
        } else {
            log_message('error', "Failed to extract objectId and objectMapId from callbackData: $callbackData");
        }

        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        $biomeWorldObjectMapModelRow = $this->biomeWorldObjectMapModel
            ->where('world_object_id', $objectId)
            ->where('map_id', $objectMapId)
            ->where('status', 'active')
            ->first();

        $worldObject = $this->worldObjectModel->where('id', $biomeWorldObjectMapModelRow['world_object_id'])->first();
        $worldObjectCellNumber = $this->mapModel->where('id', $biomeWorldObjectMapModelRow['map_id'])->first()['cell_number'];
        // Декодирование необходимых инструментов
        $requiredTools = json_decode($worldObject['discovery_tools'], true);

        // Проверка наличия каждого инструмента
        foreach ($requiredTools[0] as $itemName => $quantity) {
            $item = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($itemName, $character['id']);
            if (!$item || $item['quantity'] < $quantity) {
                // Недостаточно инструментов, отправка сообщения и выход
                $this->sendInsufficientToolsMessage($character, $requiredTools[0]);
            }
        }

        // Если инструменты есть у игрока идем далее
        $this->processDiscovery($worldObject, $character, $requiredTools, $worldObjectCellNumber);
        return Request::emptyResponse();
    }

    private function processDiscovery($object, $character, $requiredTools, $worldObjectCellNumber) {
        // Получаем текущую локацию и биомы вокруг
        $mapModel = new MapModel();
        $biomeModel = new BiomeModel();
        $currentCell = $mapModel->where('cell_number', $character['cell_number'])->first();
        // Передаем экземпляры моделей как аргументы
        $surroundingCells = $this->getSurroundingCells($currentCell, $mapModel, $biomeModel);

        // Проверяем есть ли одна из ячеек совпадением ячейке с объектом в игровом мире
        foreach ($surroundingCells as $cell) {
            log_message('debug', "cell_number around: " . print_r($cell['cell_number'], true));
            log_message('debug', "cell_number character: " . print_r($character['cell_number'], true));
            log_message('debug', "cell_number objectCellNumber: " . print_r($worldObjectCellNumber, true));
            if($cell['cell_number'] === $worldObjectCellNumber or $character['cell_number'] === $worldObjectCellNumber) {
                //Списание инструментов
                foreach ($requiredTools[0] as $tool => $quantity) {
                    $this->craftedItemsLogModel->subtractItem($character['id'], $tool, $quantity);
                }

                // Шанс, что склад пустой = 15%
                if (mt_rand(1, 100) <= 15) {
                    // Склад пуст, отправляем сообщение об этом
                    $this->sendEmptyWarehouseMessage($character);
                    return;
                }

                // Разбор награды
                $contents = json_decode($object['contents'], true);
                $this->awardContents($character, $contents[0]);

                    //Обновление статуса объекта на 'cleared'
//        $biomeWorldObjectMapId = $this->biomeWorldObjectMapModel
//            ->where('world_object_id', $object['world_object_id'])
//            ->where('map_id', $object['map_id'])
//            ->first()['id'];
//
//        if (!$this->biomeWorldObjectMapModel->updateStatus($biomeWorldObjectMapId,'cleared')) {
//            log_message('error', 'Failed to update status for object ID: ' . $object['id']);
//        }

            }
        }
    }

    private function sendEmptyWarehouseMessage($character) {
        $chatId = $this->telegramUserModel->where('id', $character['telegram_user_id'])->first()['telegram_id'];

        $messageText = "😭 Ты потратил силы, время, но склад *оказался пустой!*\n\n";
        $messageText .= "🎳 *15%* дается, что он будет пустой и ты попал в них.\n\n";
        $messageText .= "🥳 _Продолжай свое выживание, иди вперед и не вешай нос!_\n\n";
        $messageText .= "📝 *P.S.* _В этом мире еще много есть разных объектов и их величие и польза в разы выше этого дряхлого, никому не нужного склада!_";
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                ],
                [
                    ['text' => '🛒 Магазин', 'callback_data' => 'shop'],
                    ['text' => '🛠️ Крафт', 'callback_data' => 'crafting']
                ],
            ]
        ];
        $imagePath = base_url('uploads/telegram/objects/send_empty_warehouse.jpg');
        Request::answerCallbackQuery(['callback_query_id' => $chatId]);
        try {
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

    private function awardContents($character, $contents) {
        $chatId = $this->telegramUserModel->where('id', $character['telegram_user_id'])->first()['telegram_id'];
        $messageText = "😎 *Отличная работа*\n\n";
        $messageText .= "🏚️ Успешно обыскал и унес с собой:\n\n";

        if (isset($contents['resources'])) {
            foreach ($contents['resources'] as $name => $amount) {
                // Генерируем случайное количество от 1 до максимального
                $awardedAmount = mt_rand(1, $amount);
                // Выдаем только в случае положительного количества
                if ($awardedAmount > 0) {
                    $name = $this->resourceModel->getResourceByNameEn($name)['name'];
                    $messageText .= "- *$name:* $awardedAmount\n";
                    // Добавляем или увеличиваем ресурс с учетом случайного количества
                    $this->resourceModel->addOrIncreaseResource($character['id'], $name, $awardedAmount);
                }
            }
        }

        if (isset($contents['crafted_items'])) {
            foreach ($contents['crafted_items'] as $name => $amount) {
                // Генерируем случайное количество от 1 до максимального
                $awardedAmount = mt_rand(1, $amount);
                // Выдаем только в случае положительного количества
                if ($awardedAmount > 0) {
                    $name = $this->craftedItemsModel->getRowByName($name)['name_rus'];
                    $messageText .= "- *$name:* $awardedAmount шт.\n";
                    // Добавляем или увеличиваем предмет с учетом случайного количества
                    $this->craftedItemsModel->addOrIncreaseItem($character['id'], $name, $awardedAmount);
                }
            }
        }

        $messageText .= "\n_Продолжай исследования, чтобы находить еще больше полезных ресурсов, мест и объектов! НО ПОМНИ среди этого мира не все интересное, полезное и дружелюбное_";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🗺️ Исследовать далее', 'callback_data' => 'explore']
                ]
            ]
        ];

        try {
            Request::answerCallbackQuery(['callback_query_id' => $chatId]);
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
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
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

    private function getSurroundingCells($currentCell, MapModel $mapModel, BiomeModel $biomeModel) {
        $x = $currentCell['coordinate_x'];
        $y = $currentCell['coordinate_y'];

        // Определение координат соседних ячеек
        $neighboringPositions = [
            ['x' => $x - 1, 'y' => $y - 1], // Северо-запад
            ['x' => $x,     'y' => $y - 1], // Север
            ['x' => $x + 1, 'y' => $y - 1], // Северо-восток
            ['x' => $x - 1, 'y' => $y],     // Запад
            ['x' => $x + 1, 'y' => $y],     // Восток
            ['x' => $x - 1, 'y' => $y + 1], // Юго-запад
            ['x' => $x,     'y' => $y + 1], // Юг
            ['x' => $x + 1, 'y' => $y + 1], // Юго-восток
        ];

        $surroundingCellsInfo = [];

        foreach ($neighboringPositions as $position) {
            // Получение ячеек по координатам
            $cell = $mapModel->where('coordinate_x', $position['x'])
                ->where('coordinate_y', $position['y'])
                ->first();

            if ($cell) {
                // Получение информации о биоме
                $biome = $biomeModel->find($cell['biome_id']);
                $biomeName = $biome ? $biome['name'] : 'Неизвестный биом';

                $surroundingCellsInfo[] = [
                    'cell_number' => $cell['cell_number'],
                    'coordinates' => "X={$position['x']}, Y={$position['y']}",
                    'biome' => $biomeName,
                    'biome_id' => $cell['biome_id'], // Добавить эту строку
                    'map_id' => $cell['id']  // Добавляем map_id, предполагая, что это primary key в таблице map
                ];
            }
        }

        return $surroundingCellsInfo;
    }
}
