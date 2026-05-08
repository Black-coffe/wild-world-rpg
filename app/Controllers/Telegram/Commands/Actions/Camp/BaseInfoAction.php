<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Bases\BaseBuildingsList;
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
 *
 * v0.51.76 (decomp 5/5 closed) — orchestrator з 3 SRP services:
 *   BaseInfoMessageFormatter   Markdown templates + inline keyboards
 *   BaseLocationResolver       claimed_cell + map + biome lookups
 *   BaseBuildingsList          building enumeration + count + tax
 *   + CampCheckService (existing) + CommunicationTowerCoverageService (existing)
 */
class BaseInfoAction extends BaseAction
{
    private const PHOTO_NOT_ON_BASE = 'uploads/telegram/camp/an_empty_area.jpg';
    private const PHOTO_BASE        = 'uploads/telegram/camp/base_with_its_buildings.jpg';

    private BaseInfoMessageFormatter $formatter;
    private BaseLocationResolver $resolver;
    private BaseBuildingsList $buildingsList;
    private CampCheckService $campCheck;
    private CommunicationTowerCoverageService $towerCoverage;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->formatter     = new BaseInfoMessageFormatter();
        $this->resolver      = new BaseLocationResolver();
        $this->buildingsList = new BaseBuildingsList();
        $this->campCheck     = new CampCheckService();
        $this->towerCoverage = new CommunicationTowerCoverageService();
    }

    public function handle(): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendMessage($this->formatter->userOrCharacterNotFound());
        }

        $claimedCell = $this->resolver->findClaimedCell((int) $character['id']);
        if (!$claimedCell) {
            return $this->handleNoBase($character);
        }

        // На базе фізично
        if ($claimedCell['map_cell_id'] == $character['cell_number']) {
            return $this->showBaseBuildings($character, $claimedCell);
        }

        // Не на базі — перевіряємо вышку связи
        $coverage = $this->towerCoverage->checkCoverage($character['id']);
        if ($coverage['isCovered']) {
            return $this->showBaseBuildings($character, $claimedCell, $coverage);
        }

        return $this->handleNotOnBasePhysically($claimedCell);
    }

    protected function handleNoBase(array|\App\Entities\CharacterEntity $character): ServerResponse
    {
        $cellNumber = (int) ($character['cell_number'] ?? 0);

        if ($cellNumber && $this->campCheck->isCellClaimedByAnyone($cellNumber)) {
            return $this->sendMessage($this->formatter->noBaseCellTaken());
        }

        if (!$cellNumber) {
            return $this->sendMessage($this->formatter->noBaseNoCellNumber());
        }

        $mapRow = $this->resolver->findMapRow($cellNumber);
        if (!$mapRow) {
            return $this->sendMessage($this->formatter->noBaseMapNotFound($cellNumber));
        }

        $coordX   = (int) $mapRow['coordinate_x'];
        $coordY   = (int) $mapRow['coordinate_y'];
        $biomeId  = (int) $mapRow['biome_id'];
        $biomeRow = $this->resolver->findBiomeRow($biomeId);

        if (!$biomeRow) {
            return $this->sendMessage($this->formatter->noBaseBiomeNotFound($coordX, $coordY, $biomeId));
        }

        return $this->sendMessage($this->formatter->noBaseHappyPath($coordX, $coordY, $biomeRow));
    }

    /**
     * @param array<string, mixed> $claimedCell
     */
    protected function handleNotOnBasePhysically(array $claimedCell): ServerResponse
    {
        $mapRow = $this->resolver->findMapRow((int) $claimedCell['map_cell_id']);
        if (!$mapRow) {
            return $this->sendMessage($this->formatter->notOnBaseMapError());
        }

        $biomeRow = $this->resolver->findBiomeRow((int) $mapRow['biome_id']);
        $payload  = $this->formatter->notOnBasePhysically(
            (int) $mapRow['coordinate_x'],
            (int) $mapRow['coordinate_y'],
            (string) ($biomeRow['name'] ?? '???'),
        );

        return $this->sendPhoto(self::PHOTO_NOT_ON_BASE, $payload);
    }

    /**
     * @param array<string, mixed> $claimedCell
     * @param array<string, mixed>|null $coverageResult
     */
    protected function showBaseBuildings(
        array|\App\Entities\CharacterEntity $character,
        array $claimedCell,
        ?array $coverageResult = null
    ): ServerResponse {
        $mapRow = $this->resolver->findMapRow((int) $claimedCell['map_cell_id']);
        if (!$mapRow) {
            return $this->sendMessage($this->formatter->baseMapNotFound());
        }

        $biomeRow = $this->resolver->findBiomeRow((int) $mapRow['biome_id']);
        $summary  = $this->buildingsList->buildSummary((int) $character['id']);
        $payload  = $this->formatter->baseBuildings(
            (int) $mapRow['coordinate_x'],
            (int) $mapRow['coordinate_y'],
            (string) ($biomeRow['name'] ?? '???'),
            $summary['count'],
            $summary['totalTax'],
            $summary['list'],
            $coverageResult,
        );

        return $this->sendPhoto(self::PHOTO_BASE, $payload);
    }

    /**
     * Send sendMessage payload з chat_id injection.
     *
     * @param array<string, mixed> $payload
     */
    private function sendMessage(array $payload): ServerResponse
    {
        $payload['chat_id'] = $this->callbackQuery->getMessage()->getChat()->getId();
        return Request::sendMessage($payload);
    }

    /**
     * Send sendPhoto з base_url($relativePath) encode + chat_id injection.
     *
     * @param array<string, mixed> $payload з 'caption', 'parse_mode', 'reply_markup'
     */
    private function sendPhoto(string $relativeImagePath, array $payload): ServerResponse
    {
        return Request::sendPhoto([
            'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'        => Request::encodeFile(base_url($relativeImagePath)),
            'caption'      => $payload['caption'],
            'parse_mode'   => $payload['parse_mode'],
            'reply_markup' => $payload['reply_markup'],
        ]);
    }
}
