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

class BaseInfoAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        // Отправляем ответ на CallbackQuery сразу
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => '🤖 Это снова я – *Роби*!\n\nПользователь не найден в базе данных или персонаж не определён.',
                'parse_mode' => 'Markdown'
            ]);
        }

        $claimedCellModel = new ClaimedCellModel();
        $mapModel = new MapModel();
        $biomeModel = new BiomeModel();
        $buildingModel = new BuildingModel();
        $characterBuildingModel = new CharacterBuildingModel();

        // Проверяем, есть ли у персонажа лагерь
        $claimedCell = $claimedCellModel->where('character_id', $character['id'])->first();

        if (!$claimedCell) {
            // У персонажа нет разбитого лагеря
            $text = "🤖 Это снова я – *Роби*!\n\n"
                . "У тебя нет еще разбитого лагеря, а значит и нет базы. Для разбивки лагеря используй кнопки ниже.";

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
                'text'    => $text,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        }

        // Проверяем, находится ли персонаж на ячейке базы
        if ($claimedCell['map_cell_id'] != $character['cell_number']) {
            $mapRow = $mapModel->where('cell_number', $claimedCell['map_cell_id'])->first();
            $biomeName = $biomeModel->where('id', $mapRow['biome_id'])->first()['name'];
            $coordinates = [
                'x' => $mapRow['coordinate_x'],
                'y' => $mapRow['coordinate_y']
            ];

            $text = "🤖 Это снова я – *Роби*!\n\n"
                . "Твоя база находится в другой игровой ячейке, ты не дома! Чтобы начать строительство вернись на базу, используя:\n\n1️⃣ переезд пешком\n2️⃣ телепорт на выбор.\n\n"
                . "📍 *Координаты базы*: x={$coordinates['x']} y={$coordinates['y']}\n"
                . "🌍 *Биом*: {$biomeName}";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '📡 Телепорт', 'callback_data' => 'TeleportToCamp'],
                        ['text' => '🚜 Переехать', 'callback_data' => 'move'],
                    ],
                ]
            ];
            $imagePath = base_url('uploads/telegram/camp/an_empty_area.jpg'); // Укажите актуальный путь к изображению

            return Request::sendPhoto([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'photo'   => Request::encodeFile($imagePath),
                'caption' => $text,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        }

        // Получаем список построек
        $buildings = $characterBuildingModel->where('character_id', $character['id'])->findAll();
        $buildingCount = count($buildings);
        $totalTax = array_sum(array_column($buildings, 'tax'));

        $mapRow = $mapModel->where('cell_number', $claimedCell['map_cell_id'])->first();
        $biomeName = $biomeModel->where('id', $mapRow['biome_id'])->first()['name'];
        $coordinates = [
            'x' => $mapRow['coordinate_x'],
            'y' => $mapRow['coordinate_y']
        ];

        $buildingList = "";
        foreach ($buildings as $building) {
            $buildingName = $buildingModel->where('id', $building['building_id'])->first()['name_ru'] ?? 'Неизвестное строение';
            $buildingList .= "- {$buildingName}\n";
        }

        $text = "🤖 Это я – *Роби*!\n\n"
            . "📍 *Координаты базы*: x={$coordinates['x']} y={$coordinates['y']}\n"
            . "🌍 *Биом*: {$biomeName}\n\n"
            . "*Твоя база содержит:*\n"
            . "*Построек:* {$buildingCount} шт.\n"
            . "*Налог:* {$totalTax} ед. золота в сутки\n\n"
            . "*Список построек:*\n"
            . "{$buildingList}";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🏗 Строить', 'callback_data' => 'Build'],
                    ['text' => '🏘 Постройки', 'callback_data' => 'construction'],
                ],
                [
                    ['text' => '❌ Удалить базу', 'callback_data' => 'DeleteBase'],
                ],
            ]
        ];

        $imagePath = base_url('uploads/telegram/camp/base_with_its_buildings.jpg'); // Укажите актуальный путь к изображению

        return Request::sendPhoto([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'   => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
