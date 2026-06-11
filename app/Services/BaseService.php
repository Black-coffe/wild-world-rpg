<?php

namespace App\Services;

use App\Models\ClaimedCellModel;
use App\Services\Bases\BaseBuildingsList;
use App\Services\Bases\BaseLifecycleService;
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
        // ADR-095 Фаза 1b: «активная база» = база на ТЕКУЩЕЙ клетке (а не первая). Чинит
        // мульти-бэйс: стоя на 2-й базе раньше показывалась 1-я / «не на базе».
        $activeCell = $this->claimedCellModel->findActiveCell(
            (int) $characterRow['id'],
            (int) ($characterRow['cell_number'] ?? 0)
        );
        if ($activeCell !== null) {
            // ADR-095 Фаза 2: визит на базу сбрасывает TTL (last_visited_at = now). Делаем
            // ВСЕГДА (даже при dormant) — чтобы к моменту активации last_visited был свежим
            // у активных игроков и их базы не снеслись разом. Безвредно: просто timestamp.
            $this->touchVisit($activeCell);
            $activeCell['last_visited_at'] = date('Y-m-d H:i:s'); // отразить визит для TTL-дисплея (база свежа)
            // ADR-103 Слой 2: игрок открыл экран своей базы — event для онбординг-цели
            // open_base_screen (gated killswitch'ем + уровнем, idempotent).
            $this->recordBaseOpened($characterRow, $chatId);
            return $this->showBaseBuildings($chatId, $characterRow, $activeCell, null, $editMessageId);
        }

        // Не на базе физически — берём первую активную базу для дистанционного просмотра.
        $claimedCell = $this->claimedCellModel->findFirstActiveCell((int) $characterRow['id']);
        if ($claimedCell === null) {
            return $this->showNoBaseInfo($chatId, $characterRow, $editMessageId);
        }

        $coverage = $this->towerCoverageService->checkCoverage($characterRow['id']);
        if ($coverage['isCovered']) {
            // ADR-103 Слой 2: дистанционный просмотр базы под покрытием вышки — тоже
            // «открыл экран базы» (видит постройки/оборону) → засчитываем event.
            $this->recordBaseOpened($characterRow, $chatId);
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

        // ADR-095 Фаза 1b: разрешаем НЕСКОЛЬКО баз — гейтим по лимиту уровня, а не «есть ли
        // уже база» (раньше любая активная база блокировала создание 2-й полностью).
        $level     = is_numeric($characterRow['level'] ?? null) ? (int) $characterRow['level'] : 1;
        $rawCount  = $this->claimedCellModel
            ->where('character_id', $characterRow['id'])
            ->where('status', 'active')
            ->countAllResults();
        $campCount = is_numeric($rawCount) ? (int) $rawCount : 0;
        $baseLimit = new \App\Services\Bases\BaseLimitService();
        if ($campCount >= $baseLimit->maxBasesForLevel($level)) {
            $nextLevel = $baseLimit->nextBaseLevel($campCount);
            $msg = $nextLevel === null
                ? "🤖 У тебя максимально возможное число баз (*{$campCount}*). Больше построить нельзя."
                : "🤖 Лимит баз для уровня *{$level}* достигнут (*{$campCount}*). Следующая база откроется на *{$nextLevel}-м уровне* — каждые *{$baseLimit->levelsPerBase()}* уровней открывают ещё одну.";
            return $this->sendMessage($chatId, ['text' => $msg, 'parse_mode' => 'Markdown']);
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
     * ADR-103 Слой 2: отметить, что игрок открыл экран своей базы (event-флаг для
     * онбординг-цели open_base_screen). Сервис сам гейтит по killswitch'у и уровню и
     * пишет idempotent — безопасно вызывать на каждом открытии базы.
     *
     * @param array<string,mixed>|\App\Entities\CharacterEntity $characterRow
     */
    private function recordBaseOpened(array|\App\Entities\CharacterEntity $characterRow, int $chatId): void
    {
        (new \App\Services\Onboarding\OnboardingChainService())
            ->recordBaseOpened($characterRow, $chatId);

        // E20 (ADR-120): one-shot хинт «Автоматизация» игрокам L12-25 без Мастерской
        // робототехники (окно/killswitch/opt-out — внутри сервиса, дёшево гейтится по level).
        (new \App\Services\Onboarding\OnboardingHintService())
            ->maybeSendAutomationHint($characterRow, $chatId);
    }

    /**
     * ADR-095 Фаза 2: отметить визит на базу (last_visited_at = now) — сбрасывает TTL.
     * Делается всегда (даже при dormant), чтобы last_visited «прогрелся» к активации.
     *
     * @param array<string,mixed> $claimedCell
     */
    private function touchVisit(array $claimedCell): void
    {
        $id = $claimedCell['id'] ?? null;
        if (! is_numeric($id)) {
            return;
        }
        $this->claimedCellModel->update((int) $id, ['last_visited_at' => date('Y-m-d H:i:s')]);
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
        // ADR-095 Фаза 1b: постройки именно активной/просматриваемой базы (этой ячейки).
        // Нарроуим map_cell_id через переменную (feedback_phpstan_no_mixed_to_int_cast).
        $mapCellRaw = $claimedCell['map_cell_id'] ?? null;
        $cellNum    = is_numeric($mapCellRaw) ? (int) $mapCellRaw : 0;
        $summary    = $this->buildingsList->buildSummary((int) $characterRow['id'], $cellNum);

        // W21: декор базы (имя + флаг) и killswitch для кнопки «🎨 Декор».
        // ADR-095 Фаза 1b: декор ИМЕННО показываемой базы (cell-aware), не первой.
        $decorSvc    = new BaseCampDecorService();
        $decor       = $decorSvc->getCampDecor((int) $characterRow['id'], $cellNum);
        $decorEnabled = $decorSvc->enabled();

        $payload = $this->formatter->baseBuildings(
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
        );

        // ADR-095 Фаза 2 (DORMANT): остаток срока жизни базы. daysRemaining = null при
        // ttl_enabled OFF → строка не добавляется (byte-identical в dormant).
        $lifecycle = new BaseLifecycleService();
        $levelInt  = is_numeric($characterRow['level'] ?? null) ? (int) $characterRow['level'] : 1;
        $daysLeft  = $lifecycle->daysRemaining($claimedCell['last_visited_at'] ?? null, $levelInt);
        if ($daysLeft !== null) {
            // На базе (coverageResult === null) визит уже продлил срок → «свежа». Дистанционно
            // (через вышку) — реальный остаток + напоминание заглянуть.
            $onBase = $coverageResult === null;
            if ($daysLeft <= 0) {
                $payload['caption'] .= "\n\n⏳ *База на грани разрушения!* Срок истёк — она исчезнет при ближайшем обходе.";
            } elseif ($onBase) {
                $payload['caption'] .= "\n\n⏳ База свежа — простоит ещё *{$daysLeft}* дн.";
            } else {
                $payload['caption'] .= "\n\n⏳ База простоит ещё *{$daysLeft}* дн. — загляни, чтобы продлить срок.";
            }
        }

        return $this->sendPhoto($chatId, self::PHOTO_BASE, $payload, $editMessageId);
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
