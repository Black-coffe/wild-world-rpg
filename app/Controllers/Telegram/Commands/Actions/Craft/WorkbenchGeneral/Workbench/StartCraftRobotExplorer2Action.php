<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Workbench;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterResourceModel;
use App\Models\CharacterTaskModel;
use App\Models\ResourceModel;
use App\Models\CharacterModel;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\ClaimedCellModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class StartCraftRobotExplorer2Action extends BaseAction
{
    protected $characterResourceModel;
    protected $resourceModel;
    protected $characterModel;
    protected $buildingModel;
    protected $characterBuildingModel;
    protected $claimedCellModel;
    protected $craftedItemsLogModel;
    protected $craftedItemsModel;
    protected $characterTaskModel;

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
        $this->characterTaskModel = new CharacterTaskModel();
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
            $text .= "\nКрафт займет ~45 минут.\n";
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
            return $this->startCraftingProcess($character, $user['id'], $requiredResources, $requiredComponents, $requiredGold);
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

    private function startCraftingProcess($character, $userId, $requiredResources, $requiredComponents, $requiredGold): ServerResponse
    {
        $craftTask = $this->taskModel->where('name', 'craftRobotExplorer')->first();

        if (!$craftTask) {
            return $this->sendError('Задача "Крафт 🔍 Исследователя" не найдена в базе данных.');
        }

        $activeTask = $this->characterTaskModel->where([
            'character_id' => $character['id'],
            'task_id' => $craftTask['id'],
            'status' => 'in_work'
        ])->first();


        if ($activeTask) {
            return $this->sendError("Извини, но ты не можешь выполнять несколько одинаковых задач одновременно. Пожалуйста, дождись завершения текущего крафта.");
        }

        $duration = 120; // Время крафта в минутах

        $startTime = new \DateTime();
        $endTime = (clone $startTime)->add(new \DateInterval('PT' . $duration . 'M'));

        $status_save = $this->characterTaskModel->save([
            'character_id' => $character['id'],
            'telegram_user_id' => $userId,
            'task_id' => $craftTask['id'],
            'start_time' => $startTime->format('Y-m-d H:i:s'),
            'end_time' => $endTime->format('Y-m-d H:i:s'),
            'status' => 'in_work',
        ]);

        // Списать все, что было потрачено на крафт верстака
        // Списание ресурсов
        foreach ($requiredResources as $resourceName => $requiredAmount) {
            $resource = $this->resourceModel->getResourceByName($resourceName);
            if ($resource) {
                $this->characterResourceModel->where('id_characters', $character['id'])
                    ->where('id_resources', $resource['id'])
                    ->decrement('quantity', $requiredAmount);
            }
        }

        // Списание компонентов
        foreach ($requiredComponents as $componentName => $requiredAmount) {
            $component = $this->craftedItemsModel->getCraftedItemByName($componentName);
            if ($component) {
                $this->craftedItemsLogModel->where('character_id', $character['id'])
                    ->where('crafted_item_id', $component['id'])
                    ->decrement('quantity', $requiredAmount);
            }
        }

        // Списание золота
        $this->characterModel->where('id', $character['id'])->decrement('gold', $requiredGold);

        return $this->notifyCraftStarted($character, $startTime, $endTime);
    }

    private function notifyCraftStarted($character, $startTime, $endTime): ServerResponse
    {
        $interval = $startTime->diff($endTime);
        $minutes = $interval->days * 1440 + $interval->h * 60 + $interval->i;

        $text = "*Процесс крафта запущен*\n\n"
            . "*Ты создаешь: *робота 🔍 Исследователя!*\n\n"
            . "__*Время крафта: " . $minutes . " минут.*__ ⏱️\n\n"
            . "*О готовности ты узнаешь в сообщении.* 🎁\n\n"
            . "P.S. _Не забудь поделиться своими достижениями!_ 🗣️\n";

        $imagePath = base_url('uploads/telegram/craft/standard/standard_craft_area.jpg');
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendPhoto([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'   => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
        ]);
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
