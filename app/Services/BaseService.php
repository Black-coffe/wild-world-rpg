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

// Подключаем сервис для проверки занятости ячейки (используется в showCampCreation)
use App\Services\Bases\CampCheckService;

/**
 * Класс BaseService, в котором мы используем
 * логику по работе с базой/лагерем.
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
     * Показывает информацию о базе, если у персонажа она есть.
     * Если базы нет – предлагает разбить лагерь, + показывает биом и его характеристики.
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

        // --------------------------------------------------------------------
        // Если у персонажа НЕТ лагеря (базы)
        // --------------------------------------------------------------------
        if (!$claimedCell) {
            // Подготовим строку с подробной информацией о текущем биоме
            // (где сейчас персонаж), если возможно получить cell_number.
            $cellNumber = $characterRow['cell_number'] ?? 0;

            // Значения по умолчанию (если не найдём ничего в map)
            $coordX = '???';
            $coordY = '???';
            $biomeName = '???';
            $biomeDesc = '';
            $dangerLevel = 0;
            $survivalDiff = 0;

            if ($cellNumber) {
                $mapRow = $this->mapModel
                    ->where('cell_number', $cellNumber)
                    ->first();
                if ($mapRow) {
                    $coordX = $mapRow['coordinate_x'];
                    $coordY = $mapRow['coordinate_y'];

                    // Получаем биом
                    $biomeId = $mapRow['biome_id'];
                    $biomeRow = $this->biomeModel->find($biomeId);
                    if ($biomeRow) {
                        $biomeName    = $biomeRow['name'] ?? '???';
                        $biomeDesc    = $biomeRow['description'] ?? '';
                        $dangerLevel  = $biomeRow['danger_level'] ?? 0;
                        $survivalDiff = $biomeRow['survival_difficulty'] ?? 0;
                    }
                }
            }

            // Формируем текст для ситуации "базы нет"
            $text = "🤖 Это снова я – *Роби*!\n\n"
                . "У тебя *нет* ещё разбитого лагеря, а значит и нет базы.\n\n"
                . "Ты сейчас находишься в локации:\n"
                . "• Координаты: X={$coordX}, Y={$coordY}\n"
                . "• Биом: *{$biomeName}*\n"
                . "• Опасность: {$dangerLevel}\n"
                . "• Сложность выживания: {$survivalDiff}\n";

            // Если есть описание биома, добавим
            if ($biomeDesc) {
                $text .= "• _Описание_: {$biomeDesc}\n\n";
            } else {
                $text .= "\n";
            }

            $text .= "Для разбивки лагеря используй кнопку ниже:";

            // Кнопка «Разбить лагерь»
            $keyboard = [
                'inline_keyboard' => [
                    [
                        // Нажатие этой кнопки будет обрабатывать колбэк 'Camp',
                        // где мы можем вызвать showCampCreation(...)
                        ['text' => '🏕 Разбить лагерь', 'callback_data' => 'Camp'],
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

        // --------------------------------------------------------------------
        // 2. Проверяем, находится ли персонаж на ячейке базы
        // --------------------------------------------------------------------
        if ($claimedCell['map_cell_id'] !== $characterRow['cell_number']) {
            // Если персонаж НЕ на своей базе, готовим другое сообщение
            $mapRow = $this->mapModel
                ->where('cell_number', $claimedCell['map_cell_id'])
                ->first();
            if (!$mapRow) {
                return Request::sendMessage([
                    'chat_id' => $chatId,
                    'text'    => 'Ошибка: не удалось найти карту для базы.',
                ]);
            }

            $biomeRow  = $this->biomeModel->where('id', $mapRow['biome_id'])->first();
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

        // --------------------------------------------------------------------
        // 3. Если персонаж действительно на базе → показываем список построек
        // --------------------------------------------------------------------
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
        $totalTax      = array_sum(array_column($buildings, 'tax'));

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

    /**
     * Метод, который вызывается после нажатия на «🏕 Разбить лагерь» (колбэк 'Camp'),
     * чтобы показать информацию о текущей ячейке и предупредить игрока.
     *
     * @param int   $chatId       ID чата
     * @param array $characterRow данные персонажа (cell_number и т.д.)
     * @return ServerResponse
     */
    public function showCampCreation(int $chatId, array $characterRow): ServerResponse
    {
        $cellNumber = $characterRow['cell_number'] ?? 0;
        if (!$cellNumber) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Ошибка: у персонажа нет cell_number!',
            ]);
        }

        // 1) Проверяем, есть ли уже база у самого игрока (active)
        $existingCamp = $this->claimedCellModel
            ->where('character_id', $characterRow['id'])
            ->where('status', 'active')
            ->first();

        if ($existingCamp) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "У вас уже есть активная база. Сначала удалите её, чтобы создать новую.",
            ]);
        }

        // 2) Проверяем, не занята ли эта ячейка другим игроком
        $campCheckService = new CampCheckService();
        if ($campCheckService->isCellClaimedByAnyone($cellNumber)) {
            $text = "❗ Невозможно разбить лагерь: эта территория уже занята другим игроком.";
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => $text,
            ]);
        }

        // 3) Ячейка свободна — получаем координаты и биом
        $mapRow = $this->mapModel
            ->where('cell_number', $cellNumber)
            ->first();

        if (!$mapRow) {
            $text = "Ошибка: не найдена карта для cell_number={$cellNumber}";
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => $text,
            ]);
        }

        $x       = $mapRow['coordinate_x'];
        $y       = $mapRow['coordinate_y'];
        $biomeId = $mapRow['biome_id'];

        $biomeRow  = $this->biomeModel->find($biomeId);
        $biomeName = $biomeRow['name'] ?? '???';

        // 4) Предупреждаем о последствиях
        $text = "Ты собираешься разбить лагерь на клетке (X={$x}, Y={$y}), биом: *{$biomeName}*.\n\n"
            . "Разбивка лагеря – серьёзный шаг. Если потом снести базу,\n"
            . "все построенные сооружения будут утеряны!\n\n"
            . "Подтверждаешь создание лагеря?";

        // Кнопки подтверждения/отмены
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ Подтвердить', 'callback_data' => 'CampCreateConfirm'],
                    ['text' => '❌ Отмена',      'callback_data' => 'CancelCamp'],
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
}
