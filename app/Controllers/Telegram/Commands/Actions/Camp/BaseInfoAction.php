<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\ClaimedCellModel;
use App\Models\MapModel;
use App\Models\BiomeModel;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

// Подключаем сервис проверки занятости ячейки
use App\Services\Bases\CampCheckService;

// >>> Добавляем сервис проверки сигнала Вышки связи <<<
use App\Services\Coverage\CommunicationTowerCoverageService;

/**
 * Класс BaseInfoAction
 * Показывает информацию о базе/лагере:
 * 1) Если базы нет, предлагаем разбить.
 * 2) Если персонаж не на базе, сообщаем координаты базы
 *    (или, при наличии вышки связи, даём доступ к управлению).
 * 3) Если персонаж на базе, показываем список построек.
 */
class BaseInfoAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        // Сразу отвечаем на CallbackQuery (убираем "часики" в интерфейсе)
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);

        // Получаем данные о пользователе и персонаже
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'       => '🤖 Это снова я – *Роби*!\n\nПользователь не найден или персонаж не определён.',
                'parse_mode' => 'Markdown'
            ]);
        }

        // Подключаем необходимые модели
        $claimedCellModel       = new ClaimedCellModel();
        $mapModel               = new MapModel();
        $biomeModel             = new BiomeModel();
        $buildingModel          = new BuildingModel();
        $characterBuildingModel = new CharacterBuildingModel();

        // Также подключим наш сервис проверок вышки связи
        $towerCoverageService   = new CommunicationTowerCoverageService();

        // 1) Проверяем, есть ли у персонажа база
        $claimedCell = $claimedCellModel
            ->where('character_id', $character['id'])
            ->first();

        // -------------------------------------------
        // 1) Если у персонажа НЕТ лагеря
        // -------------------------------------------
        if (!$claimedCell) {
            return $this->handleNoBase(
                $character,
                $mapModel,
                $biomeModel
            );
        }

        // -------------------------------------------
        // 2) У персонажа есть лагерь
        // Проверяем, находится ли персонаж ФИЗИЧЕСКИ на ячейке своей базы
        // -------------------------------------------
        if ($claimedCell['map_cell_id'] == $character['cell_number']) {
            // Персонаж действительно на базе
            return $this->showBaseBuildings(
                $character,
                $claimedCell,
                $mapModel,
                $biomeModel,
                $buildingModel,
                $characterBuildingModel
            );
        }

        // Иначе персонаж НЕ на базе. Проверяем сигнал вышки
        $coverageResult = $towerCoverageService->checkCoverage($character['id']);
        if ($coverageResult['isCovered']) {
            // Если покрывает, покажем базу так же,
            // но добавим пометку «удалённое управление»
            return $this->showBaseBuildings(
                $character,
                $claimedCell,
                $mapModel,
                $biomeModel,
                $buildingModel,
                $characterBuildingModel,
                $coverageResult
            );
        } else {
            // Если не покрывает — старое поведение:
            // «база в другой ячейке, вернись...»
            return $this->handleNotOnBasePhysically(
                $claimedCell,
                $mapModel,
                $biomeModel
            );
        }
    }

    /**
     * Случай, когда у персонажа вообще нет базы.
     */
    protected function handleNoBase(
        array|\App\Entities\CharacterEntity $character,
        MapModel $mapModel,
        BiomeModel $biomeModel
    ): ServerResponse
    {
        // Проверим, не занята ли текущая ячейка другим игроком
        $cellNumber = $character['cell_number'] ?? 0;
        $campCheckService = new CampCheckService();

        if ($cellNumber && $campCheckService->isCellClaimedByAnyone($cellNumber)) {
            // Ячейка занята чужим лагерем
            $text = "🤖 Это снова я – *Роби*!\n\n"
                . "Ты находишься в ячейке, которая уже занята чужим лагерем.\n"
                . "Здесь нельзя разбить собственный лагерь!";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        // Другие действия (меню)
                        ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ],
                ]
            ];

            return Request::sendMessage([
                'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'         => $text,
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        }

        // Ячейка не занята → можно предлагать разбить лагерь
        // Показываем инфо о биоме/координатах
        $cellNumber = $character['cell_number'] ?? 0;
        if (!$cellNumber) {
            // Нет cellNumber
            $text = "🤖 Это снова я – *Роби*!\n\n"
                . "У тебя нет разбитого лагеря, и не найдены координаты. "
                . "Похоже, ты ещё не на карте?";
        } else {
            // Ищем запись в map
            $mapRow = $mapModel->where('cell_number', $cellNumber)->first();
            if (!$mapRow) {
                $text = "🤖 Это снова я – *Роби*!\n\n"
                    ."У тебя нет ещё разбитого лагеря и не найдена карта для cell_number={$cellNumber}.";
            } else {
                // Координаты
                $coordX = $mapRow['coordinate_x'];
                $coordY = $mapRow['coordinate_y'];

                // Биом
                $biomeId = $mapRow['biome_id'];
                $biomeRow = $biomeModel->find($biomeId);

                if (!$biomeRow) {
                    $text = "🤖 Это снова я – *Роби*!\n\n"
                        ."У тебя нет ещё разбитого лагеря, "
                        ."координаты: X={$coordX}, Y={$coordY}, "
                        ."но биом (ID={$biomeId}) не найден.";
                } else {
                    $bName   = $biomeRow['name'] ?? '???';
                    $bDesc   = $biomeRow['description'] ?? 'Описание недоступно';
                    $dLevel  = $biomeRow['danger_level'] ?? 0;
                    $sDiff   = $biomeRow['survival_difficulty'] ?? 0;

                    $text = "🤖 Это снова я – *Роби*!\n\n"
                        . "У тебя *нет* ещё разбитого лагеря, а значит и нет базы.\n"
                        . "Но ты сейчас находишься в ячейке:\n"
                        . "• Координаты: X={$coordX}, Y={$coordY}\n"
                        . "• Биом: *{$bName}*\n"
                        . "• Опасность: {$dLevel}\n"
                        . "• Сложность выживания: {$sDiff}\n"
                        . "• *Описание*: {$bDesc}\n\n"
                        . "Для разбивки лагеря используй кнопки ниже.";
                }
            }
        }

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
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Случай, когда у персонажа есть база, но он не на её клетке
     * и сигнал вышки связи НЕ дотягивается.
     */
    protected function handleNotOnBasePhysically(
        array $claimedCell,
        MapModel $mapModel,
        BiomeModel $biomeModel
    ): ServerResponse
    {
        // Ищем координаты базы
        $mapRow = $mapModel->where('cell_number', $claimedCell['map_cell_id'])->first();
        if (!$mapRow) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Ошибка: не удалось найти карту для базы.',
            ]);
        }

        $biomeRow  = $biomeModel->where('id', $mapRow['biome_id'])->first();
        $biomeName = $biomeRow['name'] ?? '???';
        $coordX    = $mapRow['coordinate_x'];
        $coordY    = $mapRow['coordinate_y'];

        $text = "🤖 Это снова я – *Роби*!\n\n"
            . "Твоя база находится в другой игровой ячейке, "
            . "и (без вышки связи или вне её радиуса) ты не можешь управлять ею дистанционно.\n\n"
            . "Чтобы начать строительство, вернись на базу:\n"
            . "1️⃣ перемещение пешком\n"
            . "2️⃣ телепорт\n\n"
            . "📍 *Координаты базы*: x={$coordX}, y={$coordY}\n"
            . "🌍 *Биом*: {$biomeName}";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📡 Телепорт', 'callback_data' => 'TeleportToCamp'],
                    ['text' => '🚜 Переехать', 'callback_data' => 'move'],
                ],
            ]
        ];

        $imagePath = base_url('uploads/telegram/camp/an_empty_area.jpg');
        return Request::sendPhoto([
            'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Случай, когда персонаж находится на базе
     * ИЛИ в зоне покрытия вышки связи (дистанционное управление).
     * Если $coverageResult['isCovered']=true, добавим блок текста "удалённое управление".
     */
    protected function showBaseBuildings(
        array|\App\Entities\CharacterEntity $character,
        array $claimedCell,
        MapModel $mapModel,
        BiomeModel $biomeModel,
        BuildingModel $buildingModel,
        CharacterBuildingModel $characterBuildingModel,
        ?array $coverageResult = null
    ): ServerResponse
    {
        // Собираем список построек
        $buildings = $characterBuildingModel
            ->where('character_id', $character['id'])
            ->findAll();

        $mapRow = $mapModel
            ->where('cell_number', $claimedCell['map_cell_id'])
            ->first();
        if (!$mapRow) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Ошибка: карта не найдена (ячейка базы).',
            ]);
        }

        // Биом
        $biomeRow  = $biomeModel->find($mapRow['biome_id']);
        $biomeName = $biomeRow['name'] ?? '???';
        $coordX    = $mapRow['coordinate_x'];
        $coordY    = $mapRow['coordinate_y'];

        $buildingCount = count($buildings);
        $totalTax      = array_sum(array_column($buildings, 'tax'));

        // Список построек
        $buildingList = '';
        foreach ($buildings as $b) {
            $bld = $buildingModel->where('id', $b['building_id'])->first();
            $bName = $bld['name_ru'] ?? 'Неизвестное строение';
            $buildingList .= "- {$bName}\n";
        }

        // Проверяем, есть ли покрытие вышки
        $remoteText = "";
        if ($coverageResult && $coverageResult['isCovered']) {
            $lvl       = $coverageResult['towerLevel'] ?? 1;
            $distance  = $coverageResult['distanceToBase'] ?? 0;
            $maxCov    = $coverageResult['maxCoverage'] ?? ($lvl * 100);

            $remoteText = "_Вы находитесь не на базе, но_ *Вышка связи* (ур. {$lvl}) "
                . "покрывает расстояние *{$distance}* / *{$maxCov}*.\n"
                . "Управление базой доступно *дистанционно*!️\n\n";
        }

        // Итоговый текст
        $text = "🤖 Это я – *Роби*!\n\n"
            . $remoteText
            . "📍 *Координаты базы*: x={$coordX}, y={$coordY}\n"
            . "🌍 *Биом*: {$biomeName}\n\n"
            . "*Твоя база содержит:*\n"
            . "*Построек:* {$buildingCount} шт.\n"
            . "*Налог:* {$totalTax} ед. золота в сутки\n\n"
            . "*Список построек:*\n"
            . "{$buildingList}";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🏗 Строить',    'callback_data' => 'Build'],
                    ['text' => '🏘 Постройки',  'callback_data' => 'construction'],
                    ['text' => '📡 Маяки',      'callback_data' => 'teleportBeacon'],
                ],
                [
                    ['text' => '❌ Удалить базу',         'callback_data' => 'DeleteBase'],
                    ['text' => '🚚 Полноценный переезд', 'callback_data' => 'DeleteBase_FullRelocation'],
                ],
            ]
        ];

        $imagePath = base_url('uploads/telegram/camp/base_with_its_buildings.jpg');

        return Request::sendPhoto([
            'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
