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

class BaseInfoAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        // Сразу отвечаем на CallbackQuery (убираем "часики" в интерфейсе)
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);

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

        // Проверяем, есть ли у персонажа лагерь
        $claimedCell = $claimedCellModel
            ->where('character_id', $character['id'])
            ->first();

        // -------------------------------------------
        // 1) Если у персонажа НЕТ лагеря
        // -------------------------------------------
        if (!$claimedCell) {
            // Проверим, не занята ли ТЕКУЩАЯ ячейка другим игроком
            $cellNumber = $character['cell_number'] ?? 0;
            $campCheckService = new CampCheckService();

            if ($cellNumber && $campCheckService->isCellClaimedByAnyone($cellNumber)) {
                // Ячейка занята активной базой (чужой)
                $text = "🤖 Это снова я – *Роби*!\n\n"
                    . "Ты находишься в ячейке, которая уже занята чужим лагерем.\n"
                    . "Здесь нельзя разбить собственный лагерь!";

                $keyboard = [
                    'inline_keyboard' => [
                        [
                            // Другие действия (вернуться в меню и т.д.)
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

            } else {
                // Ячейка не занята → можно предлагать разбить лагерь

                // -- Добавляем инфу о биоме и координатах --
                // 1) Получаем cellNumber из персонажа
                $cellNumber = $character['cell_number'] ?? 0;
                if (!$cellNumber) {
                    // На случай, если у персонажа нет cell_number
                    // (маловероятно, но лучше подстраховаться)
                    $text = "🤖 Это снова я – *Роби*!\n\n"
                        . "У тебя нет еще разбитого лагеря, а данные о координатах не найдены.";
                } else {
                    // 2) Ищем запись в map по cellNumber
                    $mapRow = $mapModel->where('cell_number', $cellNumber)->first();
                    if (!$mapRow) {
                        // Нет записи в map
                        $text = "🤖 Это снова я – *Роби*!\n\n"
                            . "У тебя нет еще разбитого лагеря и не найдена карта для cell_number={$cellNumber}.";
                    } else {
                        // 3) Извлекаем координаты
                        $coordX = $mapRow['coordinate_x'];
                        $coordY = $mapRow['coordinate_y'];
                        // 4) Ищем биом
                        $biomeId = $mapRow['biome_id'];
                        $biomeRow = $biomeModel->find($biomeId);

                        if (!$biomeRow) {
                            // Нет записи о биоме
                            $text = "🤖 Это снова я – *Роби*!\n\n"
                                . "У тебя нет еще разбитого лагеря, координаты: X={$coordX}, Y={$coordY}, "
                                . "но биом не найден (ID={$biomeId}).";
                        } else {
                            // Собираем всю информацию о биоме
                            $biomeName    = $biomeRow['name']            ?? '???';
                            $biomeDesc    = $biomeRow['description']     ?? 'Описание недоступно';
                            $dangerLevel  = $biomeRow['danger_level']    ?? 0;
                            $survivalDiff = $biomeRow['survival_difficulty'] ?? 0;

                            // 5) Формируем текст
                            $text = "🤖 Это снова я – *Роби*!\n\n"
                                . "У тебя *нет* еще разбитого лагеря, а значит, и нет базы.\n"
                                . "Но ты сейчас находишься в ячейке:\n"
                                . "• Координаты: X={$coordX}, Y={$coordY}\n"
                                . "• Биом: *{$biomeName}*\n"
                                . "• Опасность: {$dangerLevel}\n"
                                . "• Сложность выживания: {$survivalDiff}\n"
                                . "• *Описание*: {$biomeDesc}\n\n"
                                . "Для разбивки лагеря используй кнопки ниже.";
                        }
                    }
                }

                // -- Кнопки --
                $keyboard = [
                    'inline_keyboard' => [
                        [
                            ['text' => '🏕 Разбить лагерь',    'callback_data' => 'Camp'],
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
        }

        // -------------------------------------------
        // 2) У персонажа есть лагерь
        // -------------------------------------------
        // Проверяем, находится ли персонаж на ячейке своей базы
        if ($claimedCell['map_cell_id'] != $character['cell_number']) {
            // Персонаж НЕ на своей базе
            $mapRow = $mapModel
                ->where('cell_number', $claimedCell['map_cell_id'])
                ->first();
            if (!$mapRow) {
                return Request::sendMessage([
                    'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                    'text'    => 'Ошибка: не удалось найти карту для базы.',
                ]);
            }
            $biomeRow  = $biomeModel->where('id', $mapRow['biome_id'])->first();
            $biomeName = $biomeRow['name'] ?? '???';

            $coordX = $mapRow['coordinate_x'];
            $coordY = $mapRow['coordinate_y'];

            $text = "🤖 Это снова я – *Роби*!\n\n"
                . "Твоя база находится в другой игровой ячейке, ты не дома! "
                . "Чтобы начать строительство, вернись на базу, используя:\n"
                . "1️⃣ переезд пешком\n"
                . "2️⃣ телепорт.\n\n"
                . "📍 *Координаты базы*: x={$coordX} y={$coordY}\n"
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

        // -------------------------------------------
        // 3) Персонаж действительно находится на своей базе
        // -------------------------------------------
        $buildings = $characterBuildingModel
            ->where('character_id', $character['id'])
            ->findAll();

        $buildingCount = count($buildings);
        $totalTax      = array_sum(array_column($buildings, 'tax'));

        $mapRow = $mapModel->where('cell_number', $claimedCell['map_cell_id'])->first();
        if (!$mapRow) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Ошибка: карта не найдена (ячейка базы).',
            ]);
        }

        $biomeRow  = $biomeModel->where('id', $mapRow['biome_id'])->first();
        $biomeName = $biomeRow['name'] ?? '???';
        $coordX    = $mapRow['coordinate_x'];
        $coordY    = $mapRow['coordinate_y'];

        // Список построек
        $buildingList = '';
        foreach ($buildings as $b) {
            $bld = $buildingModel->where('id', $b['building_id'])->first();
            $bName = $bld['name_ru'] ?? 'Неизвестное строение';
            $buildingList .= "- {$bName}\n";
        }

        $text = "🤖 Это я – *Роби*!\n\n"
            . "📍 *Координаты базы*: x={$coordX} y={$coordY}\n"
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
                ],
                [
                    ['text' => '❌ Удалить базу', 'callback_data' => 'DeleteBase'],
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
