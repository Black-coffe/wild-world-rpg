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
use App\Models\CharacterBuildingModel; // Импортируем модель
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class BuildSolarStationConstruction extends BaseAction
{
    protected $claimedCellModel;
    protected $characterModel;
    protected $buildingModel;
    protected $resourceModel;
    protected $characterResourceModel;
    protected $taskModel;
    protected $eventModel;
    protected $craftedItemsModel;
    protected $craftedItemsLogModel;
    protected $activeEventModel;
    protected $characterBuildingModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->claimedCellModel = new ClaimedCellModel();
        $this->characterModel = new CharacterModel();
        $this->buildingModel = new BuildingModel();
        $this->resourceModel = new ResourceModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->taskModel = new TaskModel();
        $this->eventModel = new EventModel();
        $this->craftedItemsModel = new CraftedItemsModel();
        $this->craftedItemsLogModel = new CraftedItemsLogModel();
        $this->activeEventModel = new ActiveEventModel();
        $this->characterBuildingModel = new CharacterBuildingModel(); // Инициализируем модель
    }

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendError('Пользователь не найден в базе данных или персонаж не определён.');
        }

        // 1. Проверка наличия лагеря
        $claimedCells = $this->claimedCellModel->where('character_id', $character['id'])->findAll();
        if (empty($claimedCells)) {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🏕 Разбить лагерь', 'callback_data' => 'Camp'],
                        ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
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

        // 4. Проверка наличия мастерской
        $workshop = $this->buildingModel->where('name_en', 'Workshop')->first();
        $workshopExists = $this->characterBuildingModel->where('character_id', $character['id'])
            ->where('building_id', $workshop['id'])
            ->first();

        // 5. Проверка ресурсов и крафтовых предметов
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

        // 6. Отображение информации о строительстве
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                ],
            ]
        ];

        if ($workshopExists && empty($missingResources) && empty($missingCraftedItems)) {
            array_unshift($keyboard['inline_keyboard'], [['text' => '🛠️ Строить', 'callback_data' => 'startBuildSolarStation']]);
        }

        $text = "*☀️ Солнечная станция*\n"
            . "Тебе нужны:\n\n"
            . $this->formatResourcesForText($requiredResources, $this->resourceModel, $character['id'])
            . $this->formatCraftedItemsForText($requiredCraftedItems, $this->craftedItemsModel, $character['id'])
            . "\n*Стройка:* ~40 минут!\n"
            . "\n*Описание:* Дает возможность электрофицировать сооружения и делать автоматизацию многих вещей, нужно для прокачки уровней зданий.\n";

        if (!$workshopExists) {
            $text .= "\nДля строительства *☀️ Солнечной станции* необходима *Мастерская*.\n";
        }

        if ($missingResources || $missingCraftedItems) {
            // Формируем текст сообщения о недостающих ресурсах и предметах
            $missingResourcesText = $this->getMissingResourcesText($missingResources, $this->resourceModel);
            $missingCraftedItemsText = $this->getMissingCraftedItemsText($missingCraftedItems, $this->craftedItemsModel);

            $text .= "\nНедостаточно для стройки:\n\n";
            if ($missingResourcesText) {
                $text .= "Ресурсы:\n" . $missingResourcesText . "\n";
            }
            if ($missingCraftedItemsText) {
                $text .= "Крафт предметы:\n" . $missingCraftedItemsText . "\n";
            }
        }

        $imagePath = base_url('uploads/telegram/camp/solar_power_station.jpg');
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendPhoto([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo' => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
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

    private function formatResourcesForText($requiredResources, $resourceModel, $characterId)
    {
        $text = "";
        foreach ($requiredResources as $resourceName => $requiredAmount) {
            $resource = $resourceModel->getResourceByNameEn($resourceName);
            if ($resource) {
                $characterResource = $this->characterResourceModel->where('id_characters', $characterId)
                    ->where('id_resources', $resource['id'])
                    ->first();
                $availableAmount = $characterResource ? $characterResource['quantity'] : 0;
                $text .= "📦 " . $resource['name'] . " - " . $requiredAmount . " ед. (в наличии " . $availableAmount . " ед.)\n";
            }
        }
        return $text;
    }

    private function formatCraftedItemsForText($requiredCraftedItems, $craftedItemsModel, $characterId)
    {
        $text = "";
        foreach ($requiredCraftedItems as $itemName => $requiredAmount) {
            $item = $craftedItemsModel->getRowByName($itemName);
            if ($item) {
                $craftedItem = $this->craftedItemsLogModel->getItemByCraftedItemIdAndCharacterId($item['id'], $characterId);
                $availableAmount = $craftedItem ? $craftedItem['quantity'] : 0;
                $text .= "📦 " . $item['name_rus'] . " - " . $requiredAmount . " ед. (в наличии " . $availableAmount . " ед.)\n";
            }
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
}

