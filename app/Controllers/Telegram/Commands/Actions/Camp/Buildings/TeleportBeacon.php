<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp\Buildings;

use App\Services\Telegram\Request;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;

// Модели:
use App\Models\CharacterBuildingModel;
use App\Models\BuildingModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\CharacterModel;
use App\Models\MapModel;
use App\Models\BiomeModel;
use App\Models\TeleportBeaconModel;
use App\Services\Player\TeleportBeacon\BeaconMessageFormatter;
use App\Services\Player\TeleportBeacon\BeaconSettings;

/**
 * Класс для вывода информации о телепорт-маяках,
 * а также текущей локации персонажа (координаты + биом).
 */
class TeleportBeacon
{
    protected CallbackQuery $callbackQuery;

    // Модели
    protected CharacterBuildingModel $characterBuildingModel;
    protected BuildingModel $buildingModel;
    protected CraftedItemsLogModel $craftedItemsLogModel;
    protected CraftedItemsModel $craftedItemsModel;
    protected CharacterModel $characterModel;
    protected MapModel $mapModel;
    protected BiomeModel $biomeModel;
    protected TeleportBeaconModel $teleportBeaconModel;

    public function __construct(CallbackQuery $callbackQuery)
    {
        $this->callbackQuery          = $callbackQuery;
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->buildingModel          = new BuildingModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();
        $this->craftedItemsModel      = new CraftedItemsModel();
        $this->characterModel         = new CharacterModel();
        $this->mapModel               = new MapModel();
        $this->biomeModel             = new BiomeModel();
        $this->teleportBeaconModel    = new TeleportBeaconModel();
    }

