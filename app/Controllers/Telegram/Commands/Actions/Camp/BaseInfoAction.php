<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Services\Bases\BaseInfoMessageFormatter;
use App\Services\Bases\BaseLocationResolver;
use App\Services\Bases\CampCheckService;
use App\Services\Coverage\CommunicationTowerCoverageService;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

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
    private BaseInfoMessageFormatter $formatter;
    private BaseLocationResolver $resolver;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->formatter = new BaseInfoMessageFormatter();
        $this->resolver  = new BaseLocationResolver();
    }

    public function handle(): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendMessage($this->formatter->userOrCharacterNotFound());
        }

        $buildingModel          = new BuildingModel();
        $characterBuildingModel = new CharacterBuildingModel();
        $towerCoverageService   = new CommunicationTowerCoverageService();

        $claimedCell = $this->resolver->findClaimedCell((int) $character['id']);

        // 1) Если у персонажа НЕТ лагеря
        if (!$claimedCell) {
            return $this->handleNoBase($character);
        }

        // 2) Персонаж физически на базе
        if ($claimedCell['map_cell_id'] == $character['cell_number']) {
            return $this->showBaseBuildings($character, $claimedCell, $buildingModel, $characterBuildingModel);
        }

        // 3) Не на базе, проверяем вышку связи
        $coverageResult = $towerCoverageService->checkCoverage($character['id']);
        if ($coverageResult['isCovered']) {
            return $this->showBaseBuildings(
                $character, $claimedCell, $buildingModel, $characterBuildingModel, $coverageResult
            );
        }

        // 4) Не на базе, вышка не покрывает
        return $this->handleNotOnBasePhysically($claimedCell);
    }

    /**
     * Случай, когда у персонажа вообще нет базы.
     */
    protected function handleNoBase(array|\App\Entities\CharacterEntity $character): ServerResponse
    {
        $cellNumber       = (int) ($character['cell_number'] ?? 0);
        $campCheckService = new CampCheckService();

        if ($cellNumber && $campCheckService->isCellClaimedByAnyone($cellNumber)) {
            return $this->sendMessage($this->formatter->noBaseCellTaken());
        }

        if (!$cellNumber) {
            return $this->sendMessage($this->formatter->noBaseNoCellNumber());
        }

        $mapRow = $this->resolver->findMapRow($cellNumber);
        if (!$mapRow) {
            return $this->sendMessage($this->formatter->noBaseMapNotFound($cellNumber));
        }

        $coordX  = (int) $mapRow['coordinate_x'];
        $coordY  = (int) $mapRow['coordinate_y'];
        $biomeId = (int) $mapRow['biome_id'];
        $biomeRow = $this->resolver->findBiomeRow($biomeId);

        if (!$biomeRow) {
            return $this->sendMessage($this->formatter->noBaseBiomeNotFound($coordX, $coordY, $biomeId));
        }

        return $this->sendMessage($this->formatter->noBaseHappyPath($coordX, $coordY, $biomeRow));
    }

    /**
     * Случай, когда у персонажа есть база, но он не на её клетке
     * и сигнал вышки связи НЕ дотягивается.
     *
     * @param array<string, mixed> $claimedCell
     */
    protected function handleNotOnBasePhysically(array $claimedCell): ServerResponse
    {
        $mapRow = $this->resolver->findMapRow((int) $claimedCell['map_cell_id']);
        if (!$mapRow) {
            return $this->sendMessage($this->formatter->notOnBaseMapError());
        }

        $biomeRow  = $this->resolver->findBiomeRow((int) $mapRow['biome_id']);
        $biomeName = (string) ($biomeRow['name'] ?? '???');
        $coordX    = (int) $mapRow['coordinate_x'];
        $coordY    = (int) $mapRow['coordinate_y'];

        $payload   = $this->formatter->notOnBasePhysically($coordX, $coordY, $biomeName);
        $imagePath = base_url('uploads/telegram/camp/an_empty_area.jpg');

        return Request::sendPhoto([
            'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $payload['caption'],
            'parse_mode'   => $payload['parse_mode'],
            'reply_markup' => $payload['reply_markup'],
        ]);
    }

    /**
     * Случай, когда персонаж находится на базе ИЛИ в зоне покрытия вышки связи.
     *
     * @param array<string, mixed> $claimedCell
     * @param array<string, mixed>|null $coverageResult
     */
    protected function showBaseBuildings(
        array|\App\Entities\CharacterEntity $character,
        array $claimedCell,
        BuildingModel $buildingModel,
        CharacterBuildingModel $characterBuildingModel,
        ?array $coverageResult = null
    ): ServerResponse {
        $buildings = $characterBuildingModel->where('character_id', $character['id'])->findAll();

        $mapRow = $this->resolver->findMapRow((int) $claimedCell['map_cell_id']);
        if (!$mapRow) {
            return $this->sendMessage($this->formatter->baseMapNotFound());
        }

        $biomeRow  = $this->resolver->findBiomeRow((int) $mapRow['biome_id']);
        $biomeName = (string) ($biomeRow['name'] ?? '???');
        $coordX    = (int) $mapRow['coordinate_x'];
        $coordY    = (int) $mapRow['coordinate_y'];

        $buildingCount = count($buildings);
        $totalTax      = (int) array_sum(array_column($buildings, 'tax'));

        $buildingList = '';
        foreach ($buildings as $b) {
            $bld   = $buildingModel->where('id', $b['building_id'])->first();
            $bName = $bld['name_ru'] ?? 'Неизвестное строение';
            $buildingList .= "- {$bName}\n";
        }

        $payload = $this->formatter->baseBuildings(
            $coordX, $coordY, $biomeName, $buildingCount, $totalTax, $buildingList, $coverageResult
        );
        $imagePath = base_url('uploads/telegram/camp/base_with_its_buildings.jpg');

        return Request::sendPhoto([
            'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $payload['caption'],
            'parse_mode'   => $payload['parse_mode'],
            'reply_markup' => $payload['reply_markup'],
        ]);
    }

    /**
     * Send pre-built formatter payload (sendMessage variant).
     *
     * @param array<string, mixed> $payload
     */
    private function sendMessage(array $payload): ServerResponse
    {
        $payload['chat_id'] = $this->callbackQuery->getMessage()->getChat()->getId();
        return Request::sendMessage($payload);
    }
}
