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

class BuildArsenalConstruction extends BaseAction
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
        $this->claimedCellModel         = new ClaimedCellModel();
        $this->buildingModel            = new BuildingModel();
        $this->characterResourceModel   = new CharacterResourceModel();
        $this->eventModel               = new EventModel();
        $this->craftedItemsModel        = new CraftedItemsModel();
        $this->craftedItemsLogModel     = new CraftedItemsLogModel();
        $this->activeEventModel         = new ActiveEventModel();
        $this->characterBuildingModel   = new CharacterBuildingModel();
    }

    /**
     * Точка входа, вызываемая при нажатии inline-кнопки "⚔️ Арсенал" (callback_data = "actionNameForArsenal").
     */
    public function handle(): ServerResponse
    {
        // 1) Убираем "часики" на кнопке
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // 2) Получаем user/character
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Ошибка: нет пользователя/персонажа.'
            ]);
        }

        // 3) Проверяем, не идёт ли переезд (если да — блокируем строительство)
        $activeTasksService = new ActiveTasksService();
        $blocked = $activeTasksService->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $this->callbackQuery->getMessage()->getChat()->getId()
        );
        if ($blocked) {
            // Если переезд активен, checkRelocationAndBlock сам отправил сообщение
            return Request::emptyResponse();
        }

        // 4) Проверяем, есть ли у персонажа база/лагерь
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

        // 5) Проверяем, что персонаж сейчас в лагере (cell_number == map_cell_id лагеря)
        $characterRefreshed = $this->characterModel->find($character['id']);
        $currentCell        = $characterRefreshed['cell_number'];
        $campCell           = $claimedCells[0]['map_cell_id']; // если лагерь один

        if ($currentCell != $campCell) {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '📡 Телепорт', 'callback_data' => 'TeleportToCamp'],
                        ['text' => '🏃‍♂️ Переместиться', 'callback_data' => 'move'],
                    ],
                ]
            ];

            return Request::sendMessage([
                'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'         => "Вы находитесь не в базе. Вернитесь в лагерь, чтобы начать строительство *Арсенала*.",
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        }

        // 6) Убеждаемся, что в таблице buildings есть запись name_en='Arsenal'
        $arsenalBuilding = $this->ensureArsenalExists(); // метод ниже
        if (!$arsenalBuilding) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => "Ошибка: не удалось создать/получить запись 'Arsenal' в таблице `buildings`."
            ]);
        }

        // 7) Проверяем минимальный уровень (min_character_level)
        // Или используем поле 'level' если вы это так храните
        $minLevel = $arsenalBuilding['min_character_level'] ?? 10; // или $arsenalBuilding['level']
        if ($characterRefreshed['level'] < $minLevel) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => "Ваш уровень слишком низкий для строительства *⚔️ Арсенал*. Нужно хотя бы уровень: *{$minLevel}*",
                'parse_mode' => 'Markdown'
            ]);
        }

        // 8) Проверяем наличие зависимых построек:
        // Мастерская (Workshop), Доменная печь (BlastFurnace), Солнечная станция (SolarStation), Лаборатория (Laboratory)
        $requiredBuildingEn = ['Workshop','BlastFurnace','SolarStation','Laboratory'];
        $needBuildings = [];
        foreach ($requiredBuildingEn as $bname) {
            $bRow = $this->buildingModel->where('name_en', $bname)->first();
            if (!$bRow) {
                // Если вдруг нет записи в DB — можно обработать, но скорее это ошибка
                $needBuildings[] = $bname." (нет в DB)";
                continue;
            }

            // Ищем в character_buildings
            $cb = $this->characterBuildingModel
                ->where('character_id', $character['id'])
                ->where('building_id', $bRow['id'])
                ->first();

            if (!$cb) {
                // У игрока не построено
                $needBuildings[] = $bRow['name_ru'] ?? $bname;
            }
        }

        // Если какие-то из нужных зданий не найдены — говорим об этом
        $missingBuildingsText = "";
        if (!empty($needBuildings)) {
            $missingBuildingsText = "*Для строительства Арсенала требуются постройки:*\n";
            foreach ($needBuildings as $mb) {
                $missingBuildingsText .= "- {$mb}\n";
            }
        }

        // 9) Список необходимых ресурсов (пример)
        $requiredResources = [
            'Ironstone'    => 200,  // Железная руда
            'RareMetals'   => 60,
            'Oil'          => 70,
            'Sulfur'       => 50,
        ];

        // 10) Список необходимых крафтовых предметов
        $requiredCraftedItems = [
            'metalFragments'       => 120, // Металл фрагменты
            'wiring'               => 15,  // Проводка
            'electronicComponents' => 8,
        ];

        // 11) Проверяем недостающие ресурсы
        $missingResources = $this->checkResources(
            $character['id'],
            $requiredResources,
            $this->resourceModel,
            $this->characterResourceModel
        );

        // 12) Проверяем недостающие крафт-предметы
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
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory']
                ]
            ]
        ];

        // Только если ВСЁ в порядке (нет недостающих зданий, нет недостающих ресурсов/предметов)
        if (empty($needBuildings)
            && empty($missingResources)
            && empty($missingCraftedItems)
        ) {
            // F2.1 cutover (v0.3.0): callback_data маршрутизируется в
            // GenericBuildingAction, который читает рецепт из
            // app/Config/Buildings.php (см. CallbackqueryCommand mapping).
            array_unshift($keyboard['inline_keyboard'], [
                ['text' => '🛠️ Построить Арсенал', 'callback_data' => 'genericStartBuild_Arsenal']
            ]);
        }

        // 14) Текст описания:
        $text = "*⚔️ Арсенал*\n"
            . "Здесь вы сможете:\n"
            . "- Хранить и производить оружие/броню\n"
            . "- Улучшать, модифицировать, разрабатывать новые модели\n"
            . "- Получать бонусы при создании боеприпасов\n\n"
            . "Требуемый уровень игрока: *{$minLevel}*\n";

        // Если каких-то требуемых зданий нет
        if ($missingBuildingsText) {
            $text .= $missingBuildingsText . "\n";
        }

        // Список ресурсов и предметов
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
                $text .= "\n*Ресурсы:* \n".$missingResText;
            }
            if ($missingItemsText) {
                $text .= "\n*Крафтовые предметы:* \n".$missingItemsText;
            }
        }

        // Картинка (если есть)
        $imagePath = base_url('uploads/telegram/camp/arsenal.png');

        return Request::sendPhoto([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Метод, который проверяет, существует ли в таблице `buildings` запись с name_en='Arsenal'.
     * Если нет — создаём её (с базовыми полями).
     */
    /**
     * @return array<string, mixed>|null
     */
    private function ensureArsenalExists(): ?array
    {
        $arsenal = $this->buildingModel->where('name_en', 'Arsenal')->first();
        if (!$arsenal) {
            // Пытаемся создать
            $data = [
                'name_ru'               => 'Арсенал',
                'name_en'               => 'Arsenal',
                'description'           => 'Здание, позволяющее хранить/создавать оружие, броню и боеприпасы',
                'building_type'         => 'military',
                'hp'                    => 350,
                'construction_time'     => 240,    // например, 240 мин (4 часа)
                'tax'                   => 2000,   // налог
                'level'                 => 1,      // ваш уровень в вашей логике (1=начальный)
                'usage'                 => 'all',
                // Указываем поля min_character_level, required_resources и т.д.:
                'min_character_level'   => 15, // пусть будет 15
                'required_resources'    => '{}',
                'effects'               => 'Арсенал:production_of_weapons',
                'days_until_disappearance' => 0,
                'usage_count'           => null
            ];

            $this->buildingModel->insert($data);
            // Снова считываем
            $arsenal = $this->buildingModel->where('name_en', 'Arsenal')->first();
        }
        return $arsenal;
    }

    /**
     * Проверяет, хватает ли у персонажа ресурсов (таблица resources).
     * Возвращает массив [resourceNameEn => [...]] тех, которых не хватает.
     */
    /**
     * @param array<string, int> $requiredResources
     * @return array<string, array{required: int, available: int, name_rus: string}>
     */
    private function checkResources(int $characterId, array $requiredResources, ResourceModel $resourceModel, CharacterResourceModel $characterResourcesModel): array
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
                // Если ресурс вообще не найден в таблице, можно тоже считать, что не хватает
                $missing[$resourceNameEn] = [
                    'required'  => $requiredAmt,
                    'available' => 0,
                    'name_rus'  => $resourceNameEn.'(нет в DB)',
                ];
            }
        }
        return $missing;
    }

    /**
     * Проверяет, хватает ли у персонажа крафтовых предметов (таблица crafted_items + crafted_items_log).
     * Возвращает массив [itemNameEn => [...]] тех, которых не хватает.
     */
    /**
     * @param array<string, int> $requiredItems
     * @return array<string, array{required: int, available: int, name_rus: string}>
     */
    private function checkCraftedItems(int $characterId, array $requiredItems, CraftedItemsModel $craftedItemsModel, CraftedItemsLogModel $craftedItemsLogModel): array
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
                // Если предмета нет в crafted_items, считаем, что тоже не хватает
                $missing[$itemNameEn] = [
                    'required'  => $reqAmt,
                    'available' => 0,
                    'name_rus'  => $itemNameEn.'(нет в DB)',
                ];
            }
        }
        return $missing;
    }

    /**
     * Форматирует список нужных ресурсов, выводя "Название (нужно X, у вас Y)"
     */
    /**
     * @param array<string, int> $requiredResources
     */
    private function formatResourcesForText(array $requiredResources, ResourceModel $resourceModel, int $characterId): string
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

    /**
     * Форматирует список нужных крафтовых предметов, аналогично
     */
    /**
     * @param array<string, int> $requiredItems
     */
    private function formatCraftedItemsForText(array $requiredItems, CraftedItemsModel $craftedItemsModel, int $characterId): string
    {
        $text = "";
        foreach ($requiredItems as $itemNameEn => $reqAmount) {
            $row = $craftedItemsModel->getRowByName($itemNameEn);
            if ($row) {
                // Смотрим, сколько у игрока
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

    /**
     * Генерирует текст о недостающих ресурсах
     */
    /**
     * @param array<string, array{required: int, available: int, name_rus: string}> $missingResources
     */
    private function getMissingResourcesText(array $missingResources, ResourceModel $resourceModel): string
    {
        $text = "";
        foreach ($missingResources as $resNameEn => $data) {
            $text .= "- {$data['name_rus']}: требуется {$data['required']}, у вас {$data['available']}\n";
        }
        return $text;
    }

    /**
     * Генерирует текст о недостающих крафтовых предметах
     */
    /**
     * @param array<string, array{required: int, available: int, name_rus: string}> $missingCraftedItems
     */
    private function getMissingCraftedItemsText(array $missingCraftedItems, CraftedItemsModel $craftedItemsModel): string
    {
        $text = "";
        foreach ($missingCraftedItems as $itemNameEn => $data) {
            $text .= "- {$data['name_rus']}: требуется {$data['required']}, у вас {$data['available']}\n";
        }
        return $text;
    }
}
