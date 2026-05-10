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
use App\Services\Tasks\ActiveTasksService;

class BuildLabConstruction extends BaseAction
{
    // $characterModel, $resourceModel, $taskModel — наследуются от BaseAction (untyped там)
    protected ClaimedCellModel $claimedCellModel;
    protected BuildingModel $buildingModel;
    protected CharacterResourceModel $characterResourceModel;
    protected EventModel $eventModel;
    protected CraftedItemsModel $craftedItemsModel;
    protected CraftedItemsLogModel $craftedItemsLogModel;
    protected ActiveEventModel $activeEventModel;
    protected CharacterBuildingModel $characterBuildingModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        // characterModel, resourceModel, taskModel инициализируются в parent::__construct
        $this->claimedCellModel = new ClaimedCellModel();
        $this->buildingModel = new BuildingModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->eventModel = new EventModel();
        $this->craftedItemsModel = new CraftedItemsModel();
        $this->craftedItemsLogModel = new CraftedItemsLogModel();
        $this->activeEventModel = new ActiveEventModel();
        $this->characterBuildingModel = new CharacterBuildingModel();
    }

    public function handle(): ServerResponse
    {
        // 1) answerCallbackQuery убираем "часики"
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // 2) Получаем user/character
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Ошибка: нет пользователя/персонажа.'
            ]);
        }

        // 3) Проверяем переезд
        $activeTasksService = new ActiveTasksService();
        $blocked = $activeTasksService->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $this->callbackQuery->getMessage()->getChat()->getId()
        );
        if ($blocked) {
            // true = переезд активен, сервис уже отправил сообщение.
            // Дальнейшая логика не идёт.
            return Request::emptyResponse();
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
        $building = $this->buildingModel->where('name_en', 'Laboratory')->first();
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
                'text'    => "Ваш уровень слишком низкий для строительства *🥼 Лаборатория*.",
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
            'Wood' => 10000,
        ];
        $requiredCraftedItems = [
            'GlassBags' => 50,
            'metalFragments' => 100,
            'stoneBlocks' => 24,
        ];

        $missingResources = $this->checkResources($character['id'], $requiredResources, $this->resourceModel, $this->characterResourceModel);
        $missingCraftedItems = $this->checkCraftedItems($character['id'], $requiredCraftedItems, $this->craftedItemsModel, $this->craftedItemsLogModel);

        // 6. Отображение информации о строительстве
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                ],
            ]
        ];

        if ($workshopExists && empty($missingResources) && empty($missingCraftedItems)) {
            array_unshift($keyboard['inline_keyboard'], [['text' => '🛠️ Строить', 'callback_data' => 'genericStartBuild_Laboratory']]);
        }

        $text = "*🥼 Лаборатория*\n"
            . "Тебе нужны:\n\n"
            . $this->formatResourcesForText($requiredResources, $this->resourceModel, $character['id'])
            . $this->formatCraftedItemsForText($requiredCraftedItems, $this->craftedItemsModel, $character['id'])
            . "\n*Стройка:* ~90 минут!\n"
            . "\n*Описание:* Позволяет проводить исследования и создавать новые технологии, а также строить роботов и прочие изделия.\n";

        if (!$workshopExists) {
            $text .= "\nДля строительства *🥼 Лаборатория* необходима *Мастерская*.\n";
        }

        if ($missingResources || $missingCraftedItems) {
            // Формируем текст сообщения о недостающих ресурсах и предметах
            $missingResourcesText = $this->getMissingResourcesText($missingResources, $this->resourceModel);
            $missingCraftedItemsText = $this->getMissingCraftedItemsText($missingCraftedItems, $this->craftedItemsModel);

            $text .= "\n*Нужно для строительства:*\n\n";
            if ($missingResourcesText) {
                $text .= "Ресурсы:\n" . $missingResourcesText . "\n";
            }
            if ($missingCraftedItemsText) {
                $text .= "Крафт предметы:\n" . $missingCraftedItemsText . "\n";
            }
        }

        $imagePath = base_url('uploads/telegram/camp/laboratory.jpg');
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo' => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * @param array<string, int> $requiredResources
     * @return array<string, array{required: int, available: int, name: string}>
     */
    private function checkResources(int $characterId, array $requiredResources, ResourceModel $resourceModel, CharacterResourceModel $characterResourcesModel): array
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

    /**
     * @param array<string, int> $requiredCraftedItems
     * @return array<string, array{required: int, available: int, name_rus: string}>
     */
    private function checkCraftedItems(int $characterId, array $requiredCraftedItems, CraftedItemsModel $craftedItemsModel, CraftedItemsLogModel $craftedItemsLogModel): array
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

    /**
     * @param array<string, array{required: int, available: int, name: string}> $missingResources
     */
    private function getMissingResourcesText(array $missingResources, ResourceModel $resourceModel): string
    {
        $text = "";
        foreach ($missingResources as $resourceName => $resourceInfo) {
            $text .= "- " . $resourceInfo['name'] . ": требуется " . $resourceInfo['required'] . ", в наличии " . $resourceInfo['available'] . "\n";
        }
        return $text;
    }

    /**
     * @param array<string, array{required: int, available: int, name_rus: string}> $missingCraftedItems
     */
    private function getMissingCraftedItemsText(array $missingCraftedItems, CraftedItemsModel $craftedItemsModel): string
    {
        $text = "";
        foreach ($missingCraftedItems as $itemName => $itemInfo) {
            $text .= "- " . $itemInfo['name_rus'] . ": требуется " . $itemInfo['required'] . ", в наличии " . $itemInfo['available'] . "\n";
        }
        return $text;
    }

    /**
     * @param array<string, int> $requiredResources
     */
    private function formatResourcesForText(array $requiredResources, ResourceModel $resourceModel, int $characterId): string
    {
        $text = "";
        foreach ($requiredResources as $resourceName => $requiredAmount) {
            $resource = $resourceModel->getResourceByNameEn($resourceName);
            if ($resource) {
                $characterResource = $this->characterResourceModel->where('id_characters', $characterId)
                    ->where('id_resources', $resource['id'])
                    ->first();
                $availableAmount = $characterResource ? $characterResource['quantity'] : 0;
                $text .= \App\Helpers\ResourceIconHelper::for($resource['name']) . " " . $resource['name'] . " - " . $requiredAmount . " ед. (в наличии " . $availableAmount . " ед.)\n";
            }
        }
        return $text;
    }

    /**
     * @param array<string, int> $requiredCraftedItems
     */
    private function formatCraftedItemsForText(array $requiredCraftedItems, CraftedItemsModel $craftedItemsModel, int $characterId): string
    {
        $text = "";
        foreach ($requiredCraftedItems as $itemName => $requiredAmount) {
            $item = $craftedItemsModel->getRowByName($itemName);
            if ($item) {
                $craftedItem = $this->craftedItemsLogModel->getItemByCraftedItemIdAndCharacterId($item['id'], $characterId);
                $availableAmount = $craftedItem ? $craftedItem['quantity'] : 0;
                $text .= \App\Helpers\ResourceIconHelper::for($item['name_rus']) . " " . $item['name_rus'] . " - " . $requiredAmount . " ед. (в наличии " . $availableAmount . " ед.)\n";
            }
        }
        return $text;
    }

    private function sendError(string $message): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text' => $message,
        ]);
    }
}

