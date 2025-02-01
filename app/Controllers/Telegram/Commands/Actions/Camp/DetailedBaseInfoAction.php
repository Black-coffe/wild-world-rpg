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

class DetailedBaseInfoAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        // Отправляем ответ на CallbackQuery сразу (закрываем "часики")
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
                . "У тебя нет ещё разбитого лагеря, а значит и нет базы. Для разбивки лагеря используй кнопки ниже.";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🏕 Разбить лагерь', 'callback_data' => 'Camp'],
                        ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions']
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
            $biomeRow = $biomeModel->find($mapRow['biome_id']);
            $biomeName = $biomeRow['name'] ?? '???';

            $coordinates = [
                'x' => $mapRow['coordinate_x'],
                'y' => $mapRow['coordinate_y']
            ];

            $text = "🤖 Это снова я – *Роби*!\n\n"
                . "Твоя база находится в другой игровой ячейке, ты не дома! Чтобы начать строительство, вернись на базу, используя:\n\n"
                . "1️⃣ переезд пешком\n2️⃣ телепорт на выбор\n\n"
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
            $imagePath = base_url('uploads/telegram/camp/an_empty_area.jpg'); // Укажите корректный путь к изображению

            return Request::sendPhoto([
                'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
                'photo'      => Request::encodeFile($imagePath),
                'caption'    => $text,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        }

        // Получаем список построек
        $buildings = $characterBuildingModel
            ->where('character_id', $character['id'])
            ->where('map_cell_id', $claimedCell['map_cell_id'])
            ->findAll();

        if (empty($buildings)) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => "На вашей базе нет построек.",
                'parse_mode' => 'Markdown'
            ]);
        }

        // Получаем данные о карте и биоме
        $mapRow = $mapModel->where('cell_number', $claimedCell['map_cell_id'])->first();
        $biomeRow = $biomeModel->find($mapRow['biome_id']);
        $biomeName = $biomeRow['name'] ?? '???';
        $coordinates = [
            'x' => $mapRow['coordinate_x'],
            'y' => $mapRow['coordinate_y']
        ];

        $text = "Перед тобой территория твоей базы – твои владения, твоя мощь и сила. Здесь ты можешь изучить более подробно каждое сооружение и его возможности.\n\n"
            . "*Координаты базы*: x={$coordinates['x']} y={$coordinates['y']}\n"
            . "*Биом*: {$biomeName}\n\n"
            . "Список построек:";

        $keyboardButtons = [];
        foreach ($buildings as $building) {
            $bInfo = $buildingModel->find($building['building_id']);
            if (!$bInfo) {
                // Если нет информации о здании, пропускаем
                continue;
            }
            $buildingNameRu = $bInfo['name_ru'] ?? 'Неизвестное строение';
            $buildingNameEn = $bInfo['name_en'] ?? 'unknown';
            $buildingIcon   = $this->getBuildingIcon($bInfo['id']);

            $keyboardButtons[] = [
                'text'          => "{$buildingIcon} {$buildingNameRu}",
                'callback_data' => "building_{$bInfo['id']}_{$buildingNameEn}"
            ];
        }

        // Разбиваем кнопки по 2 в ряд (можно регулировать по вкусу)
        $keyboard = array_chunk($keyboardButtons, 2);

        $imagePath = base_url('uploads/telegram/camp/base_with_its_buildings.jpg'); // Укажите актуальный путь к изображению

        return Request::sendPhoto([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ]);
    }

    /**
     * Получает иконку для строения по его ID.
     *
     * @param int $buildingId
     * @return string
     */
    private function getBuildingIcon(int $buildingId): string
    {
        $icons = [
            1 => '🚰',  // Ручная скважина (HandPump)
            2 => '🔥',  // Доменная печь (BlastFurnace)
            3 => '🏚️', // Склад (Warehouse)
            4 => '🔧',  // Мастерская (Workshop)
            5 => '🌱',  // Теплица (Greenhouse)
            6 => '☀️',  // Солнечная станция (SolarStation)
            7 => '🥊',  // Спортзал (Gym)
            8 => '🥼',  // Лаборатория (Laboratory)
            9 => '🤖',  // Мастерская робототехники (RoboticsWorkshop)
            10 => '🌀'  // Центр телепортации (TeleportationCenter) — новое здание
        ];

        return $icons[$buildingId] ?? '🏠';  // Возвращает дефолтную иконку, если ID не найден
    }
}
