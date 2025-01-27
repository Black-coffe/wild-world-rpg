<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\ActiveEventModel;
use App\Models\ClaimedCellModel;
use App\Models\CharacterModel;
use App\Models\BuildingModel;
use App\Models\ResourceModel;
use App\Models\CharacterResourceModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\EventModel;
use App\Models\TaskModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class StartBuildSolarStationConstruction extends BaseAction
{
    protected $claimedCellModel;
    protected $characterModel;
    protected $buildingModel;
    protected $resourceModel;
    protected $caracterResourceModel;
    protected $taskModel;
    protected $eventModel;
    protected $craftedItemsModel;
    protected $craftedItemsLogModel;
    protected $activeEventModel;
    protected $characterResourceModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->claimedCellModel = new ClaimedCellModel();
        $this->characterModel = new CharacterModel();
        $this->buildingModel = new BuildingModel();
        $this->resourceModel = new ResourceModel();
        $this->caracterResourceModel = new CharacterResourceModel();
        $this->taskModel = new TaskModel();
        $this->eventModel = new EventModel();
        $this->craftedItemsModel = new CraftedItemsModel();
        $this->craftedItemsLogModel = new CraftedItemsLogModel();
        $this->activeEventModel = new ActiveEventModel();
        $this->characterResourceModel = new CharacterResourceModel();
    }

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendError('Пользователь не найден в базе данных или персонаж не определён.');
        }

        // Проверка активного переезда (BaseRelocation)
        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $this->callbackQuery->getMessage()->getChat()->getId()
        )) {
            return Request::emptyResponse(); // Переезд есть, сервис уже отписался
        }

        // 1. Проверка наличия лагеря
        $claimedCells = $this->claimedCellModel->where('character_id', $character['id'])->findAll();
        if (empty($claimedCells)) {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🏕 Разбить лагерь', 'callback_data' => 'Camp'],
                        ['text' => '👤 Персонаж', 'callback_data' => 'character'],
                    ],
                ]
            ];

            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => "У вас нет лагеря. Разбейте лагерь, чтобы продолжить.",
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        }

        // 2. Проверка нахождения персонажа на базе
        $character = $this->characterModel->find($character['id']);
        $currentCell = $character['cell_number'];
        $campCell = $claimedCells[0]['map_cell_id']; // Предположим, что у персонажа только один лагерь
        if ($currentCell != $campCell) {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '📡 Телепорт', 'callback_data' => 'TeleportToCamp'],
                        ['text' => '🚜 Переехать', 'callback_data' => 'move'],
                        ['text' => '👤 Персонаж', 'callback_data' => 'character'],
                    ],
                ]
            ];

            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => "Вы находитесь не в лагере. Переместитесь в лагерь, чтобы продолжить строительство.",
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        }

        // 3. Проверка уровня персонажа для строительства
        $building = $this->buildingModel->where('name_en', 'SolarStation')->first();
        if ($character['level'] < $building['level']) {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '📡 Телепорт', 'callback_data' => 'TeleportToCamp'],
                        ['text' => '🚜 Переехать', 'callback_data' => 'move'],
                        ['text' => '👤 Персонаж', 'callback_data' => 'character'],
                    ],
                ]
            ];

            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => "Ваш уровень слишком низкий для строительства *☀️ Солнечной станции*.",
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        }

        // 4. Проверка ресурсов и крафтовых предметов
        $requiredResources = [
            'VolcanicAsh' => 15,
        ];
        $requiredCraftedItems = [
            'GlassBags' => 29,
            'metalFragments' => 11,
            'stoneBlocks' => 5,
            'WoodMaterials' => 10,
            'WorkbenchOne' => 1,
        ];

        $missingResources = $this->checkResources($character['id'], $requiredResources, $this->resourceModel, $this->characterResourceModel);
        $missingCraftedItems = $this->checkCraftedItems($character['id'], $requiredCraftedItems, $this->craftedItemsModel, $this->craftedItemsLogModel);

        if ($missingResources || $missingCraftedItems) {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '⛏️ Добыть ресурсы', 'callback_data' => 'gather'],
                        ['text' => '🛍️ Купить', 'callback_data' => 'buy'],
                    ],
                    [
                        ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ]
                ]
            ];

            // Формируем текст сообщения о недостающих ресурсах и предметах
            $missingResourcesText = $this->getMissingResourcesText($missingResources, $this->resourceModel);
            $missingCraftedItemsText = $this->getMissingCraftedItemsText($missingCraftedItems, $this->craftedItemsModel);

            $messageText = "Недостаточно ресурсов для строительства *☀️ Солнечной станции*.\n\n";
            if ($missingResourcesText) {
                $messageText .= "Недостающие ресурсы:\n" . $missingResourcesText . "\n";
            }
            if ($missingCraftedItemsText) {
                $messageText .= "Недостающие крафтовые предметы:\n" . $missingCraftedItemsText . "\n";
            }

            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => $messageText,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        }

        // 5. Начало строительства
        return $this->startBuildingProcess($character, $user, $requiredResources, $requiredCraftedItems);
    }

    private function startBuildingProcess($character, $userId, $requiredResources, $requiredCraftedItems): ServerResponse
    {
        $craftTask = $this->taskModel->where('name', 'startBuildSolarStation')->first();
        if (!$craftTask) {
            return $this->sendError('Задача "Строительство солнечной станции" не найдена в базе данных.');
        }

        // Проверка наличия активной задачи Строительства
        $activeTask = $this->characterTaskModel->where([
            'character_id' => $character['id'],
            'task_id' => $craftTask['id'],
            'status' => 'in_work'
        ])->first();

        if ($activeTask) {
            return $this->sendError("Извини, но ты не многорукий и не всемогущ. Данная задача строительства уже выполняется, ожидай. А чтобы не скучать пойди проведи время в разделе \"Развлечения\"");
        }

        // Calculate adjusted crafting duration
        $duration = $this->calculateCraftingDuration($character, $craftTask);

        $startTime = new \DateTime();
        $endTime = (clone $startTime)->add(new \DateInterval('PT' . $duration . 'M'));


        $this->characterTaskModel->save([
            'character_id' => $character['id'],
            'telegram_user_id' => $userId['id'],
            'task_id' => $craftTask['id'],
            'start_time' => $startTime->format('Y-m-d H:i:s'),
            'end_time' => $endTime->format('Y-m-d H:i:s'),
            'status' => 'in_work',
        ]);

        // Списание ресурсов
        $this->subtractResources($character['id'], $requiredResources, $this->resourceModel, $this->characterResourceModel);

        // Списание крафтовых предметов
        $subtractResult = $this->subtractCraftedItems($character['id'], $requiredCraftedItems, $this->craftedItemsModel, $this->craftedItemsLogModel);
        if (!$subtractResult) {
            return $this->sendError('Ошибка при списании предметов.');
        }

        return $this->notifyCraftStarted($character, $startTime, $endTime);

    }

    private function notifyCraftStarted($character, $startTime, $endTime): ServerResponse
    {
        $interval = $startTime->diff($endTime);
        $minutes = $interval->days * 1440 + $interval->h * 60 + $interval->i;

        $text = "*Процесс строительства запущен*\n\n"
            . "*Ты строишь: ☀️ Солнечную станцию*\n\n"
            . "__*Время стройки: " . $minutes . " минут.*__ ⏱️\n\n"
            . "*О готовности ты узнаешь в сообщении.* 🎁\n\n"
            . "P.S. _Не забудь поделиться своими находками!_ 🗣️\n";

        $imagePath = base_url('uploads/telegram/camp/Construction-by-improvised.jpg'); // Ensure this path is correctly configured
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendPhoto([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'   => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    private function calculateCraftingDuration($character, $craftTask)
    {
        // Retrieve character attributes
        $experience = $character['experience'];
        $agility = $character['agility'];
        $intellect = $character['intellect'];

        // Define weighting factors for each attribute
        $expFactor = 0.3; // 30% weight to experience
        $agiFactor = 0.3; // 30% weight to agility
        $intFactor = 0.4; // 40% weight to intellect

        // Calculate attribute contribution
        $attributeScore = ($experience * $expFactor) + ($agility * $agiFactor) + ($intellect * $intFactor);
        $maxAttributeScore = 1000 * ($expFactor + $agiFactor + $intFactor); // Assuming maximum score for each is 1000

        // Normalize the score to a scale of 0 to 1
        $normalizedScore = $attributeScore / $maxAttributeScore;

        // Determine crafting time based on normalized score
        $minDuration = $craftTask['min_duration'];
        $maxDuration = $craftTask['max_duration'];
        $adjustedDuration = $minDuration + ($maxDuration - $minDuration) * (1 - $normalizedScore); // Inverse relationship

        // Ensure the duration is within task defined limits
        return max($minDuration, min($maxDuration, round($adjustedDuration)));
    }

    private function checkResources($characterId, $requiredResources, $resourceModel, $characterResourcesModel)
    {
        $missingResources = [];
        foreach ($requiredResources as $resourceName => $requiredAmount) {
            $resource = $resourceModel->getResourceByNameEn($resourceName);
            if ($resource) {
                $characterResource = $characterResourcesModel->where('id_characters', $characterId)
                    ->where('id_resources', $resource['id'])
                    ->first();
                if (!$characterResource || $characterResource['quantity'] < $requiredAmount) {
                    $missingResources[$resourceName] = [
                        'required' => $requiredAmount,
                        'available' => $characterResource ? $characterResource['quantity'] : 0,
                        'name' => $resource['name']
                    ];
                }
            }
        }
        return $missingResources;
    }

    private function checkCraftedItems($characterId, $requiredCraftedItems, $craftedItemsModel, $craftedItemsLogModel)
    {
        $missingCraftedItems = [];
        foreach ($requiredCraftedItems as $itemName => $requiredAmount) {
            $item = $craftedItemsModel->getRowByName($itemName);
            if ($item) {
                $craftedItem = $craftedItemsLogModel->getItemByCraftedItemIdAndCharacterId($item['id'], $characterId);
                if (!$craftedItem || $craftedItem['quantity'] < $requiredAmount) {
                    $missingCraftedItems[$itemName] = [
                        'required' => $requiredAmount,
                        'available' => $craftedItem ? $craftedItem['quantity'] : 0,
                        'name_rus' => $item['name_rus']
                    ];
                }
            }
        }
        return $missingCraftedItems;
    }

    private function getMissingResourcesText($missingResources, $resourceModel)
    {
        $text = "";
        foreach ($missingResources as $resourceName => $resourceInfo) {
            $text .= "- " . $resourceInfo['name'] . ": требуется " . $resourceInfo['required'] . ", в наличии " . $resourceInfo['available'] . "\n";
        }
        return $text;
    }

    private function getMissingCraftedItemsText($missingCraftedItems, $craftedItemsModel)
    {
        $text = "";
        foreach ($missingCraftedItems as $itemName => $itemInfo) {
            $text .= "- " . $itemInfo['name_rus'] . ": требуется " . $itemInfo['required'] . ", в наличии " . $itemInfo['available'] . "\n";
        }
        return $text;
    }

    private function sendError($message): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text' => $message,
        ]);
    }

    private function subtractResources($characterId, $requiredResources, $resourceModel, $characterResourcesModel)
    {
        foreach ($requiredResources as $resourceName => $requiredAmount) {
            $resource = $resourceModel->getResourceByNameEn($resourceName);
            if ($resource) {
                $characterResourcesModel->where('id_characters', $characterId)
                    ->where('id_resources', $resource['id'])
                    ->set('quantity', 'quantity - ' . $requiredAmount, false)
                    ->update();
            }
        }
    }

    private function subtractCraftedItems($characterId, $requiredCraftedItems, $craftedItemsModel, $craftedItemsLogModel)
    {
        $db = \Config\Database::connect();
        $db->transStart();

        foreach ($requiredCraftedItems as $itemName => $requiredAmount) {
            $item = $craftedItemsModel->getRowByName($itemName);
            if ($item) {
                $logEntry = $craftedItemsLogModel->where('character_id', $characterId)
                    ->where('crafted_item_id', $item['id'])
                    ->first();
                if ($logEntry) {
                    if ($itemName == 'WorkbenchOne') {
                        // Специальная обработка для верстака 1-го уровня
                        if ($logEntry['durability_count'] > 0) {
                            $newDurability = $logEntry['durability_count'] - 1;
                            $craftedItemsLogModel->update($logEntry['id'], ['durability_count' => $newDurability]);
                        } else {
                            log_message('error', 'Недостаточно прочности у Верстака 1-го уровня');
                            $db->transRollback();
                            return false;
                        }
                    } else {
                        // Общая обработка для остальных предметов
                        if ($logEntry['quantity'] >= $requiredAmount) {
                            $newQuantity = $logEntry['quantity'] - $requiredAmount;
                            if ($newQuantity > 0) {
                                $craftedItemsLogModel->update($logEntry['id'], ['quantity' => $newQuantity]);
                            } else {
                                $craftedItemsLogModel->delete($logEntry['id']);
                            }
                        } else {
                            log_message('error', 'Недостаточно предметов для списания: ' . $itemName);
                            $db->transRollback();
                            return false;
                        }
                    }
                } else {
                    log_message('error', 'Запись о предмете не найдена: ' . $itemName);
                    $db->transRollback();
                    return false;
                }
            } else {
                log_message('error', 'Предмет не найден: ' . $itemName);
                $db->transRollback();
                return false;
            }
        }

        $db->transComplete();

        if ($db->transStatus() === FALSE) {
            log_message('error', 'Ошибка при списании предметов');
            return false;
        }

        return true;
    }

}

