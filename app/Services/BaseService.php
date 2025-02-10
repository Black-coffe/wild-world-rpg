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

// >>> Добавляем наш Coverage-сервис <<<
use App\Services\Coverage\CommunicationTowerCoverageService;

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

    // + добавим сервис в конструктор (по желанию),
    //   но можно создавать инстанс CommunicationTowerCoverageService прямо в методе, по вкусу.
    protected $towerCoverageService;

    public function __construct()
    {
        $this->claimedCellModel       = new ClaimedCellModel();
        $this->mapModel               = new MapModel();
        $this->biomeModel             = new BiomeModel();
        $this->buildingModel          = new BuildingModel();
        $this->characterBuildingModel = new CharacterBuildingModel();

        // либо создаём сервис здесь:
        $this->towerCoverageService   = new CommunicationTowerCoverageService();
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
        // ЕСЛИ у персонажа НЕТ базы
        // --------------------------------------------------------------------
        if (!$claimedCell) {
            return $this->showNoBaseInfo($chatId, $characterRow);
        }

        // --------------------------------------------------------------------
        // 2. Проверяем: "игрок ФИЗИЧЕСКИ на ячейке базы"?
        // --------------------------------------------------------------------
        $onBasePhysically = ($claimedCell['map_cell_id'] === $characterRow['cell_number']);
        if ($onBasePhysically) {
            // Если да — выводим все постройки + механику
            return $this->showBaseBuildings($chatId, $characterRow, $claimedCell);
        }

        // --------------------------------------------------------------------
        // 2.1 Если игрок НЕ на базе, проверяем,
        //     покрывает ли его сигнал Вышки связи (CommunicationTower).
        // --------------------------------------------------------------------
        $coverageResult = $this->towerCoverageService->checkCoverage($characterRow['id']);
        if ($coverageResult['isCovered']) {
            // Если покрытие есть — тоже покажем постройки,
            // но добавим пометку «Вы находитесь в зоне вышки связи,
            // управляете базой удалённо!»

            return $this->showBaseBuildings($chatId, $characterRow, $claimedCell, $coverageResult);
        } else {
            // Если покрытия нет — старое поведение:
            return $this->showNotOnBaseInfo($chatId, $claimedCell);
        }
    }

    /**
     * Показывает ситуацию, когда у игрока нет базы.
     */
    protected function showNoBaseInfo(int $chatId, array $characterRow): ServerResponse
    {
        $cellNumber = $characterRow['cell_number'] ?? 0;

        // Значения по умолчанию
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

        // Формируем текст "базы нет"
        $text = "🤖 Это снова я – *Роби*!\n\n"
            . "У тебя *нет* ещё разбитого лагеря, а значит и нет базы.\n\n"
            . "Ты сейчас находишься в локации:\n"
            . "• Координаты: X={$coordX}, Y={$coordY}\n"
            . "• Биом: *{$biomeName}*\n"
            . "• Опасность: {$dangerLevel}\n"
            . "• Сложность выживания: {$survivalDiff}\n";

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

    /**
     * Показывает ситуацию, когда у игрока есть база,
     * но он НЕ находится физически (и нет покрытия).
     */
    protected function showNotOnBaseInfo(int $chatId, array $claimedCell): ServerResponse
    {
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
            . "Твоя база находится в другой ячейке, и сигнал вышки связи туда не дотягивается.\n"
            . "Чтобы начать строительство или управлять базой, вернись физически:\n\n"
            . "1️⃣ дойти пешком\n"
            . "2️⃣ телепорт\n\n"
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

    /**
     * Показывает список построек (как если бы физически),
     * но если coverageResult передан и isCovered=true,
     * добавляет фразу «Вы управляете базой дистанционно через вышку связи» и т.д.
     *
     * @param array $claimedCell — запись из claimed_cells
     * @param array|null $coverageResult — результат checkCoverage,
     *        или null, если мы действительно физически на базе
     */
    protected function showBaseBuildings(
        int $chatId,
        array $characterRow,
        array $claimedCell,
        ?array $coverageResult = null
    ): ServerResponse
    {
        // Собираем список построек
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
            $bld = $this->buildingModel->find($b['building_id']);
            $bName = $bld['name_ru'] ?? 'Неизвестное строение';
            $buildingList .= "- {$bName}\n";
        }

        // Если coverageResult != null и coverageResult['isCovered']=true,
        // значит управляем "дистанционно"
        $coverageText = "";
        if ($coverageResult && $coverageResult['isCovered']) {
            $towerLevel    = $coverageResult['towerLevel'];
            $distance      = $coverageResult['distanceToBase'];
            $maxCoverage   = $coverageResult['maxCoverage'];

            $coverageText = "\n_Вы сейчас не физически на базе,_\n"
                . "_но сигнал *Вышки связи* (ур. {$towerLevel}) покрывает *{$distance}* ходов_\n"
                . "_(лимит: {$maxCoverage} ходов)._\n"
                . "**Управление базой доступно дистанционно!**\n\n";
        }

        $text = "🤖 Это я – *Роби*!\n\n"
            // Если coverageText заполнен — добавим его
            . ($coverageText ?: '')
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
                    ['text' => '🏗 Строить',      'callback_data' => 'Build'],
                    ['text' => '🏘 Постройки',    'callback_data' => 'construction'],
                    ['text' => '📡 Маяки',        'callback_data' => 'teleportBeacon'],
                ],
                [
                    ['text' => '❌ Удалить базу', 'callback_data' => 'DeleteBase'],
                    ['text' => '🚚 Полноценный переезд', 'callback_data' => 'DeleteBase_FullRelocation'],
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

        // Кнопки подтверждения
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ Подтвердить', 'callback_data' => 'CampCreateConfirm'],
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