    public function handle(): ServerResponse
    {
        // 1) Убираем «часики» на нажатой кнопке
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
        ]);

        // 2) Получаем chat_id
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        // 3) Узнаём telegramId
        $telegramUserId = $this->callbackQuery->getFrom()->getId();

        // 4) Определяем characterId
        $characterId = $this->characterModel->getCharacterIdByTelegramId($telegramUserId);
        if (!$characterId) {
            return $this->sendError($chatId, "Ошибка: не удалось определить персонажа для пользователя {$telegramUserId}.");
        }

        // -- Проверяем наличие здания «Центр телепортации»
        $teleportCenter = $this->buildingModel->where('name_en', 'TeleportationCenter')->first();
        if (!$teleportCenter) {
            $errorText = "Ошибка: в базе нет здания \"Центр телепортации\". Обратитесь к администратору.";
            return $this->sendError($chatId, $errorText);
        }

        // Есть ли у игрока постройка «Центр телепортации»?
        $hasTeleportCenter = $this->characterBuildingModel
            ->where('character_id', $characterId)
            ->where('building_id', $teleportCenter['id'])
            ->first();

        if (!$hasTeleportCenter) {
            // Нет центра телепортации
            $errorText = "Для установки телепорт-маяков нужно здание *Центр телепортации*.\n"
                . "Построй его, а потом возвращайся!";
            return $this->sendError($chatId, $errorText);
        }

        // -- Достаём уровень здания (в character_buildings.level),
        //    максимальный уровень постройки = 10
        $teleportCenterLevel = $hasTeleportCenter['level'] ?? 1;
        if ($teleportCenterLevel < 1) {
            $teleportCenterLevel = 1;
        } elseif ($teleportCenterLevel > 10) {
            $teleportCenterLevel = 10; // Но по условию больше 10 не бывает
        }

        // -- Узнаём, где сейчас персонаж (cell_number, biome_id, уровень)
        $characterRow = $this->characterModel->find($characterId);
        if (!$characterRow) {
            return $this->sendError($chatId, "Ошибка: персонаж с ID {$characterId} не найден.");
        }

        $cellNumber = $characterRow['cell_number'] ?? 0;
        $biomeId    = $characterRow['biome_id']    ?? null;
        $playerLevel = $characterRow['level']      ?? 1;

        $coordX    = '?';
        $coordY    = '?';
        $biomeName = '???';

        // -- Получаем координаты
        if ($cellNumber) {
            $mapRow = $this->mapModel->where('cell_number', $cellNumber)->first();
            if ($mapRow) {
                $coordX = $mapRow['coordinate_x'];
                $coordY = $mapRow['coordinate_y'];
                $mapBiomeId = $mapRow['biome_id'];

                // Если у персонажа biome_id не проставлен, возьмём из map
                if (!$biomeId) {
                    $biomeId = $mapBiomeId;
                }
            }
        }

        // -- Получаем название биома
        if ($biomeId) {
            $biomeRow = $this->biomeModel->find($biomeId);
            if ($biomeRow) {
                $biomeName = $biomeRow['name'];
            }
        }

        // -- Считаем, сколько маяков «TeleportBeaconBasic» есть у игрока (в инвентаре)
        $beaconItem = $this->craftedItemsModel->where('name_eng', 'TeleportBeaconBasic')->first();
        $beaconQuantity = 0;
        if ($beaconItem) {
            $beaconLog = $this->craftedItemsLogModel
                ->where('character_id',    $characterId)
                ->where('crafted_item_id', $beaconItem['id'])
                ->first();
            if ($beaconLog) {
                $beaconQuantity = $beaconLog['quantity'];
            }
        }

        // --- Расчёт максимально допустимого количества маяков
        // 1 маяк на каждые 10 уровней персонажа, макс 10 маяков к 100+ уровню
        $baseMaxByPlayer = intdiv($playerLevel, 10);
        if ($baseMaxByPlayer > 10) {
            $baseMaxByPlayer = 10;
        }
        // Уровень Центра телепортации => +1 маяк за каждый уровень здания
        // Итого maxBeacons = baseMaxByPlayer + teleportCenterLevel
        $maxBeacons = $baseMaxByPlayer + $teleportCenterLevel;

        // -- Установленные маяки игрока: остаток телепортов у каждого + налог.
        //    Раньше экран про маяки не показывал ни одного маяка — только лимиты и
        //    счётчик в инвентаре, а остаток заряда жил хвостом «ТП. 87» в списке
        //    перемещения. Вопрос игрока (Анжела, 18.08.2026) «как узнать, сколько
        //    заряда осталось в маяке?» — ровно про это.
        $balance = new BeaconSettings();
        $beacons = [];
        // Свежие модели под цикл: у `$this->mapModel` выше уже накоплен where('cell_number'),
        // а builder CI4 копит условия между вызовами (урок feedback_ci4_model_builder_state_quirk).
        $beaconMapModel   = new MapModel();
        $beaconBiomeModel = new BiomeModel();
        foreach ($this->teleportBeaconModel->where('character_id', $characterId)->findAll() as $beaconRaw) {
            $b = is_array($beaconRaw) ? $beaconRaw : (array) $beaconRaw;

            $beaconBiome  = '???';
            $beaconCellId = $this->asInt($b['map_cell_id'] ?? null);
            if ($beaconCellId > 0) {
                $mapFound      = $beaconMapModel->find($beaconCellId);
                $beaconMapRow  = is_array($mapFound) ? $mapFound : (is_object($mapFound) ? (array) $mapFound : []);
                $beaconBiomeId = $this->asInt($beaconMapRow['biome_id'] ?? null);
                if ($beaconBiomeId > 0) {
                    // BiomeModel отдаёт BiomeEntity (F1.4.1) — конвертируем в массив
                    // отдельной переменной (урок entity_strict_array_typehint_trap).
                    $biomeFound     = $beaconBiomeModel->find($beaconBiomeId);
                    $beaconBiomeRow = $biomeFound !== null ? $biomeFound->toArray() : [];
                    $foundName      = $beaconBiomeRow['name'] ?? null;
                    if (is_string($foundName) && $foundName !== '') {
                        $beaconBiome = $foundName;
                    }
                }
            }

            $uses = $this->asInt($b['remaining_uses'] ?? null);

            $beacons[] = [
                'x'        => $this->asInt($b['coordinate_x'] ?? null),
                'y'        => $this->asInt($b['coordinate_y'] ?? null),
                'uses'     => $uses,
                // Потолок берём максимумом из настройки и остатка: у старых маяков
                // запас мог быть выставлен другим значением, и «87 из 100» при 120
                // на руках читалось бы как враньё.
                'max_uses' => max($balance->maxUses(), $uses),
                'biome'    => $beaconBiome,
                'tax'      => $this->asInt($b['tax_cost'] ?? null),
            ];
        }

        $text = (new BeaconMessageFormatter())->beaconsOverview(
            $beacons,
            [
                'x'     => is_scalar($coordX) ? (string) $coordX : '?',
                'y'     => is_scalar($coordY) ? (string) $coordY : '?',
                'cell'  => $this->asInt($cellNumber),
                'biome' => is_string($biomeName) ? $biomeName : '???',
            ],
            $this->asInt($playerLevel),
            $baseMaxByPlayer,
            $this->asInt($teleportCenterLevel),
            $maxBeacons,
            $this->asInt($beaconQuantity),
            $balance->maxUses(),
            $balance->taxPerDay()
        ) . "\n\n";

        // --- Формируем кнопки
        $keyboardRows = [];

        // Кнопка «Установить маяк», только если у игрока есть хотя бы один маяк
        // beaconQuantity > 0
        if ($beaconQuantity > 0) {
            $callbackForSet = "teleportBeaconSet_x={$coordX}_y={$coordY}";
            $keyboardRows[] = [
                [
                    'text' => "Установить маяк здесь: (X={$coordX} Y={$coordY})",
                    'callback_data' => $callbackForSet
                ],
            ];
        } else {
            // Если маяков нет, добавим фразу в текст (дополнительно):
            $text .= "\n_У тебя нет маяков, чтобы поставить на этой локации._\n";
            // N2: прямой путь скрафтить маяк (раньше экран был тупиком при 0 шт.).
            $keyboardRows[] = [
                ['text' => '🔧 Скрафтить маяк', 'callback_data' => 'teleportBeaconCraft2'],
            ];
        }

        // Перемещение и снятие. «🗑 Снять маяк» — единственный выход из состояния
        // «лимит забит выработанными маяками»: до 18.08.2026 удаления не существовало,
        // хотя отказ установки советовал «сначала удали старый маяк».
        $keyboardRows[] = [
            ['text' => '🌀 Переместиться', 'callback_data' => 'teleportBeaconMove'],
            ['text' => '🗑 Снять маяк',    'callback_data' => 'teleportBeaconRemove'],
        ];

        // N2: возврат на базу (экран не должен быть тупиком).
        $keyboardRows[] = [
            ['text' => '🏠 База', 'callback_data' => 'Base'],
        ];

        $keyboard = [ 'inline_keyboard' => $keyboardRows ];

        // -- Путь к фото
        $imagePath = base_url('uploads/telegram/craft/standard/beacon_craft.jpg');

        // -- Отправляем сообщение с фото
        return \App\Services\Notifications\MediaSender::sendPhotoOrText([
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Мягкое приведение к int: модели отдают значения как mixed, а прямой каст
     * mixed→int запрещён статикой (и врёт на неожиданных типах).
     */
    private function asInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function sendError(int $chatId, string $message): ServerResponse
    {
        return Request::sendMessage([
            'chat_id'    => $chatId,
            'text'       => $message,
            'parse_mode' => 'Markdown',
        ]);
    }
}
