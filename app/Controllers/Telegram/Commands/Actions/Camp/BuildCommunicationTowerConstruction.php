<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\ActiveEventModel;
use App\Models\ClaimedCellModel;
use App\Models\CharacterModel;
use App\Models\BuildingModel;
use App\Models\ResourceModel;
use App\Models\CharacterResourceModel;
use App\Models\TaskModel;
use App\Models\EventModel;
use App\Models\CraftedItemsModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CharacterBuildingModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use App\Services\Tasks\ActiveTasksService;

/**
 * Класс для «запроса постройки» Вышки связи (CommunicationTower).
 * Аналогичен BuildArsenalConstruction, но с другими требованиями.
 */
class BuildCommunicationTowerConstruction extends BaseAction
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

        $this->claimedCellModel       = new ClaimedCellModel();
        $this->characterModel         = new CharacterModel();
        $this->buildingModel          = new BuildingModel();
        $this->resourceModel          = new ResourceModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->taskModel              = new TaskModel();
        $this->eventModel             = new EventModel();
        $this->craftedItemsModel      = new CraftedItemsModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();
        $this->activeEventModel       = new ActiveEventModel();
        $this->characterBuildingModel = new CharacterBuildingModel();
    }

    public function handle(): ServerResponse
    {
        // 1) Убираем «часики»
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // 2) Получаем user/character
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Ошибка: нет пользователя или персонажа.'
            ]);
        }

        // 3) Проверяем «переезд»
        $activeTasksService = new ActiveTasksService();
        $blocked = $activeTasksService->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $this->callbackQuery->getMessage()->getChat()->getId()
        );
        if ($blocked) {
            return Request::emptyResponse();
        }

        // 4) Проверяем базу (лагерь)
        $claimedCells = $this->claimedCellModel
            ->where('character_id', $character['id'])
            ->findAll();
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
                'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'         => "У вас нет лагеря. Сначала разверните лагерь, чтобы строить здания.",
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        }

        // 5) Находимся ли мы в своём лагере
        $characterRefreshed = $this->characterModel->find($character['id']);
        $currentCell        = $characterRefreshed['cell_number'];
        $campCell           = $claimedCells[0]['map_cell_id'];

        if ($currentCell != $campCell) {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '📡 Телепорт',       'callback_data' => 'TeleportToCamp'],
                        ['text' => '🏃‍♂️ Переместиться', 'callback_data' => 'move'],
                    ],
                ]
            ];

            return Request::sendMessage([
                'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'         => "Вы не в базе. Вернитесь в лагерь, чтобы начать строительство *Вышки связи*!",
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        }

        // 6) Проверяем/создаём запись CommunicationTower
        $commTower = $this->ensureCommunicationTowerExists();
        if (!$commTower) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => "Ошибка: не удалось получить/создать здание «Вышка связи»."
            ]);
        }

        // 7) Проверка уровня
        $minLevel = $commTower['min_character_level'] ?? 10;
        if ($characterRefreshed['level'] < $minLevel) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => "Ваш уровень слишком низкий для строительства «📢 Вышка связи». Нужно хотя бы {$minLevel}-й уровень!",
                'parse_mode' => 'Markdown'
            ]);
        }

        // 8) Проверяем зависимые постройки
        // Предположим, нужна Солнечная станция (SolarStation), Лаборатория (Laboratory), Мастерская (Workshop)
        $requiredBuildingEn = ['SolarStation', 'Laboratory', 'Workshop'];
        $needBuildings = [];
        foreach ($requiredBuildingEn as $bname) {
            $bRow = $this->buildingModel->where('name_en', $bname)->first();
            if (!$bRow) {
                $needBuildings[] = $bname." (нет в DB)";
                continue;
            }
            $cb = $this->characterBuildingModel
                ->where('character_id', $character['id'])
                ->where('building_id', $bRow['id'])
                ->first();
            if (!$cb) {
                $needBuildings[] = $bRow['name_ru'] ?? $bname;
            }
        }

        $missingBuildingsText = "";
        if (!empty($needBuildings)) {
            $missingBuildingsText = "*Для строительства Вышки связи требуются постройки:*\n";
            foreach ($needBuildings as $mb) {
                $missingBuildingsText .= "- {$mb}\n";
            }
        }

        // 9) Выбираем ресурсы (пример)
        $requiredResources = [
            'Ironstone'  => 100,   // Железная руда
            'RareMetals' => 20,    // Редкие металлы
            'Oil'        => 30,    // Нефть
            'Sulfur'     => 15,    // Сера
        ];

        // 10) Выбираем крафтовые предметы (пример)
        $requiredCraftedItems = [
            'metalFragments'       => 100,
            'electronicComponents' => 12,
            'wiring'               => 12,
        ];

        // 11) Проверяем недостающие ресурсы
        $missingResources = $this->checkResources(
            $character['id'],
            $requiredResources,
            $this->resourceModel,
            $this->characterResourceModel
        );

        // 12) Проверяем недостающие предметы
        $missingCraftedItems = $this->checkCraftedItems(
            $character['id'],
            $requiredCraftedItems,
            $this->craftedItemsModel,
            $this->craftedItemsLogModel
        );

        // 13) Формируем кнопки
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎒 Инвентарь',     'callback_data' => 'inventory']
                ]
            ]
        ];
        // Если всё в порядке
        if (empty($needBuildings)
            && empty($missingResources)
            && empty($missingCraftedItems)
        ) {
            array_unshift($keyboard['inline_keyboard'], [
                ['text' => '🛠️ Построить Вышку связи', 'callback_data' => 'genericStartBuild_CommunicationTower']
            ]);
        }

        // 14) Текст описания
        $text = "*📢 Вышка связи*\n"
            . "Позволяет удалённо управлять базой и сооружениями.\n"
            . "Каждый уровень увеличивает радиус действия на 100 клеток.\n\n"
            . "Требуемый уровень игрока: *{$minLevel}*\n";

        if ($missingBuildingsText) {
            $text .= $missingBuildingsText . "\n";
        }

        $text .= "\n*Необходимые ресурсы:*\n";
        $text .= $this->formatResourcesForText($requiredResources, $this->resourceModel, $character['id']);

        $text .= "\n*Необходимые предметы:*\n";
        $text .= $this->formatCraftedItemsForText($requiredCraftedItems, $this->craftedItemsModel, $character['id']);

        // Уточняем, чего не хватает
        if (!empty($missingResources) || !empty($missingCraftedItems)) {
            $text .= "\n_У вас не хватает следующих материалов:_\n";

            $missingResText   = $this->getMissingResourcesText($missingResources, $this->resourceModel);
            $missingItemsText = $this->getMissingCraftedItemsText($missingCraftedItems, $this->craftedItemsModel);

            if ($missingResText) {
                $text .= "\n*Ресурсы:* \n" . $missingResText;
            }
            if ($missingItemsText) {
                $text .= "\n*Крафтовые предметы:* \n" . $missingItemsText;
            }
        }

        $imagePath = base_url('uploads/telegram/camp/communication_tower.png');

        return Request::sendPhoto([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Если нет записи "CommunicationTower" в buildings, создаём.
     */
    private function ensureCommunicationTowerExists()
    {
        $row = $this->buildingModel->where('name_en', 'CommunicationTower')->first();
        if (!$row) {
            $data = [
                'name_ru'               => 'Вышка связи',
                'name_en'               => 'CommunicationTower',
                'description'           => 'Позволяет удалённо управлять базой, чем выше уровень — тем дальше радиус связи.',
                'building_type'         => 'engineering',
                'hp'                    => 200,
                'construction_time'     => 180,   // в минутах
                'tax'                   => 1300,
                'level'                 => 1,
                'usage'                 => 'all',
                'min_character_level'   => 10,
                'required_resources'    => '{}',
                'effects'               => 'Communication range: +100 cells per building level.',
                'days_until_disappearance' => 0,
                'usage_count'           => null
            ];
            $this->buildingModel->insert($data);
            $row = $this->buildingModel->where('name_en', 'CommunicationTower')->first();
        }
        return $row;
    }

    // --- Ниже те же методы checkResources(), checkCraftedItems() и т.д. ---
    // (Можно использовать «как есть», скопировав из класса BuildArsenalConstruction)
    private function checkResources($characterId, $requiredResources, $resourceModel, $characterResourcesModel)
    {
        $missing = [];
        foreach ($requiredResources as $resourceNameEn => $requiredAmt) {
            $resRow = $resourceModel->getResourceByNameEn($resourceNameEn);
            if ($resRow) {
                $charRes = $characterResourcesModel
                    ->where('id_characters', $characterId)
                    ->where('id_resources', $resRow['id'])
                    ->first();
                $have = $charRes ? $charRes['quantity'] : 0;
                if ($have < $requiredAmt) {
                    $missing[$resourceNameEn] = [
                        'required'  => $requiredAmt,
                        'available' => $have,
                        'name_rus'  => $resRow['name'] ?? $resourceNameEn,
                    ];
                }
            } else {
                $missing[$resourceNameEn] = [
                    'required'  => $requiredAmt,
                    'available' => 0,
                    'name_rus'  => $resourceNameEn.' (нет в DB)',
                ];
            }
        }
        return $missing;
    }

    private function checkCraftedItems($characterId, $requiredItems, $craftedItemsModel, $craftedItemsLogModel)
    {
        $missing = [];
        foreach ($requiredItems as $itemNameEn => $reqAmt) {
            $itemRow = $craftedItemsModel->getRowByName($itemNameEn);
            if ($itemRow) {
                $logRow = $craftedItemsLogModel
                    ->where('crafted_item_id', $itemRow['id'])
                    ->where('character_id', $characterId)
                    ->first();
                $have = $logRow ? $logRow['quantity'] : 0;
                if ($have < $reqAmt) {
                    $missing[$itemNameEn] = [
                        'required'  => $reqAmt,
                        'available' => $have,
                        'name_rus'  => $itemRow['name_rus'] ?? $itemNameEn,
                    ];
                }
            } else {
                $missing[$itemNameEn] = [
                    'required'  => $reqAmt,
                    'available' => 0,
                    'name_rus'  => $itemNameEn.' (нет в DB)',
                ];
            }
        }
        return $missing;
    }

    private function formatResourcesForText($requiredResources, $resourceModel, $characterId)
    {
        $text = "";
        foreach ($requiredResources as $resNameEn => $reqAmount) {
            $r = $resourceModel->getResourceByNameEn($resNameEn);
            if ($r) {
                $charRes = $this->characterResourceModel
                    ->where('id_characters', $characterId)
                    ->where('id_resources', $r['id'])
                    ->first();
                $have = $charRes ? $charRes['quantity'] : 0;
                $text .= "- {$r['name']} (требуется {$reqAmount}, у вас {$have})\n";
            } else {
                $text .= "- {$resNameEn} (нет в DB) (требуется {$reqAmount}, у вас 0)\n";
            }
        }
        return $text;
    }

    private function formatCraftedItemsForText($requiredItems, $craftedItemsModel, $characterId)
    {
        $text = "";
        foreach ($requiredItems as $itemNameEn => $reqAmount) {
            $row = $craftedItemsModel->getRowByName($itemNameEn);
            if ($row) {
                $logRow = $this->craftedItemsLogModel
                    ->where('crafted_item_id', $row['id'])
                    ->where('character_id', $characterId)
                    ->first();
                $have = $logRow ? $logRow['quantity'] : 0;

                $text .= "- {$row['name_rus']} (нужно {$reqAmount}, у вас {$have})\n";
            } else {
                $text .= "- {$itemNameEn} (нет в DB) (нужно {$reqAmount}, у вас 0)\n";
            }
        }
        return $text;
    }

    private function getMissingResourcesText(array $missingResources, $resourceModel)
    {
        $text = "";
        foreach ($missingResources as $resNameEn => $data) {
            $text .= "- {$data['name_rus']}: требуется {$data['required']}, у вас {$data['available']}\n";
        }
        return $text;
    }

    private function getMissingCraftedItemsText(array $missingCraftedItems, $craftedItemsModel)
    {
        $text = "";
        foreach ($missingCraftedItems as $itemNameEn => $data) {
            $text .= "- {$data['name_rus']}: требуется {$data['required']}, у вас {$data['available']}\n";
        }
        return $text;
    }
}
