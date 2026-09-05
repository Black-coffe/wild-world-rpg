<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\TeleportBeacon;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use App\Models\CraftedItemsModel;
use App\Models\CraftedItemsLogModel;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\ClaimedCellModel;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * Показывает требования к крафту "Рюкзак телепорт" (teleportBackpack2).
 * Аналог TeleportBeaconBasic2Action, но для другого предмета.
 */
class TeleportBackpack2Action extends BaseAction
{
    protected $characterResourceModel;
    protected $resourceModel;
    protected $characterModel;
    protected $craftedItemsModel;
    protected $craftedItemsLogModel;
    protected $buildingModel;
    protected $characterBuildingModel;
    protected $claimedCellModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterResourceModel  = new CharacterResourceModel();
        $this->resourceModel           = new ResourceModel();
        $this->characterModel          = new CharacterModel();
        $this->craftedItemsModel       = new CraftedItemsModel();
        $this->craftedItemsLogModel    = new CraftedItemsLogModel();
        $this->buildingModel           = new BuildingModel();
        $this->characterBuildingModel  = new CharacterBuildingModel();
        $this->claimedCellModel        = new ClaimedCellModel();
    }

    public function handle(): ServerResponse
    {
        // Убираем "часики" на кнопке
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь или персонаж не найдены.',
            ]);
        }

        // Проверка активного переезда (если используется общая логика блокировки)
        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $this->callbackQuery->getMessage()->getChat()->getId()
        )) {
            return Request::emptyResponse();
        }

        $characterId = $character['id'];

        /**
         * Требования к крафту рюкзака-телепорта (примерные значения).
         * Подставьте свои реальные параметры.
         */
        $requiredResources = [
            'Янтарь' => 23,
            'Минералы'   => 15,
            'Солнечные камни'  => 12,
            'Кристаллы'  => 7,
        ];
        $requiredComponents = [
            'Проводка'               => 4,
            'Электронные компоненты' => 8,
            'Ткань'                  => 36,
        ];
        $requiredGold = 21000;

        // 1. Проверка наличия базы
        if (!$this->checkHasBase($characterId)) {
            return $this->sendInsufficientResponse($chatId, 'У вас нет построенной базы (лагеря).');
        }

        // 2. Проверка здания "Центр телепортации" (TeleportationCenter) — для логики
        $teleportCenter = $this->buildingModel->where('name_en', 'TeleportationCenter')->first();
        if (!$teleportCenter) {
            return $this->sendInsufficientResponse(
                $chatId,
                'В БД не найдено здание TeleportationCenter. Обратитесь к администратору.'
            );
        }
        $hasTeleportCenter = $this->characterBuildingModel
            ->where('character_id', $characterId)
            ->where('building_id', $teleportCenter['id'])
            ->first();
        if (!$hasTeleportCenter) {
            return $this->sendInsufficientResponse($chatId, 'Для крафта требуется здание "Центр телепортации".');
        }

        // (опционально) 3. Проверка 1-го верстака
        $workshop = $this->buildingModel->where('name_en', 'Workshop')->first();
        if ($workshop) {
            $hasWorkshop = $this->characterBuildingModel
                ->where('character_id', $characterId)
                ->where('building_id', $workshop['id'])
                ->first();
            if (!$hasWorkshop) {
                return $this->sendInsufficientResponse($chatId, 'Необходим 1-й верстак (Workshop).');
            }
        }

        // 4. Проверяем сырьевые ресурсы
        $resourcesAvailable = $this->checkResourcesAvailability($characterId, $requiredResources);

        // 5. Проверяем крафтовые предметы
        $componentsAvailable = $this->checkCraftedItemAvailability($characterId, $requiredComponents);

        // 6. Проверяем золото
        $charRow     = $this->characterModel->find($characterId);
        $goldQuantity = $charRow ? $charRow['gold'] : 0;

        // --------------------------
        // Формируем вывод
        // --------------------------
        $title = "*🎒 Рюкзак телепорт*\n\n"
            . "Позволяет игроку в любой момент вернуться на свою базу без дополнительных затрат!\n\n"
            . "_Требования для крафта:_\n"
            . "• Центр телепортации\n"
            . "• База\n"
            . "• 1-й верстак (желательно)\n"
            . "• Материалы + Золото\n\n";

        // Сюда будем собирать строки формата «• Название x Кол-во ✅/❌ (...)»
        $requirementsText = [];
        $canCraft = true; // если найдём «недостаточно» — поставим false

        // -- Сырьевые ресурсы
        foreach ($requiredResources as $resName => $reqAmount) {
            $haveAmount = $resourcesAvailable[$resName]['quantity'] ?? 0;
            if ($haveAmount < $reqAmount) {
                $canCraft = false;
                $requirementsText[] = "• {$resName} x {$reqAmount} ❌ (есть {$haveAmount}, нужно {$reqAmount})";
            } else {
                $requirementsText[] = "• {$resName} x {$reqAmount} ✅ (есть {$haveAmount})";
            }
        }

        // -- Крафтовые предметы
        foreach ($requiredComponents as $compName => $reqAmount) {
            $haveAmount = $componentsAvailable[$compName]['quantity'] ?? 0;
            if ($haveAmount < $reqAmount) {
                $canCraft = false;
                $requirementsText[] = "• {$compName} x {$reqAmount} ❌ (есть {$haveAmount}, нужно {$reqAmount})";
            } else {
                $requirementsText[] = "• {$compName} x {$reqAmount} ✅ (есть {$haveAmount})";
            }
        }

        // -- Золото
        if ($goldQuantity < $requiredGold) {
            $canCraft = false;
            $requirementsText[] = "• Золото: {$requiredGold} ❌ (есть {$goldQuantity}, нужно {$requiredGold})";
        } else {
            $requirementsText[] = "• Золото: {$requiredGold} ✅ (есть {$goldQuantity})";
        }

        $requirementsBlock = implode("\n", $requirementsText);

        $text = $title
            . "Требуются:\n"
            . "{$requirementsBlock}\n\n";

        // Если хватает ресурсов — предлагаем крафт
        if ($canCraft) {
            $text .= "*Все необходимые материалы в наличии!* \n"
                . "Примерное время крафта: ~45 минут.\n"
                . "Нажми кнопку «Крафтить», чтобы начать создание рюкзака.";
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🛠️ Крафтить', 'callback_data' => 'startCraftTeleportBackpack2'],
                    ],
                ]
            ];
        } else {
            // Иначе выводим ссылку на продажу/покупку/инвентарь
            $text .= "__У вас не хватает некоторых материалов. Проверьте список выше.__";
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                        ['text' => '💰 Продать',   'callback_data' => 'sell'],
                        ['text' => '🛍️ Купить',   'callback_data' => 'buy'],
                    ],
                ]
            ];
        }

        // Изображение (если есть)
        $imagePath = base_url('uploads/telegram/craft/standard/backpack_craft.jpg');

        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Проверяем, есть ли у игрока база (claimed_cells со статусом active).
     */
    private function checkHasBase(int $characterId): bool
    {
        $row = $this->claimedCellModel
            ->where('character_id', $characterId)
            ->where('status', 'active')
            ->first();
        return (bool)$row;
    }

    /**
     * Возвращает массив ['Название'=> ['name'=>'...', 'quantity'=>число], ...].
     */
    private function checkResourcesAvailability(int $characterId, array $requiredResources): array
    {
        $results = [];
        foreach ($requiredResources as $name => $amount) {
            $row = $this->resourceModel->getResourceByName($name);
            if ($row) {
                $charRes = $this->characterResourceModel->getResourceByNameAndCharacterId($name, $characterId);
                $haveQty = $charRes ? $charRes['quantity'] : 0;
            } else {
                $haveQty = 0;
            }
            $results[$name] = [
                'name'     => $name,
                'quantity' => $haveQty,
            ];
        }
        return $results;
    }

    /**
     * Аналогичная проверка для крафтовых предметов.
     */
    private function checkCraftedItemAvailability(int $characterId, array $requiredComponents): array
    {
        $results = [];
        foreach ($requiredComponents as $name => $amount) {
            $craftedRow = $this->craftedItemsModel->getCraftedItemByName($name);
            if ($craftedRow) {
                $charItem = $this->craftedItemsLogModel
                    ->where('crafted_item_id', $craftedRow['id'])
                    ->where('character_id', $characterId)
                    ->first();
                $haveQty = $charItem ? $charItem['quantity'] : 0;
            } else {
                $haveQty = 0;
            }
            $results[$name] = [
                'name'     => $name,
                'quantity' => $haveQty,
            ];
        }
        return $results;
    }

    /**
     * Если чего-то не хватает (база, здание, ресурсы), отправляем сообщение + фото.
     */
    private function sendInsufficientResponse(int $chatId, string $message): ServerResponse
    {
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '◀️ Я', 'callback_data' => 'character'],
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                ],
                [
                    ['text' => '💰 Продать', 'callback_data' => 'sell'],
                    ['text' => '🛍️ Купить', 'callback_data' => 'buy'],
                ],
            ]
        ];

        $imagePath = base_url('uploads/telegram/craft/standard/backpack_craft.jpg');

        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $message,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
