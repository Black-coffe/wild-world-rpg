<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\ClaimedCellModel;
use App\Models\CharacterModel;
use App\Models\BuildingModel;
use App\Models\ResourceModel;
use App\Models\CharacterResourceModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\EventModel;
use App\Models\TaskModel;
use App\Models\CharacterBuildingModel;
use App\Models\ActiveEventModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use App\Services\Tasks\ActiveTasksService;

/**
 * Класс, позволяющий игроку увидеть требования к постройке "Центра телепортации"
 * и при наличии всех условий показать кнопку "Строить".
 */
class BuildTeleportationCenterConstruction extends BaseAction
{
    protected $claimedCellModel;
    protected $characterModel;
    protected $buildingModel;
    protected $resourceModel;
    protected $characterResourceModel;
    protected $craftedItemsModel;
    protected $craftedItemsLogModel;
    protected $characterBuildingModel;
    protected $activeEventModel;
    protected $eventModel;
    protected $taskModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);

        $this->claimedCellModel       = new ClaimedCellModel();
        $this->characterModel         = new CharacterModel();
        $this->buildingModel          = new BuildingModel();
        $this->resourceModel          = new ResourceModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->craftedItemsModel      = new CraftedItemsModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->activeEventModel       = new ActiveEventModel();
        $this->eventModel             = new EventModel();
        $this->taskModel              = new TaskModel();
    }

    public function handle(): ServerResponse
    {
        // 1) Убираем "часики" на inline-кнопке
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // 2) Получаем user/character
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Ошибка: не найден пользователь или персонаж.'
            ]);
        }

        // 3) Проверяем активность переезда
        $activeTasksService = new ActiveTasksService();
        $blocked = $activeTasksService->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $this->callbackQuery->getMessage()->getChat()->getId()
        );
        if ($blocked) {
            // Если переезд активен, сервис уже отправил сообщение и блокирует дальнейшие действия
            return Request::emptyResponse();
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

            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => "У вас нет лагеря. Разбейте лагерь, чтобы продолжить.",
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        }

        // 2. Проверка нахождения персонажа в лагере
        $currentCell = $character['cell_number'];
        $campCell    = $claimedCells[0]['map_cell_id']; // Предположим, что у игрока только один лагерь
        if ($currentCell != $campCell) {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '📡 Телепорт', 'callback_data' => 'TeleportToCamp'],
                        ['text' => '🚜 Переехать', 'callback_data' => 'move'],
                    ],
                ]
            ];

            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => "Вы не в лагере. Переместитесь в лагерь, чтобы построить *Центр телепортации*.",
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        }

        // 3. Проверка уровня для "TeleportationCenter"
        //    Пусть в таблице `buildings` "name_en = 'TeleportationCenter'"
        $building = $this->buildingModel->where('name_en', 'TeleportationCenter')->first();
        if (!$building) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => "Ошибка: не найдено здание с name_en='TeleportationCenter'.",
            ]);
        }

        if ($character['level'] < $building['min_character_level']) {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🏠 База', 'callback_data' => 'Base'],
                        ['text' => '👤 Персонаж', 'callback_data' => 'character']
                    ]
                ]
            ];

            return Request::sendMessage([
                'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'         => "Недостаточный уровень персонажа для постройки *Центра телепортации* (требуется как минимум {$building['min_character_level']} ур.).",
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        }

        // 4. Проверка ресурсов: у нас есть поле `required_resources` (JSON)
        //    В примере добавим условные требования (если у вас уже есть JSON в таблице "buildings", можно парсить оттуда)
        $requiredRes = json_decode($building['required_resources'], true);
        if (!is_array($requiredRes)) {
            $requiredRes = [];
        }

        // 5. Проверка крафтовых предметов (если нужно)
        //    Допустим, для "Телепорт-центра" нам нужны некие особые предметы
        $requiredCraftedItems = [
            // 'TeleporterModule' => 1,
            // 'EnergyCrystal' => 5,
        ];

        // 6. Проверяем, есть ли достаточно ресурсов и предметов
        $missingResources     = $this->checkResources($character['id'], $requiredRes, $this->resourceModel, $this->characterResourceModel);
        $missingCraftedItems  = $this->checkCraftedItems($character['id'], $requiredCraftedItems, $this->craftedItemsModel, $this->craftedItemsLogModel);

        // Формируем inline-клавиатуру
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                ],
            ]
        ];

        // Если всё в порядке (нет недостающих ресурсов/предметов) — добавляем кнопку «Строить»
        if (empty($missingResources) && empty($missingCraftedItems)) {
            array_unshift($keyboard['inline_keyboard'], [
                ['text' => '🛠️ Построить', 'callback_data' => 'startBuildTeleportCenter']
            ]);
        }

        $text = "*🌀 Центр телепортации*\n\n"
            . "Уникальное здание, позволяющее:\n"
            . "- Снижать стоимость телепортов\n"
            . "- Увеличивать лимит маяков\n\n"
            . "Требуется уровень персонажа >= *{$building['min_character_level']}*\n\n"
            . $this->formatResourcesNeeded($requiredRes)
            . $this->formatCraftedItemsNeeded($requiredCraftedItems)
            . "\n_Время строительства_ (примерно): *{$building['construction_time']} мин.*\n"
            . "_Налог:_ *{$building['tax']}* золота в сутки\n";

        // Если есть недостающие ресурсы/предметы, добавим информацию
        if (!empty($missingResources) || !empty($missingCraftedItems)) {
            $text .= "\n*Недостающие ресурсы / предметы:*\n";
            if ($missingResources) {
                $text .= $this->formatMissingResources($missingResources);
            }
            if ($missingCraftedItems) {
                $text .= $this->formatMissingCraftedItems($missingCraftedItems);
            }
        }

        // Путь к картинке (замените на ваш актуальный)
        $imagePath = base_url('uploads/telegram/camp/teleport_center.png');

        return Request::sendPhoto([
            'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Проверяем ресурсы у игрока
     */
    private function checkResources($characterId, array $requiredRes, $resourceModel, $characterResourceModel): array
    {
        $missing = [];
        foreach ($requiredRes as $resName => $resNeeded) {
            // Предположим, в resourceModel есть метод getResourceByNameEn($resName) или аналог
            $resource = $resourceModel->getResourceByNameEn($resName);
            if ($resource) {
                $charRes = $characterResourceModel
                    ->where('id_characters', $characterId)
                    ->where('id_resources', $resource['id'])
                    ->first();
                $haveAmount = $charRes ? $charRes['quantity'] : 0;
                if ($haveAmount < $resNeeded) {
                    $missing[$resName] = [
                        'have' => $haveAmount,
                        'need' => $resNeeded,
                        'rusName' => $resource['name'] ?? $resName
                    ];
                }
            } else {
                $missing[$resName] = [
                    'have' => 0,
                    'need' => $resNeeded,
                    'rusName' => $resName
                ];
            }
        }
        return $missing;
    }

    /**
     * Проверяем крафтовые предметы
     */
    private function checkCraftedItems($characterId, array $requiredItems, $craftedItemsModel, $craftedItemsLogModel): array
    {
        $missing = [];
        foreach ($requiredItems as $itemCode => $itemNeeded) {
            // Предположим, в craftedItemsModel есть метод getRowByName($itemCode)
            $item = $craftedItemsModel->getRowByName($itemCode);
            if ($item) {
                $logRow = $craftedItemsLogModel->getItemByCraftedItemIdAndCharacterId($item['id'], $characterId);
                $haveAmount = $logRow ? $logRow['quantity'] : 0;
                if ($haveAmount < $itemNeeded) {
                    $missing[$itemCode] = [
                        'have' => $haveAmount,
                        'need' => $itemNeeded,
                        'rusName' => $item['name_rus'] ?? $itemCode
                    ];
                }
            } else {
                $missing[$itemCode] = [
                    'have' => 0,
                    'need' => $itemNeeded,
                    'rusName' => $itemCode
                ];
            }
        }
        return $missing;
    }

    private function formatResourcesNeeded(array $requiredRes): string
    {
        if (empty($requiredRes)) {
            return "_Ресурсы не требуются либо не указаны_\n";
        }
        $txt = "Требуемые ресурсы:\n";
        foreach ($requiredRes as $rName => $rNeed) {
            $txt .= "- {$rName}: {$rNeed}\n";
        }
        $txt .= "\n";
        return $txt;
    }

    private function formatCraftedItemsNeeded(array $requiredItems): string
    {
        if (empty($requiredItems)) {
            return "_Нет обязательных крафтовых предметов_\n";
        }
        $txt = "Требуемые крафтовые предметы:\n";
        foreach ($requiredItems as $iName => $iNeed) {
            $txt .= "- {$iName}: {$iNeed}\n";
        }
        $txt .= "\n";
        return $txt;
    }

    private function formatMissingResources(array $missing): string
    {
        $txt = "";
        foreach ($missing as $rName => $m) {
            $txt .= "- {$m['rusName']}: есть {$m['have']} / нужно {$m['need']}\n";
        }
        return $txt;
    }

    private function formatMissingCraftedItems(array $missing): string
    {
        $txt = "";
        foreach ($missing as $iName => $m) {
            $txt .= "- {$m['rusName']}: есть {$m['have']} / нужно {$m['need']}\n";
        }
        return $txt;
    }
}
