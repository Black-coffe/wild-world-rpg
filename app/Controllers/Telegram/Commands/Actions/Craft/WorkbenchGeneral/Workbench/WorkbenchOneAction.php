<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Workbench;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use App\Models\CharacterModel;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\ClaimedCellModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class WorkbenchOneAction extends BaseAction
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

        // Проверка активного переезда (BaseRelocation)
        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $this->callbackQuery->getMessage()->getChat()->getId()
        )) {
            return Request::emptyResponse(); // Переезд есть, сервис уже отписался
        }

        $characterId = $character['id'];

        $requiredResources = [
            'Древесина' => 240,
            'Смола деревьев' => 40,
            'Базальт' => 20,
            'Лавовый камень' => 10,
        ];

        $requiredComponents = [
            'Каменные блоки' => 14,
            'Материал из древесины' => 24,
            'Металл фрагменты' => 20,
        ];

        $requiredGold = 20000;

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

        $text = "*🔬 Верстак 1!*\n\n"
            . "*Описание:* Верстак предназначен для крафта различных предметов и компонентов.\n";

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
            $text .= "\nДля крафта верстака тебе недостает:\n\n";
            $text .= implode("\n", $insufficientResources);
            $text .= "\n__Вы не можете крафтить, так как у вас недостаточно ресурсов для крафта этого предмета.__\n";
        } else {
            // Вывод списка ресурсов и компонентов, которые будут потрачены
            $text .= "\n*Для крафта верстака потребуется:*\n\n";

            foreach ($requiredResources as $resourceName => $requiredAmount) {
                $text .= "📦 {$resourceName} - {$requiredAmount}\n";
            }

            foreach ($requiredComponents as $componentName => $requiredAmount) {
                $text .= "📦 {$componentName} - {$requiredAmount}\n";
            }

            $text .= "💰 Золото - {$requiredGold} ед.\n\n";

            $text .= "Крафт займет 120 минут.\n";
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
                        ['text' => '🛠️ Крафтить', 'callback_data' => 'genericCraft_WorkbenchOne_1'],
                    ],
                    [
                        ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ],
                ]
            ];
        }

        $imagePath = base_url('uploads/telegram/workbench/workbench_one.png');

        // Проверка наличия изображения
        if (!file_exists($imagePath)) {
            $imagePath = base_url('uploads/telegram/workbench/workbench_one.png'); // Укажите путь к изображению по умолчанию
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

        $imagePath = base_url('uploads/telegram/workbench/workbench_one.png');

        // Проверка наличия изображения
        if (!file_exists($imagePath)) {
            $imagePath = base_url('uploads/telegram/workbench/workbench_one.png'); // Укажите путь к изображению по умолчанию
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
