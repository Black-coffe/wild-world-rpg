<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp\Buildings;

use Longman\TelegramBot\Request;
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

        // -- Формируем текст
        $text = "Ты в разделе *«Маяки телепорта»*.\n\n"
            . "Здесь доступны действия:\n"
            . "• *Поставить маяк* (в текущей локации)\n"
            . "• *Переместиться* (на локацию, где уже стоит маяк)\n"
            . "• *Собрать маяк* (демонтировать)\n\n"

            // Информация про лимиты:
            . "⚙ *Правила установки:*\n"
            . "1) 1 маяк на каждые *10 уровней* игрока (но не более 10 при уровне 100+).\n"
            . "2) Здание *Центр телепортации* даёт +1 маяк за *каждый* свой уровень.\n"
            . "   (Уровень здания: {$teleportCenterLevel}, значит +{$teleportCenterLevel} к лимиту.)\n"
            . "   _Максимальный уровень здания — 10._\n\n"
            . "🔹 *Твой уровень*: {$playerLevel}\n"
            . "🔹 *Базовый лимит маяков* (по уровню персонажа): {$baseMaxByPlayer}\n"
            . "🔹 *Уровень Центра*: {$teleportCenterLevel}, значит +{$teleportCenterLevel} к лимиту\n"
            . "🔹 Итого можно установить *максимум: {$maxBeacons}* маяков.\n\n"

            // Отображаем, сколько у него маяков в инвентаре
            . "У тебя в инвентаре: *{$beaconQuantity}* шт. _телепорт-маяков._\n\n"

            // Информация о текущей локации
            . "📍 *Твоя позиция*:\n"
            . "• Ячейка: *{$cellNumber}*\n"
            . "• Координаты: `X={$coordX}, Y={$coordY}`\n"
            . "• Биом: *{$biomeName}*\n\n";

        // --- Формируем кнопки
        $keyboardRows = [];

        // Кнопка «Установить маяк», только если у игрока есть хотя бы один маяк
        // beaconQuantity > 0
        if ($beaconQuantity > 0) {
            $callbackForSet = "teleportBeaconSet_x={$coordX}_y={$coordY}";
            $keyboardRows[] = [
                [
                    'text' => "Установить маяк | (X={$coordX} Y={$coordY})",
                    'callback_data' => $callbackForSet
                ],
            ];
        } else {
            // Если маяков нет, добавим фразу в текст (дополнительно):
            $text .= "\n_У тебя нет маяков, чтобы поставить на этой локации._\n";
        }

        // Остальные кнопки — «Переместиться на маяк» и «Собрать маяк»
        $keyboardRows[] = [
            ['text' => 'Переместиться на маяк',  'callback_data' => 'teleportBeaconMove'],
        ];
        $keyboardRows[] = [
            ['text' => 'Собрать маяк',           'callback_data' => 'teleportBeaconCollect'],
        ];

        $keyboard = [ 'inline_keyboard' => $keyboardRows ];

        // -- Путь к фото
        $imagePath = base_url('uploads/telegram/craft/standard/beacon_craft.jpg');

        // -- Отправляем сообщение с фото
        return Request::sendPhoto([
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
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
