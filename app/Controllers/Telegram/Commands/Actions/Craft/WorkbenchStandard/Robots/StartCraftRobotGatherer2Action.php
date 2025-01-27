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
use App\Models\TaskModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class StartCraftRobotGatherer2Action extends BaseAction
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
    protected $taskModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterResourceModel   = new CharacterResourceModel();
        $this->resourceModel            = new ResourceModel();
        $this->characterModel           = new CharacterModel();
        $this->buildingModel            = new BuildingModel();
        $this->characterBuildingModel   = new CharacterBuildingModel();
        $this->claimedCellModel         = new ClaimedCellModel();
        $this->craftedItemsLogModel     = new CraftedItemsLogModel();
        $this->craftedItemsModel        = new CraftedItemsModel();
        $this->characterTaskModel       = new CharacterTaskModel();
        $this->taskModel                = new TaskModel();
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден в базе данных или персонаж не определён.',
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
            'Янтарь'           => 6,
            'Смола деревьев'   => 40,
            'Солнечные камни'  => 30,
        ];

        $requiredComponents = [
            'Стекло пакеты'     => 3,
            'Ткань'             => 15,
            'Металл фрагменты'  => 42,
        ];

        $requiredGold = 21000;

        // 1) Проверка наличия базы (лагеря)
        if (!$this->checkBaseAvailability($character)) {
            return $this->sendInsufficientResponse($chatId, 'У вас нет построенной базы (лагеря).');
        }

        // 2) Проверка наличия Мастерской робототехники (RoboticsWorkshop)
        if (!$this->checkRoboticsWorkshopAvailability($character)) {
            return $this->sendInsufficientResponse($chatId, 'У вас нет Мастерской робототехники. Постройте её, чтобы создавать роботов!');
        }

        // 3) Проверка наличия верстака (Workshop)
        $workbench = $this->buildingModel->where('name_en', 'Workshop')->first();
        $workshopExists = $this->characterBuildingModel
            ->where('character_id', $characterId)
            ->where('building_id', $workbench['id'])
            ->first();

        if (!$workshopExists) {
            return $this->sendInsufficientResponse($chatId, 'У вас нет необходимого верстака (Workshop).');
        }

        // 4) Проверяем ресурсы / компоненты / золото
        $resourcesAvailable  = $this->checkResourcesAvailability($characterId, $requiredResources);
        $componentsAvailable = $this->checkCraftedItemAvailability($characterId, $requiredComponents);
        $goldAvailable       = $this->characterModel->where('id', $characterId)->first();
        $goldQuantity        = $goldAvailable ? $goldAvailable['gold'] : 0;

        $text = "*⛏️ Добытчик!*\n\n"
            . "*Описание:* робот, который будет добывать ресурсы. Он может собирать разного уровня ресурсы и приносить их на базу.\n";

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

        // Формируем ответ
        if (!empty($insufficientResources)) {
            $text .= "\nДля крафта робота тебе недостает:\n\n";
            $text .= implode("\n", $insufficientResources);
            $text .= "\n__Вы не можете крафтить, так как у вас недостаточно ресурсов для крафта этого предмета.__\n";
        } else {
            // Если ресурсов хватает, выводим инфо и запускаем крафт
            $text .= "\n*Для крафта робота потребуется:*\n\n";
            foreach ($requiredResources as $rName => $rAmount) {
                $text .= "📦 {$rName} - {$rAmount}\n";
            }
            foreach ($requiredComponents as $cName => $cAmount) {
                $text .= "📦 {$cName} - {$cAmount}\n";
            }
            $text .= "💰 Золото - {$requiredGold} ед.\n\n";

            $text .= "Крафт займет ~" . $this->calculateCraftingDuration($character) . " минут.\n";

            return $this->startCraftingProcess($character, $user['id'], $requiredResources, $requiredComponents, $requiredGold);
        }

        // Если ресурсов не хватает, показываем кнопки возврата
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ['text' => '💰 Продать', 'callback_data' => 'sell'],
                    ['text' => '🛍️ Купить', 'callback_data' => 'buy']
                ],
            ]
        ];

        $imagePath = base_url('uploads/telegram/craft/standard/craftRobotGatherer.jpg');
        if (!file_exists($imagePath)) {
            $imagePath = base_url('uploads/telegram/craft/standard/craftRobotGatherer.jpg');
        }

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendPhoto([
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Проверяем, есть ли у персонажа база (лагерь).
     */
    private function checkBaseAvailability($character)
    {
        return $this->claimedCellModel->where('character_id', $character['id'])->first();
    }

    /**
     * Проверяем, есть ли у персонажа Мастерская робототехники (RoboticsWorkshop).
     */
    private function checkRoboticsWorkshopAvailability($character): bool
    {
        // Получаем здание RoboticsWorkshop
        $robWorkshop = $this->buildingModel->where('name_en', 'RoboticsWorkshop')->first();
        if (!$robWorkshop) {
            // Если нет записи в buildings, считаем, что её нет
            return false;
        }

        // Ищем запись в character_buildings
        $hasWorkshop = $this->characterBuildingModel
            ->where('character_id', $character['id'])
            ->where('building_id', $robWorkshop['id'])
            ->first();

        return (bool) $hasWorkshop;
    }

    private function checkResourcesAvailability($characterId, $requiredResources)
    {
        $results = [];
        foreach ($requiredResources as $name => $amount) {
            $resource = $this->resourceModel->getResourceByName($name);
            if ($resource) {
                $characterResource = $this->characterResourceModel
                    ->getResourceByNameAndCharacterId($name, $characterId);
                $results[$name] = [
                    'name'     => $name,
                    'quantity' => $characterResource ? $characterResource['quantity'] : 0,
                ];
            } else {
                $results[$name] = [
                    'name'     => $name,
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
                $characterCraftedItem = $this->craftedItemsLogModel
                    ->where('crafted_item_id', $craftedItem['id'])
                    ->where('character_id', $characterId)
                    ->first();
                $results[$name] = [
                    'name'     => $name,
                    'quantity' => $characterCraftedItem ? $characterCraftedItem['quantity'] : 0,
                ];
            } else {
                $results[$name] = [
                    'name'     => $name,
                    'quantity' => 0,
                ];
            }
        }
        return $results;
    }

    private function startCraftingProcess($character, $userId, $requiredResources, $requiredComponents, $requiredGold): ServerResponse
    {
        $craftTask = $this->taskModel->where('name', 'craftRobotGatherer')->first();
        if (!$craftTask) {
            return $this->sendError('Задача "Крафт ⛏️ Добытчика" не найдена в базе данных.');
        }

        $activeTask = $this->characterTaskModel->where([
            'character_id' => $character['id'],
            'task_id'      => $craftTask['id'],
            'status'       => 'in_work'
        ])->first();

        if ($activeTask) {
            return $this->sendError(
                "Извини, но ты не можешь выполнять несколько одинаковых задач одновременно. " .
                "Пожалуйста, дождись завершения текущего крафта."
            );
        }

        // Рассчитываем время крафта (см. метод ниже)
        $duration  = $this->calculateCraftingDuration($character);
        $startTime = new \DateTime();
        $endTime   = (clone $startTime)->add(new \DateInterval('PT' . $duration . 'M'));

        $this->characterTaskModel->save([
            'character_id'     => $character['id'],
            'telegram_user_id' => $userId,
            'task_id'          => $craftTask['id'],
            'start_time'       => $startTime->format('Y-m-d H:i:s'),
            'end_time'         => $endTime->format('Y-m-d H:i:s'),
            'status'           => 'in_work',
        ]);

        // Списание ресурсов
        foreach ($requiredResources as $resourceName => $requiredAmount) {
            $resource = $this->resourceModel->getResourceByName($resourceName);
            if ($resource) {
                $this->characterResourceModel
                    ->where('id_characters', $character['id'])
                    ->where('id_resources',   $resource['id'])
                    ->decrement('quantity',   $requiredAmount);
            }
        }

        // Списание компонентов
        foreach ($requiredComponents as $componentName => $requiredAmount) {
            $component = $this->craftedItemsModel->getCraftedItemByName($componentName);
            if ($component) {
                $this->craftedItemsLogModel
                    ->where('character_id',      $character['id'])
                    ->where('crafted_item_id',   $component['id'])
                    ->decrement('quantity',      $requiredAmount);
            }
        }

        // Списание золота
        $this->characterModel
            ->where('id', $character['id'])
            ->decrement('gold', $requiredGold);

        return $this->notifyCraftStarted($character, $startTime, $endTime);
    }

    private function notifyCraftStarted($character, $startTime, $endTime): ServerResponse
    {
        $interval = $startTime->diff($endTime);
        $minutes  = $interval->days * 1440 + $interval->h * 60 + $interval->i;

        $text = "*Процесс крафта запущен*\n\n"
            . "Ты создаёшь: *робота ⛏️ Добытчика!*\n\n"
            . "__*Время крафта: " . $minutes . " минут.*__ ⏱️\n\n"
            . "*О готовности ты узнаешь в сообщении.* 🎁\n\n"
            . "P.S. _Не забудь поделиться своими достижениями!_ 🗣️\n";

        $imagePath = base_url('uploads/telegram/craft/standard/standard_craft_area.jpg');
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendPhoto([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    private function calculateCraftingDuration($character)
    {
        // Пример расчёта времени крафта
        $level = $character['level'];

        // Весовой коэффициент уровня
        $levelFactor = 0.6;

        // Считаем "баллы" уровня
        $attributeScore      = $level * $levelFactor;
        $maxAttributeScore   = 100 * $levelFactor;
        $normalizedScore     = $attributeScore / $maxAttributeScore;

        $minDuration = 30; // минимум минут
        $maxDuration = 50; // максимум минут
        // Инвертируем зависимость: чем выше уровень, тем быстрее
        $adjustedDuration = $minDuration + ($maxDuration - $minDuration) * (1 - $normalizedScore);

        return max($minDuration, min($maxDuration, round($adjustedDuration)));
    }

    private function sendError($message): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'    => $message,
        ]);
    }

    private function sendInsufficientResponse($chatId, $message)
    {
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ['text' => '💰 Продать', 'callback_data' => 'sell'],
                    ['text' => '🛍️ Купить',  'callback_data' => 'buy']
                ],
            ]
        ];

        $imagePath = base_url('uploads/telegram/craft/standard/craftRobotGatherer.jpg');
        if (!file_exists($imagePath)) {
            $imagePath = base_url('uploads/telegram/craft/standard/craftRobotGatherer.jpg');
        }

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendPhoto([
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $message,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
