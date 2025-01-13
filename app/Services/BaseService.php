<?php

namespace App\Services;

use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use DateTime;

use App\Models\ClaimedCellModel;
use App\Models\MapModel;
use App\Models\BiomeModel;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;

/**
 * Класс BaseService, в котором мы используем
 * логику из BaseInfoAction (пошагово переписанную).
 */
class BaseService
{
    protected $claimedCellModel;
    protected $mapModel;
    protected $biomeModel;
    protected $buildingModel;
    protected $characterBuildingModel;

    public function __construct()
    {
        $this->claimedCellModel       = new ClaimedCellModel();
        $this->mapModel               = new MapModel();
        $this->biomeModel             = new BiomeModel();
        $this->buildingModel          = new BuildingModel();
        $this->characterBuildingModel = new CharacterBuildingModel();
    }

    /**
     * Показывает информацию о базе, аналогично тому,
     * как это делал BaseInfoAction::handle().
     *
     * @param int   $chatId       ID чата
     * @param array $characterRow ассоциативный массив с полями персонажа
     * @return ServerResponse
     */
    public function showBaseInfo(int $chatId, array $characterRow): ServerResponse
    {
        // 1. Ищем запись о лагере (claimed_cells)
        $claimedCell = $this->claimedCellModel
            ->where('character_id', $characterRow['id'])
            ->first();

        // Если у персонажа нет лагеря (базы)
        if (!$claimedCell) {
            $text = "🤖 Это снова я – *Роби*!\n\n"
                . "У тебя нет еще разбитого лагеря, а значит и нет базы. "
                . "Для разбивки лагеря используй кнопки ниже.";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🏕 Разбить лагерь', 'callback_data' => 'Camp'],
                        ['text' => '👤 Персонаж',       'callback_data' => 'character'],
                    ],
                ]
            ];

            return Request::sendMessage([
                'chat_id'      => $chatId,
                'text'         => $text,
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        }

        // 2. Проверяем, находится ли персонаж на ячейке базы
        if ($claimedCell['map_cell_id'] !== $characterRow['cell_number']) {
            // Если персонаж НЕ на своей базе, готовим другое сообщение
            $mapRow = $this->mapModel
                ->where('cell_number', $claimedCell['map_cell_id'])
                ->first();
            if (!$mapRow) {
                // safety check, вдруг база тоже неизвестно где
                // но обычно mapRow не должен быть пуст
                return Request::sendMessage([
                    'chat_id' => $chatId,
                    'text'    => 'Ошибка: не удалось найти карту для базы.',
                ]);
            }

            $biomeRow = $this->biomeModel->where('id', $mapRow['biome_id'])->first();
            $biomeName = $biomeRow['name'] ?? '???';

            $coordX = $mapRow['coordinate_x'];
            $coordY = $mapRow['coordinate_y'];

            $text = "🤖 Это снова я – *Роби*!\n\n"
                . "Твоя база находится в другой игровой ячейке, ты не дома! "
                . "Чтобы начать строительство, вернись на базу, используя:\n\n"
                . "1️⃣ переезд пешком\n"
                . "2️⃣ телепорт на выбор\n\n"
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
                'chat_id'      => $chatId,
                'photo'        => Request::encodeFile($imagePath),
                'caption'      => $text,
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        }

        // 3. Если персонаж ДЕЙСТВИТЕЛЬНО на базе → выводим список построек
        $buildings = $this->characterBuildingModel
            ->where('character_id', $characterRow['id'])
            ->findAll();

        $mapRow = $this->mapModel
            ->where('cell_number', $claimedCell['map_cell_id'])
            ->first();
        if (!$mapRow) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Ошибка: карта не найдена (ячейка базы).',
            ]);
        }

        $biomeRow  = $this->biomeModel->where('id', $mapRow['biome_id'])->first();
        $biomeName = $biomeRow['name'] ?? '???';

        $coordX = $mapRow['coordinate_x'];
        $coordY = $mapRow['coordinate_y'];

        $buildingCount = count($buildings);
        $totalTax = array_sum(array_column($buildings, 'tax'));

        // Собираем строку списка построек
        $buildingList = '';
        foreach ($buildings as $b) {
            $bld = $this->buildingModel->where('id', $b['building_id'])->first();
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
                ],
            ]
        ];

        $imagePath = base_url('uploads/telegram/camp/base_with_its_buildings.jpg');

        return Request::sendPhoto([
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
