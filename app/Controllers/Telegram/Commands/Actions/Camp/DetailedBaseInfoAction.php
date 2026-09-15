<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\ClaimedCellModel;
use App\Models\MapModel;
use App\Models\BiomeModel;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Services\Bases\BaseCallbackSuffix;
use App\Services\Bases\BaseScopeResolver;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

// >>> Подключаем сервис вышки связи <<<
use App\Services\Coverage\CommunicationTowerCoverageService;

class DetailedBaseInfoAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        // Сразу отвечаем на CallbackQuery (убираем «часики» в Telegram)
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);

        // Достаём данные о пользователе / персонаже
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'       => "🤖 Это снова я – *Роби*!\n\nПользователь не найден в базе данных или персонаж не определён.",
                'parse_mode' => 'Markdown'
            ]);
        }

        // Модели
        $claimedCellModel       = new ClaimedCellModel();
        $mapModel               = new MapModel();
        $biomeModel             = new BiomeModel();
        $buildingModel          = new BuildingModel();
        $characterBuildingModel = new CharacterBuildingModel();

        // >>> Создаём сервис проверки вышки связи
        $towerService           = new CommunicationTowerCoverageService();

        $characterId = (int) $character['id'];
        $currentCell = (int) ($character['cell_number'] ?? 0);

        // multibase-picker-02: `construction_b<id>` — база выбрана явно (с экрана
        // пикера/базы), доступность проверяется заново через `resolveForBase()`.
        [, $suffixBaseId] = BaseCallbackSuffix::split((string) $this->callbackQuery->getData());
        if ($suffixBaseId !== null) {
            $resolved = (new BaseScopeResolver())->resolveForBase($characterId, $currentCell, $suffixBaseId);
            if ($resolved['reason'] === BaseScopeResolver::REASON_UNAVAILABLE) {
                return Request::sendMessage([
                    'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                    'text'    => $resolved['text'],
                ]);
            }

            $claimedCell = $claimedCellModel->find($suffixBaseId);
            if (! is_array($claimedCell)) {
                return Request::sendMessage([
                    'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                    'text'    => BaseScopeResolver::TEXT_UNAVAILABLE,
                ]);
            }

            $coverageResult = $resolved['reason'] === BaseScopeResolver::REASON_TOWER
                ? $this->coverageResultForBase($towerService, $characterId, $currentCell, $suffixBaseId)
                : null;

            return $this->showBuildings($character, $claimedCell, $mapModel, $biomeModel, $buildingModel, $characterBuildingModel, $coverageResult, $suffixBaseId);
        }

        // ADR-095 Фаза 1b: «активная база» — если игрок стоит на ОДНОЙ ИЗ своих баз,
        // показываем ИМЕННО её постройки (а не всегда первую). Чинит мульти-бэйс:
        // на 2-й базе раньше показывалась 1-я (или «не на базе»).
        $activeCell = $claimedCellModel->findActiveCell($characterId, $currentCell);
        if ($activeCell !== null) {
            $activeCellIdRaw = $activeCell['id'] ?? null;
            $activeCellId    = is_numeric($activeCellIdRaw) ? (int) $activeCellIdRaw : 0;
            return $this->showBuildings($character, $activeCell, $mapModel, $biomeModel, $buildingModel, $characterBuildingModel, null, $activeCellId);
        }

        // Не на базе физически — берём первую АКТИВНУЮ базу (по `id`) для дистанционного
        // просмотра / координат. story angela-second-base-bugs-07: раньше `first()` без
        // `status`/`orderBy` мог отдать заброшенную базу, пока рядом стоит живая.
        $activeCells = $claimedCellModel->findAllActiveCells($characterId);
        $claimedCell = $activeCells[0] ?? null;

        // Если активных баз нет вообще (is_array нарроуит для showBuildings: returnType='array').
        if (! is_array($claimedCell)) {
            return $this->handleNoBase($character);
        }

        // Не на базе, проверяем сигнал вышки
        $coverageResult = $towerService->checkCoverage($characterId);
        if ($coverageResult['isCovered']) {
            $claimedCellIdRaw = $claimedCell['id'] ?? null;
            $claimedCellId    = is_numeric($claimedCellIdRaw) ? (int) $claimedCellIdRaw : 0;
            // Покрывает вышка → можно дистанционно посмотреть постройки
            return $this->showBuildings(
                $character,
                $claimedCell,
                $mapModel,
                $biomeModel,
                $buildingModel,
                $characterBuildingModel,
                $coverageResult,
                $claimedCellId
            );
        }

        // Иначе нет покрытия — старое поведение
        return $this->handleNotOnBasePhysically($character, $claimedCell, $mapModel, $biomeModel);
    }

    /**
     * {@see CommunicationTowerCoverageService::coverageByBase()} строка → форма `checkCoverage()`, которую читает `showBuildings()`.
     *
     * @return array{isCovered:bool,towerLevel:int,distanceToBase:int,maxCoverage:int}
     */
    private function coverageResultForBase(CommunicationTowerCoverageService $towerService, int $characterId, int $currentCell, int $baseId): array
    {
        foreach ($towerService->coverageByBase($characterId, $currentCell) as $row) {
            if ($row['base_id'] === $baseId) {
                return [
                    'isCovered'      => $row['isCovered'],
                    'towerLevel'     => $row['towerLevel'],
                    'distanceToBase' => $row['distance'],
                    'maxCoverage'    => $row['maxCoverage'],
                ];
            }
        }
        return ['isCovered' => false, 'towerLevel' => 0, 'distanceToBase' => 0, 'maxCoverage' => 0];
    }

    /**
     * Случай: у персонажа нет базы вообще.
     */
    protected function handleNoBase(array|\App\Entities\CharacterEntity $character): ServerResponse
    {
        $text = "🤖 Это снова я – *Роби*!\n\n"
            . "У тебя нет ещё разбитого лагеря, а значит и нет базы. "
            . "Для разбивки лагеря используй кнопки ниже.";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🏕 Разбить лагерь', 'callback_data' => 'Camp'],
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions']
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
     * Случай: у персонажа есть база, но он НЕ на ней и нет покрытия вышки.
     */
    protected function handleNotOnBasePhysically(
        array|\App\Entities\CharacterEntity $character,
        array $claimedCell,
        MapModel $mapModel,
        BiomeModel $biomeModel
    ): ServerResponse
    {
        $mapRow = $mapModel->where('cell_number', $claimedCell['map_cell_id'])->first();
        if (!$mapRow) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Ошибка: не удалось найти карту для базы.',
            ]);
        }

        $biomeRow  = $biomeModel->find($mapRow['biome_id']);
        $biomeName = $biomeRow['name'] ?? '???';
        $coordX    = $mapRow['coordinate_x'];
        $coordY    = $mapRow['coordinate_y'];

        $text = "🤖 Это снова я – *Роби*!\n\n"
            . "Твоя база находится в другой игровой ячейке, ты не дома! "
            . "Чтобы начать строительство или изучить сооружения, вернись на базу:\n"
            . "1️⃣ пешком\n"
            . "2️⃣ телепорт.\n\n"
            . "📍 *Координаты базы*: x={$coordX} y={$coordY}\n"
            . "🌍 *Биом*: {$biomeName}";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📡 Телепорт', 'callback_data' => 'TeleportToCamp'],
                    ['text' => '🧭 Двигаться', 'callback_data' => 'move'],
                ],
            ]
        ];
        $imagePath = base_url('uploads/telegram/camp/an_empty_area.jpg');

        return \App\Services\Notifications\MediaSender::sendPhotoOrText([
            'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Случай: игрок физически на базе ИЛИ покрывает вышка связи.
     * Если $coverageResult['isCovered'] === true, добавляем пометку «дистанционно» в текст.
     */
    protected function showBuildings(
        array|\App\Entities\CharacterEntity $character,
        array $claimedCell,
        MapModel $mapModel,
        BiomeModel $biomeModel,
        BuildingModel $buildingModel,
        CharacterBuildingModel $characterBuildingModel,
        ?array $coverageResult = null,
        ?int $baseId = null
    ): ServerResponse
    {
        // multibase-picker-02: base_id всегда известен (claimedCell всегда несёт своё 'id').
        $resolvedBaseId = $baseId ?? (is_numeric($claimedCell['id'] ?? null) ? (int) $claimedCell['id'] : 0);
        // Получаем список построек ТОЛЬКО просматриваемой базы (ADR-102: per-base).
        $buildings = $characterBuildingModel
            ->where('character_id', $character['id'])
            ->where('map_cell_id', $claimedCell['map_cell_id'])
            ->findAll();

        if (empty($buildings)) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'На вашей базе нет построек.',
                'parse_mode' => 'Markdown'
            ]);
        }

        // Достаём данные о карте/биоме
        $mapRow = $mapModel->where('cell_number', $claimedCell['map_cell_id'])->first();
        if (!$mapRow) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Ошибка: не удалось найти карту для базы.',
            ]);
        }

        $biomeRow  = $biomeModel->find($mapRow['biome_id']);
        $biomeName = $biomeRow['name'] ?? '???';
        $coordX    = $mapRow['coordinate_x'];
        $coordY    = $mapRow['coordinate_y'];

        // Формируем текст
        $introText = "Перед тобой территория твоей базы. Здесь можно подробнее изучить каждое сооружение!\n\n"
            . "*Координаты базы*: x={$coordX}, y={$coordY}\n"
            . "*Биом*: {$biomeName}\n";

        // Если есть coverageResult и isCovered=true, значит дистанционно
        if ($coverageResult && $coverageResult['isCovered']) {
            $towerLvl    = $coverageResult['towerLevel'] ?? 1;
            $distance    = $coverageResult['distanceToBase'] ?? 0;
            $maxCoverage = $coverageResult['maxCoverage'] ?? ($towerLvl * 100);

            $introText  = "_Вы не на базе физически,_ но сигнал *Вышки связи* (ур. {$towerLvl}) "
                . "покрывает расстояние {$distance}/{$maxCoverage}. "
                . "**Можно управлять сооружениями удалённо!**\n\n"
                . $introText;
        }

        $keyboardButtons = [];
        foreach ($buildings as $building) {
            $bInfo = $buildingModel->find($building['building_id']);
            $bNameRu  = $bInfo['name_ru']  ?? 'Неизвестное строение';
            $bNameEng = $bInfo['name_en']  ?? 'unknown';

            // Иконку можете сделать отдельным методом
            $icon = $this->getBuildingIcon($building['building_id']);

            // S3 (v0.51.185+): suffix L<level> на кнопці — користувач одразу
            // бачить рівень кожної споруди у списку без відкриття картки.
            // CharacterBuildingModel у F1.4 повертає Entity (ArrayAccess) → доступ через ['level'] safe.
            $lvlRaw    = is_array($building) ? ($building['level'] ?? 1) : ($building->level ?? 1);
            $lvl       = is_numeric($lvlRaw) ? max(1, (int) $lvlRaw) : 1;
            $lvlSuffix = " L{$lvl}";

            $keyboardButtons[] = [
                'text' => "{$icon} {$bNameRu}{$lvlSuffix}",
                'callback_data' => BaseCallbackSuffix::append('building_' . $building['building_id'] . '_' . $bNameEng, $resolvedBaseId),
            ];
        }

        // Организуем кнопки по 2 в ряд
        $keyboard = array_chunk($keyboardButtons, 2);

        // E18 (ADR-118) — вход в витрину «🏗 Развитие базы» (легибельность эффектов уровней построек).
        $keyboard[] = [['text' => '🏗 Развитие базы', 'callback_data' => BaseCallbackSuffix::append('baseDevelopment', $resolvedBaseId)]];

        $imagePath = base_url('uploads/telegram/camp/base_with_its_buildings.jpg');

        return \App\Services\Notifications\MediaSender::sendPhotoOrText([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $introText,
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
            1 => '🚰',  // Пример иконки для здания с ID 1
            2 => '🔥',
            3 => '🏚️',
            4 => '🔧',
            5 => '🌱',
            6 => '☀️',
            7 => '🥊',
            8 => '🥼',
            // ... Добавьте ID "Arsenal" / "CommunicationTower" и т.д. при желании
        ];

        return $icons[$buildingId] ?? '🏠';
    }
}
