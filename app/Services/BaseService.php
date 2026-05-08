<?php

namespace App\Services;

use App\Models\BiomeModel;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\ClaimedCellModel;
use App\Models\MapModel;
use App\Services\Bases\BaseServiceMessageFormatter;
use App\Services\Bases\CampCheckService;
use App\Services\Coverage\CommunicationTowerCoverageService;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * Класс BaseService — логика по работе с базой/лагерем.
 *
 * v0.51.81 (decomp Step 1) — extract Markdown templates + keyboards у
 * BaseServiceMessageFormatter.
 *
 * Public API:
 *   showBaseInfo($chatId, $character) — main flow для 'Base' callback
 *     (через ShowBaseInfoAction). 4 branches: no-base / on-base /
 *     tower-covered / off-base.
 *   showCampCreation($chatId, $character) — flow для 'Camp' callback
 *     (через CampShowCreationAction).
 */
class BaseService
{
    protected $claimedCellModel;
    protected $mapModel;
    protected $biomeModel;
    protected $buildingModel;
    protected $characterBuildingModel;
    protected $towerCoverageService;
    protected BaseServiceMessageFormatter $formatter;

    public function __construct()
    {
        $this->claimedCellModel       = new ClaimedCellModel();
        $this->mapModel               = new MapModel();
        $this->biomeModel             = new BiomeModel();
        $this->buildingModel          = new BuildingModel();
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->towerCoverageService   = new CommunicationTowerCoverageService();
        $this->formatter              = new BaseServiceMessageFormatter();
    }

    /**
     * Показывает информацию о базе. 4-branch dispatcher.
     */
    public function showBaseInfo(int $chatId, array|\App\Entities\CharacterEntity $characterRow): ServerResponse
    {
        $claimedCell = $this->claimedCellModel
            ->where('character_id', $characterRow['id'])
            ->first();

        if (!$claimedCell) {
            return $this->showNoBaseInfo($chatId, $characterRow);
        }

        // v0.51.59 hotfix (F1.4.4-B 10th occurrence): explicit (int) cast.
        // Раніше strict `===` між string `$claimedCell['map_cell_id']` (raw SQL row)
        // і int `$characterRow['cell_number']` (CharacterEntity post-F1.4.2) — завжди false.
        $onBasePhysically = ((int) $claimedCell['map_cell_id'] === (int) $characterRow['cell_number']);
        if ($onBasePhysically) {
            return $this->showBaseBuildings($chatId, $characterRow, $claimedCell);
        }

        $coverageResult = $this->towerCoverageService->checkCoverage($characterRow['id']);
        if ($coverageResult['isCovered']) {
            return $this->showBaseBuildings($chatId, $characterRow, $claimedCell, $coverageResult);
        }

        return $this->showNotOnBaseInfo($chatId, $claimedCell);
    }

    /**
     * Показывает ситуацию, когда у игрока нет базы.
     */
    protected function showNoBaseInfo(int $chatId, array|\App\Entities\CharacterEntity $characterRow): ServerResponse
    {
        $cellNumber  = $characterRow['cell_number'] ?? 0;
        $coordX      = '???';
        $coordY      = '???';
        $biomeName   = '???';
        $biomeDesc   = '';
        $dangerLevel = 0;
        $survivalDiff = 0;

        if ($cellNumber) {
            $mapRow = $this->mapModel->where('cell_number', $cellNumber)->first();
            if ($mapRow) {
                $coordX = $mapRow['coordinate_x'];
                $coordY = $mapRow['coordinate_y'];

                $biomeRow = $this->biomeModel->find($mapRow['biome_id']);
                if ($biomeRow) {
                    $biomeName    = $biomeRow['name']               ?? '???';
                    $biomeDesc    = $biomeRow['description']        ?? '';
                    $dangerLevel  = (int) ($biomeRow['danger_level'] ?? 0);
                    $survivalDiff = (int) ($biomeRow['survival_difficulty'] ?? 0);
                }
            }
        }

        return $this->sendMessage($chatId, $this->formatter->noBaseInfo(
            $coordX, $coordY, (string) $biomeName, (string) $biomeDesc, $dangerLevel, $survivalDiff
        ));
    }

