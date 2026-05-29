<?php

namespace App\Services;

use App\Models\ClaimedCellModel;
use App\Services\Bases\BaseBuildingsList;
use App\Services\Bases\BaseLocationResolver;
use App\Services\Bases\BaseServiceMessageFormatter;
use App\Services\Bases\CampCheckService;
use App\Services\Coverage\CommunicationTowerCoverageService;
use App\Services\Housing\BaseCampDecorService;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * Класс BaseService — логика по работе с базой/лагерем.
 *
 * v0.51.85 (decomp 5/5 closed) — orchestrator з 3 SRP services + ClaimedCellModel:
 *   BaseServiceMessageFormatter   Markdown templates + inline keyboards
 *   BaseLocationResolver          claimed_cell + map + biome lookups
 *   BaseBuildingsList             building enumeration + count + tax
 *   + CampCheckService (existing) + CommunicationTowerCoverageService (existing)
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
    private const PHOTO_NOT_ON_BASE = 'uploads/telegram/camp/an_empty_area.jpg';
    private const PHOTO_BASE        = 'uploads/telegram/camp/base_with_its_buildings.jpg';

    protected ClaimedCellModel $claimedCellModel;
    protected CommunicationTowerCoverageService $towerCoverageService;
    protected CampCheckService $campCheck;
    protected BaseServiceMessageFormatter $formatter;
    protected BaseLocationResolver $resolver;
    protected BaseBuildingsList $buildingsList;

    public function __construct()
    {
        $this->claimedCellModel     = new ClaimedCellModel();
        $this->towerCoverageService = new CommunicationTowerCoverageService();
        $this->campCheck            = new CampCheckService();
        $this->formatter            = new BaseServiceMessageFormatter();
        $this->resolver             = new BaseLocationResolver();
        $this->buildingsList        = new BaseBuildingsList();
    }

    /**
     * Показывает информацию о базе. 4-branch dispatcher.
     */
    public function showBaseInfo(int $chatId, array|\App\Entities\CharacterEntity $characterRow, ?int $editMessageId = null): ServerResponse
    {
        $claimedCell = $this->claimedCellModel
            ->where('character_id', $characterRow['id'])
            ->first();

        if (!$claimedCell) {
            return $this->showNoBaseInfo($chatId, $characterRow, $editMessageId);
        }

        // v0.51.59 hotfix (F1.4.4-B 10th occurrence): explicit (int) cast.
        // Раніше strict `===` між string `$claimedCell['map_cell_id']` (raw SQL row)
        // і int `$characterRow['cell_number']` (CharacterEntity post-F1.4.2) — завжди false.
        if ((int) $claimedCell['map_cell_id'] === (int) $characterRow['cell_number']) {
            return $this->showBaseBuildings($chatId, $characterRow, $claimedCell, null, $editMessageId);
        }

        $coverage = $this->towerCoverageService->checkCoverage($characterRow['id']);
        if ($coverage['isCovered']) {
            return $this->showBaseBuildings($chatId, $characterRow, $claimedCell, $coverage, $editMessageId);
        }

        return $this->showNotOnBaseInfo($chatId, $claimedCell, $editMessageId);
    }

    /**
     * Метод, який вызывається після натиску на «🏕 Разбить лагерь» (callback 'Camp').
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

        if ($this->campCheck->isCellClaimedByAnyone($cellNumber)) {
            return $this->sendMessage($chatId, $this->formatter->campCellTakenError());
        }

        $mapRow = $this->resolver->findMapRow($cellNumber);
        if (!$mapRow) {
            return $this->sendMessage($chatId, $this->formatter->campMapNotFoundError($cellNumber));
        }

        $biomeRow = $this->resolver->findBiomeRow((int) $mapRow['biome_id']);

        return $this->sendMessage($chatId, $this->formatter->campCreationConfirm(
            $mapRow['coordinate_x'],
            $mapRow['coordinate_y'],
            (string) ($biomeRow['name'] ?? '???'),
        ));
    }

    /**
     * Показывает ситуацию, когда у игрока нет базы.
     */
    protected function showNoBaseInfo(int $chatId, array|\App\Entities\CharacterEntity $characterRow, ?int $editMessageId = null): ServerResponse
    {
        $cellNumber   = (int) ($characterRow['cell_number'] ?? 0);
        $coordX       = '???';
        $coordY       = '???';
        $biomeName    = '???';
        $biomeDesc    = '';
        $dangerLevel  = 0;
        $survivalDiff = 0;

        if ($cellNumber && ($mapRow = $this->resolver->findMapRow($cellNumber))) {
            $coordX = $mapRow['coordinate_x'];
            $coordY = $mapRow['coordinate_y'];

            if ($biomeRow = $this->resolver->findBiomeRow((int) $mapRow['biome_id'])) {
                $biomeName    = $biomeRow['name']               ?? '???';
                $biomeDesc    = $biomeRow['description']        ?? '';
                $dangerLevel  = (int) ($biomeRow['danger_level'] ?? 0);
                $survivalDiff = (int) ($biomeRow['survival_difficulty'] ?? 0);
            }
        }

        return $this->sendMessage($chatId, $this->formatter->noBaseInfo(
            $coordX, $coordY, (string) $biomeName, (string) $biomeDesc, $dangerLevel, $survivalDiff
        ), $editMessageId);
    }

    /**
     * Показывает ситуацию, когда у игрока есть база, но он НЕ находится физически (і нет покрытия).
     *
     * @param array<string, mixed> $claimedCell
     */
    protected function showNotOnBaseInfo(int $chatId, array $claimedCell, ?int $editMessageId = null): ServerResponse
    {
        $mapRow = $this->resolver->findMapRow((int) $claimedCell['map_cell_id']);
        if (!$mapRow) {
            return $this->sendMessage($chatId, $this->formatter->notOnBaseMapError(), $editMessageId);
        }

        $biomeRow = $this->resolver->findBiomeRow((int) $mapRow['biome_id']);

        return $this->sendPhoto(
            $chatId,
            self::PHOTO_NOT_ON_BASE,
            $this->formatter->notOnBasePhysically(
                $mapRow['coordinate_x'],
                $mapRow['coordinate_y'],
                (string) ($biomeRow['name'] ?? '???'),
            ),
            $editMessageId,
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
        ?array $coverageResult = null,
        ?int $editMessageId = null
    ): ServerResponse {
        $mapRow = $this->resolver->findMapRow((int) $claimedCell['map_cell_id']);
        if (!$mapRow) {
            return $this->sendMessage($chatId, $this->formatter->baseMapNotFoundError(), $editMessageId);
        }

        $biomeRow = $this->resolver->findBiomeRow((int) $mapRow['biome_id']);
        $summary  = $this->buildingsList->buildSummary((int) $characterRow['id']);

        // W21: декор базы (имя + флаг) и killswitch для кнопки «🎨 Декор».
        $decorSvc    = new BaseCampDecorService();
        $decor       = $decorSvc->getCampDecor((int) $characterRow['id']);
        $decorEnabled = $decorSvc->enabled();

        return $this->sendPhoto(
            $chatId,
            self::PHOTO_BASE,
            $this->formatter->baseBuildings(
                $mapRow['coordinate_x'],
                $mapRow['coordinate_y'],
                (string) ($biomeRow['name'] ?? '???'),
                $summary['count'],
                $summary['totalTax'],
                $summary['list'],
                $coverageResult,
                $decor['name'],
                $decor['flag'],
                $decorEnabled,
                $decorEnabled ? $decor : null, // W22: interior items только при включённом killswitch
            ),
            $editMessageId,
        );
    }

    /**
     * Send sendMessage payload з chat_id injection. Если передан $editMessageId —
     * редактирует это сообщение (editMessageText) с graceful fallback на новое
     * (напр. если source — photo-сообщение или старше 48ч). #12 edit-in-place (ADR-018).
     *
     * @param array<string, mixed> $payload
     */
    private function sendMessage(int $chatId, array $payload, ?int $editMessageId = null): ServerResponse
    {
        $payload['chat_id'] = $chatId;
        if ($editMessageId !== null) {
            try {
                $resp = Request::editMessageText($payload + ['message_id' => $editMessageId]);
                if ($resp->isOk()) {
                    return $resp;
                }
            } catch (\Throwable) {
                // fallthrough → новое сообщение
            }
        }
        return Request::sendMessage($payload);
    }

    /**
     * Send sendPhoto з base_url($relativePath) encode + chat_id injection. Если передан
     * $editMessageId — редактирует это сообщение (editMessageMedia/editMessageText через
     * MediaSender::editOrSend) с graceful fallback на новое. #12 edit-in-place (ADR-018).
     *
     * @param array<string, mixed> $payload з 'caption', 'parse_mode', 'reply_markup'
     */
    private function sendPhoto(int $chatId, string $relativePath, array $payload, ?int $editMessageId = null): ServerResponse
    {
        $params = [
            'chat_id'      => $chatId,
            'photo'        => Request::encodeFile(base_url($relativePath)),
            'caption'      => $payload['caption'],
            'parse_mode'   => $payload['parse_mode'],
            'reply_markup' => $payload['reply_markup'],
        ];
        if ($editMessageId !== null) {
            $params['message_id'] = $editMessageId;
            return \App\Services\Notifications\MediaSender::editOrSend($params);
        }
        return \App\Services\Notifications\MediaSender::sendPhotoOrText($params);
    }
}
