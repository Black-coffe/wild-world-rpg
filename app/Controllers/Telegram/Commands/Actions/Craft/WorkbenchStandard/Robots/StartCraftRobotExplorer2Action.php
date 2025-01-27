<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Robots;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
use App\Models\CharacterTaskModel;
use App\Models\ClaimedCellModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\ResourceModel;
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
            'Ткань'         => 12,
            'Металл фрагменты' => 36,
        ];

        $requiredGold = 15000;

        // 1) Проверка наличия построенной базы (лагеря)
        if (!$this->checkBaseAvailability($character)) {
            return $this->sendInsufficientResponse($chatId, 'У вас нет построенной базы (лагеря).');
        }

        // 2) Проверка наличия Мастерской робототехники у игрока
        if (!$this->checkRoboticsWorkshopAvailability($character)) {
            return $this->sendInsufficientResponse($chatId, 'У вас нет Мастерской робототехники. Постройте её, чтобы крафтить роботов!');
        }

        // 3) Проверка ресурсов, компонентов и золота
        $resourcesAvailable  = $this->checkResourcesAvailability($characterId, $requiredResources);
        $componentsAvailable = $this->checkCraftedItemAvailability($characterId, $requiredComponents);
        $goldAvailable       = $this->characterModel->where('id', $characterId)->first();
        $goldQuantity        = $goldAvailable ? $goldAvailable['gold'] : 0;

        $text = "*🔍 Исследователь!*\n\n"
            . "*Описание:* робот, который _изучает местность_, он будет искать и открывать новые локации.\n";

        $insufficientResources = [];

        // Проверяем недостающие ресурсы
        foreach ($requiredResources as $resourceName => $requiredAmount) {
            $availableAmount = $resourcesAvailable[$resourceName]['quantity'] ?? 0;
            if ($availableAmount < $requiredAmount) {
                $insufficientResources[] = "📦 {$resourceName} - {$availableAmount} есть, нужно {$requiredAmount}\n";
            }
        }

        // Проверяем недостающие компоненты
        foreach ($requiredComponents as $componentName => $requiredAmount) {
            $availableAmount = $componentsAvailable[$componentName]['quantity'] ?? 0;
            if ($availableAmount < $requiredAmount) {
                $insufficientResources[] = "📦 {$componentName} - {$availableAmount} есть, нужно {$requiredAmount}\n";
            }
        }

        // Проверяем золото
        if ($goldQuantity < $requiredGold) {
            $insufficientResources[] = "💰 Золото - {$goldQuantity} есть, нужно {$requiredGold} ед.\n";
        }

        // Формируем текст ответа
        if (!empty($insufficientResources)) {
            $text .= "\nДля крафта робота тебе недостает:\n\n";
            $text .= implode("\n", $insufficientResources);
            $text .= "\n__Вы не можете крафтить, так как у вас недостаточно ресурсов для крафта этого предмета.__\n";
        } else {
            $text .= "\nКрафт займет ~45 минут.\n";
        }

        // Выбираем, какое сообщение/кнопки показать
        if (!empty($insufficientResources)) {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                        ['text' => '💰 Продать', 'callback_data' => 'sell'],
                        ['text' => '🛍️ Купить', 'callback_data' => 'buy']
                    ],
                ]
            ];
        } else {
            // Если ресурсов хватает, запускаем процесс крафта
            return $this->startCraftingProcess($character, $user['id'], $requiredResources, $requiredComponents, $requiredGold);
        }

        $imagePath = base_url('uploads/telegram/workbench/workbench_one.png');
        if (!file_exists($imagePath)) {
            $imagePath = base_url('uploads/telegram/workbench/workbench_one.png');
        }

        // Чтобы убрать "часики" у кнопки
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendPhoto([
            'chat_id' => $chatId,
            'photo' => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Проверяет, есть ли у персонажа база (лагерь).
     */
    private function checkBaseAvailability($character)
    {
        return $this->claimedCellModel
            ->where('character_id', $character['id'])
            ->first();
    }

    /**
     * Проверяет, есть ли у персонажа мастерская робототехники (RoboticsWorkshop).
     */
    private function checkRoboticsWorkshopAvailability($character): bool
    {
        // 1) Получаем здание RoboticsWorkshop
        $robWorkshop = $this->buildingModel->where('name_en', 'RoboticsWorkshop')->first();
        if (!$robWorkshop) {
            // Если по каким-то причинам нет такой записи в БД, считаем что её нет
            return false;
        }

        // 2) Ищем запись в character_buildings
        $hasWorkshop = $this->characterBuildingModel
            ->where('character_id', $character['id'])
            ->where('building_id', $robWorkshop['id'])
            ->first();

        return (bool) $hasWorkshop; // true, если запись нашлась, иначе false
    }

    /**
     * Универсальный метод для вывода "не хватает ресурсов" или "нет мастерской" и т.п.
     */
    private function sendInsufficientResponse($chatId, $message)
    {
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ['text' => '💰 Продать', 'callback_data' => 'sell'],
                    ['text' => '🛍️ Купить', 'callback_data' => 'buy']
                ],
            ]
        ];

        $imagePath = base_url('uploads/telegram/craft/standard/robot_explorer.jpg');
        if (!file_exists($imagePath)) {
            $imagePath = base_url('uploads/telegram/craft/standard/robot_explorer.jpg');
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

    /**
     * Проверяет наличие нужных ресурсов (ResourceModel).
     */
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

    /**
     * Проверяет наличие нужных компонентов (CraftedItemsModel / CraftedItemsLogModel).
     */
    private function checkCraftedItemAvailability($characterId, $requiredResources)
    {
        $results = [];
        foreach ($requiredResources as $name => $amount) {
            $craftedItem = $this->craftedItemsModel->getCraftedItemByName($name);
            if ($craftedItem) {
                $characterCraftedItem = $this->craftedItemsLogModel
                    ->where('crafted_item_id', $craftedItem['id'])
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

    /**
     * Запускает процесс крафта, создаёт задачу в БД.
     */
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
            return $this->sendError("Извини, но ты не можешь выполнять несколько одинаковых задач одновременно. Дождись завершения текущего крафта.");
        }

        // Пример: 45 минут на крафт
        $duration = 45;
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

        // Списываем ресурсы
        foreach ($requiredResources as $resourceName => $requiredAmount) {
            $resource = $this->resourceModel->getResourceByName($resourceName);
            if ($resource) {
                $this->characterResourceModel
                    ->where('id_characters', $character['id'])
                    ->where('id_resources', $resource['id'])
                    ->decrement('quantity', $requiredAmount);
            }
        }

        // Списываем компоненты
        foreach ($requiredComponents as $componentName => $requiredAmount) {
            $component = $this->craftedItemsModel->getCraftedItemByName($componentName);
            if ($component) {
                $this->craftedItemsLogModel
                    ->where('character_id', $character['id'])
                    ->where('crafted_item_id', $component['id'])
                    ->decrement('quantity', $requiredAmount);
            }
        }

        // Списываем золото
        $this->characterModel
            ->where('id', $character['id'])
            ->decrement('gold', $requiredGold);

        return $this->notifyCraftStarted($character, $startTime, $endTime);
    }

    /**
     * Уведомляем пользователя о старте крафта.
     */
    private function notifyCraftStarted($character, $startTime, $endTime): ServerResponse
    {
        $interval = $startTime->diff($endTime);
        $minutes = $interval->days * 1440 + $interval->h * 60 + $interval->i;

        $text = "*Процесс крафта запущен*\n\n"
            . "Ты создаёшь: *робота 🔍 Исследователя!*\n\n"
            . "__*Время крафта: " . $minutes . " минут.*__ ⏱️\n\n"
            . "*О готовности будет сообщение.* 🎁\n\n"
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

    /**
     * Обработка ошибок.
     */
    private function sendError($message): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text' => $message,
        ]);
    }
}
