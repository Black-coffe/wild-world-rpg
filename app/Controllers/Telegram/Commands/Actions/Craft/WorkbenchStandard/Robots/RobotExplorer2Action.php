<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Robots;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
use App\Models\ClaimedCellModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\ResourceModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class RobotExplorer2Action extends BaseAction
{
    protected $characterResourceModel;
    protected $resourceModel;
    protected $characterModel;
    protected $buildingModel;
    protected $characterBuildingModel;
    protected $claimedCellModel;
    protected $craftedItemsLogModel;
    protected $craftedItemsModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterResourceModel = new CharacterResourceModel();
        $this->resourceModel = new ResourceModel();
        $this->characterModel = new CharacterModel();
        $this->buildingModel = new BuildingModel();
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->claimedCellModel = new ClaimedCellModel();
        $this->craftedItemsLogModel = new CraftedItemsLogModel();
        $this->craftedItemsModel = new CraftedItemsModel();
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        $characterId = $character['id'];

        $requiredResources = [
            'Янтарь' => 6,
            'Смола деревьев' => 40,
            'Солнечные камни' => 30,
        ];

        $requiredComponents = [
            'Стекло пакеты' => 2,
            'Ткань' => 12,
            'Металл фрагменты' => 36,
        ];

        $requiredGold = 15000;

        // Проверка наличия построенной базы (лагеря)
        if (!$this->checkBaseAvailability($character)) {
            return $this->sendInsufficientResponse($chatId, 'У вас нет построенной базы (лагеря).');
        }

        // Проверка наличия ресурсов
        $resourcesAvailable = $this->checkResourcesAvailability($characterId, $requiredResources);
        // Проверка наличия компонентов
        $componentsAvailable = $this->checkCraftedItemAvailability($characterId, $requiredComponents);
        // Проверка наличия золота
        $goldAvailable = $this->characterModel->where('id', $characterId)->first();

        $goldQuantity = $goldAvailable ? $goldAvailable['gold'] : 0;

        $text = "*🔍 Исследователь!*\n\n"
            . "*Описание:* робот, который _изучает местность_, он будет искать и открывать новые локации, сохраняя в базе открытий\n";

        $insufficientResources = [];

        // Список ресурсов
        foreach ($requiredResources as $resourceName => $requiredAmount) {
            $availableAmount = $resourcesAvailable[$resourceName]['quantity'] ?? 0;
            if ($availableAmount < $requiredAmount) {
                $insufficientResources[] = "📦 {$resourceName} - {$availableAmount} есть, нужно {$requiredAmount}\n";
            }
        }

        // Список компонентов
        foreach ($requiredComponents as $componentName => $requiredAmount) {
            $availableAmount = $componentsAvailable[$componentName]['quantity'] ?? 0;
            if ($availableAmount < $requiredAmount) {
                $insufficientResources[] = "📦 {$componentName} - {$availableAmount} есть, нужно {$requiredAmount}\n";
            }
        }

        // Золото
        if ($goldQuantity < $requiredGold) {
            $insufficientResources[] = "💰 Золото - {$goldQuantity} есть, нужно {$requiredGold} ед.\n";
        }

        if (!empty($insufficientResources)) {
            $text .= "\nДля крафта робота тебе недостает:\n\n";
            $text .= implode("\n", $insufficientResources);
            $text .= "\n__Вы не можете крафтить, так как у вас недостаточно ресурсов для крафта этого предмета.__\n";
        } else {
            // Вывод списка ресурсов и компонентов, которые будут потрачены
            $text .= "\n*Для крафта робота потребуется:*\n\n";

            foreach ($requiredResources as $resourceName => $requiredAmount) {
                $text .= "📦 {$resourceName} - {$requiredAmount}\n";
            }

            foreach ($requiredComponents as $componentName => $requiredAmount) {
                $text .= "📦 {$componentName} - {$requiredAmount}\n";
            }

            $text .= "💰 Золото - {$requiredGold} ед.\n\n";

            $text .= "Крафт займет ~45 минут.\n";
        }

        if (!empty($insufficientResources)) {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ],
                    [
                        ['text' => '💰 Продать', 'callback_data' => 'sell'],
                        ['text' => '🛍️ Купить', 'callback_data' => 'buy']
                    ],
                ]
            ];
        } else {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🛠️ Крафт', 'callback_data' => 'craftRobotExplorer2'],
                    ],
                    [
                        ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ],
                ]
            ];
        }

        $imagePath = base_url('uploads/telegram/craft/standard/robot_explorer.jpg');

        // Проверка наличия изображения
        if (!file_exists($imagePath)) {
            $imagePath = base_url('uploads/telegram/craft/standard/robot_explorer.jpg'); // Укажите путь к изображению по умолчанию
        }

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendPhoto([
            'chat_id' => $chatId,
            'photo' => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    private function checkResourcesAvailability($characterId, $requiredResources)
    {
        $results = [];
        foreach ($requiredResources as $name => $amount) {
            $resource = $this->resourceModel->getResourceByName($name);
            if ($resource) {
                $characterResource = $this->characterResourceModel->getResourceByNameAndCharacterId($name, $characterId);
                $results[$name] = [
                    'name' => $name,
                    'quantity' => $characterResource ? $characterResource['quantity'] : 0,
                ];
            } else {
                $results[$name] = [
                    'name' => $name,
                    'quantity' => 0,
                ];
            }
        }
        return $results;
    }

    private function checkCraftedItemAvailability($characterId, $requiredResources)
    {
        $results = [];
        foreach ($requiredResources as $name => $amount) {
            $craftedItem = $this->craftedItemsModel->getCraftedItemByName($name);
            if ($craftedItem) {
                $characterCraftedItem = $this->craftedItemsLogModel->where('crafted_item_id', $craftedItem['id'])
                    ->where('character_id', $characterId)
                    ->first();
                $results[$name] = [
                    'name' => $name,
                    'quantity' => $characterCraftedItem ? $characterCraftedItem['quantity'] : 0,
                ];
            } else {
                $results[$name] = [
                    'name' => $name,
                    'quantity' => 0,
                ];
            }
        }
        return $results;
    }

    private function areAllResourcesSufficient($resourcesAvailable, $requiredResources)
    {
        foreach ($requiredResources as $name => $requiredAmount) {
            if (!isset($resourcesAvailable[$name]) || $resourcesAvailable[$name]['quantity'] < $requiredAmount) {
                return false;
            }
        }
        return true;
    }

    private function checkBaseAvailability($character)
    {
        return $this->claimedCellModel->where('character_id', $character['id'])->first();
    }

    private function sendInsufficientResponse($chatId, $message)
    {
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                ],
                [
                    ['text' => '💰 Продать', 'callback_data' => 'sell'],
                    ['text' => '🛍️ Купить', 'callback_data' => 'buy']
                ],
            ]
        ];

        $imagePath = base_url('uploads/telegram/craft/standard/robot_explorer.jpg');

        // Проверка наличия изображения
        if (!file_exists($imagePath)) {
            $imagePath = base_url('uploads/telegram/craft/standard/robot_explorer.jpg'); // Укажите путь к изображению по умолчанию
        }

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendPhoto([
            'chat_id' => $chatId,
            'photo' => Request::encodeFile($imagePath),
            'caption' => $message,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