    /**
     * Показывает ситуацию, когда у игрока есть база, но он НЕ находится физически (и нет покрытия).
     *
     * @param array<string, mixed> $claimedCell
     */
    protected function showNotOnBaseInfo(int $chatId, array $claimedCell): ServerResponse
    {
        $mapRow = $this->mapModel->where('cell_number', $claimedCell['map_cell_id'])->first();
        if (!$mapRow) {
            return $this->sendMessage($chatId, $this->formatter->notOnBaseMapError());
        }

        $biomeRow  = $this->biomeModel->where('id', $mapRow['biome_id'])->first();
        $biomeName = $biomeRow['name'] ?? '???';

        return $this->sendPhoto(
            $chatId,
            base_url('uploads/telegram/camp/an_empty_area.jpg'),
            $this->formatter->notOnBasePhysically(
                $mapRow['coordinate_x'],
                $mapRow['coordinate_y'],
                (string) $biomeName,
            ),
        );
    }

    /**
     * Показывает список построек (физически OR удалённо через вышку связи).
     *
     * @param array<string, mixed> $claimedCell
     * @param array<string, mixed>|null $coverageResult
     */
    protected function showBaseBuildings(
        int $chatId,
        array|\App\Entities\CharacterEntity $characterRow,
        array $claimedCell,
        ?array $coverageResult = null
    ): ServerResponse {
        $buildings = $this->characterBuildingModel
            ->where('character_id', $characterRow['id'])
            ->findAll();

        $mapRow = $this->mapModel->where('cell_number', $claimedCell['map_cell_id'])->first();
        if (!$mapRow) {
            return $this->sendMessage($chatId, $this->formatter->baseMapNotFoundError());
        }

        $biomeRow  = $this->biomeModel->where('id', $mapRow['biome_id'])->first();
        $biomeName = $biomeRow['name'] ?? '???';

        $buildingCount = count($buildings);
        $totalTax      = (int) array_sum(array_column($buildings, 'tax'));

        $buildingList = '';
        foreach ($buildings as $b) {
            $bld   = $this->buildingModel->find($b['building_id']);
            $bName = $bld['name_ru'] ?? 'Неизвестное строение';
            $buildingList .= "- {$bName}\n";
        }

        return $this->sendPhoto(
            $chatId,
            base_url('uploads/telegram/camp/base_with_its_buildings.jpg'),
            $this->formatter->baseBuildings(
                $mapRow['coordinate_x'],
                $mapRow['coordinate_y'],
                (string) $biomeName,
                $buildingCount,
                $totalTax,
                $buildingList,
                $coverageResult,
            ),
        );
    }

    /**
     * Метод, який вызывается після нажатия на «🏕 Разбить лагерь» (callback 'Camp').
     */
    public function showCampCreation(int $chatId, array|\App\Entities\CharacterEntity $characterRow): ServerResponse
    {
        $cellNumber = (int) ($characterRow['cell_number'] ?? 0);
        if (!$cellNumber) {
            return $this->sendMessage($chatId, $this->formatter->cellNumberMissingError());
        }

        $existingCamp = $this->claimedCellModel
            ->where('character_id', $characterRow['id'])
            ->where('status', 'active')
            ->first();
        if ($existingCamp) {
            return $this->sendMessage($chatId, $this->formatter->alreadyHaveActiveCampError());
        }

        $campCheckService = new CampCheckService();
        if ($campCheckService->isCellClaimedByAnyone($cellNumber)) {
            return $this->sendMessage($chatId, $this->formatter->campCellTakenError());
        }

        $mapRow = $this->mapModel->where('cell_number', $cellNumber)->first();
        if (!$mapRow) {
            return $this->sendMessage($chatId, $this->formatter->campMapNotFoundError($cellNumber));
        }

        $biomeRow  = $this->biomeModel->find($mapRow['biome_id']);
        $biomeName = $biomeRow['name'] ?? '???';

        return $this->sendMessage($chatId, $this->formatter->campCreationConfirm(
            $mapRow['coordinate_x'],
            $mapRow['coordinate_y'],
            (string) $biomeName,
        ));
    }

    /**
     * Send sendMessage payload з chat_id injection.
     *
     * @param array<string, mixed> $payload
     */
    private function sendMessage(int $chatId, array $payload): ServerResponse
    {
        $payload['chat_id'] = $chatId;
        return Request::sendMessage($payload);
    }

    /**
     * Send sendPhoto з photo URL encode + chat_id injection.
     *
     * @param array<string, mixed> $payload з 'caption', 'parse_mode', 'reply_markup'
     */
    private function sendPhoto(int $chatId, string $photoUrl, array $payload): ServerResponse
    {
        return Request::sendPhoto([
            'chat_id'      => $chatId,
            'photo'        => Request::encodeFile($photoUrl),
            'caption'      => $payload['caption'],
            'parse_mode'   => $payload['parse_mode'],
            'reply_markup' => $payload['reply_markup'],
        ]);
    }
}
