<?php

namespace App\Services;

use App\Models\ClaimedCellModel;
use App\Services\Bases\BaseLocationResolver;
use App\Services\Bases\BaseScopeResolver;
use App\Services\Bases\BaseScreenService;
use App\Services\Bases\BaseServiceMessageFormatter;
use App\Services\Bases\CampCheckService;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * Класс BaseService — Telegram-рендер экранов базы/лагеря.
 *
 * w2-n4-base-01 (ADR-190): выбор базы, модель экрана и «открыл базу» (визит + онбординг) живут в
 * ядре {@see BaseScreenService}, общем с вебом; здесь — прежние тексты и кнопки
 * ({@see BaseServiceMessageFormatter}) и отправка.
 *
 * Public API:
 *   showBaseInfo($chatId, $character) — main flow для 'Base' callback
 *     (через ShowBaseInfoAction). Ветки: no-base / picker / on-base /
 *     tower-covered / off-base / unavailable.
 *   showCampCreation($chatId, $character) — flow для 'Camp' callback
 *     (через CampShowCreationAction).
 *
 * @phpstan-import-type Overview from BaseScreenService
 */
class BaseService
{
    private const PHOTO_NOT_ON_BASE = 'uploads/telegram/camp/an_empty_area.jpg';
    private const PHOTO_BASE        = 'uploads/telegram/camp/base_with_its_buildings.jpg';

    protected ClaimedCellModel $claimedCellModel;
    protected CampCheckService $campCheck;
    protected BaseServiceMessageFormatter $formatter;
    protected BaseLocationResolver $resolver;

    public function __construct()
    {
        $this->claimedCellModel = new ClaimedCellModel();
        $this->campCheck        = new CampCheckService();
        $this->formatter        = new BaseServiceMessageFormatter();
        $this->resolver         = new BaseLocationResolver();
    }

    /**
     * Показывает информацию о базе. w2-n4-base-01 (ADR-190): какую базу показать и что на ней —
     * решает ядро {@see BaseScreenService}; здесь только рендер прежних текстов и кнопок.
     * `$baseId` (из суффикса `Base_b<id>`) — выбор с пикера, ядро перепроверяет доступность.
     * `$baseId === null` — прежнее правило: своя клетка / единственная база / пикер при ≥2.
     */
    public function showBaseInfo(int $chatId, array|\App\Entities\CharacterEntity $characterRow, ?int $editMessageId = null, ?int $baseId = null): ServerResponse
    {
        $characterId = $this->characterIdOf($characterRow);
        // Модель баз этого сервиса — и модель ядра (шов тестов: подмена `claimedCellModel`).
        $screen      = new BaseScreenService($this->claimedCellModel);
        $resolved    = $screen->resolve($characterId, $baseId);

        switch ($resolved['state']) {
            case BaseScreenService::STATE_UNAVAILABLE:
                return $this->sendMessage($chatId, ['text' => $resolved['text']], $editMessageId);
            case BaseScreenService::STATE_NO_BASE:
                return $this->showNoBaseInfo($chatId, $characterRow, $editMessageId);
            case BaseScreenService::STATE_PICKER:
                return $this->sendMessage($chatId, $this->formatter->basePicker($resolved['bases']), $editMessageId);
            case BaseScreenService::STATE_FAR:
                return $this->showNotOnBaseInfo($chatId, $screen->overview($characterId, $resolved['base_id']), $editMessageId);
        }

        // Визит (на самой базе) и онбординг-событие с подсказками — до экрана, как раньше.
        $screen->open($characterId, $resolved['base_id'], $chatId);

        return $this->showBaseBuildings(
            $chatId,
            $screen->overview($characterId, $resolved['base_id'], $resolved['coverage']),
            $editMessageId
        );
    }

    /** @param array<string,mixed>|\App\Entities\CharacterEntity $characterRow */
    private function characterIdOf(array|\App\Entities\CharacterEntity $characterRow): int
    {
        $raw = $characterRow['id'] ?? null;
        return is_numeric($raw) ? (int) $raw : 0;
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

        $response = $this->sendMessage($chatId, $this->formatter->noBaseInfo(
            $coordX, $coordY, (string) $biomeName, (string) $biomeDesc, $dangerLevel, $survivalDiff
        ), $editMessageId);

        // ADR-103 just-in-time: новичок смотрит на экран «у тебя нет базы» — самый
        // on-point момент подсказать, как разбить первую базу. One-shot (action_log),
        // не дублирует movement-триггер (MoveCharacterToDirectionAction).
        (new \App\Services\Onboarding\OnboardingHintService())
            ->maybeSendFirstBaseTip($characterRow, $chatId);

        return $response;
    }

    /**
     * У игрока есть база, но он НЕ на ней и сигнал её Вышки не дотягивается.
     *
     * @param Overview|null $overview {@see BaseScreenService::overview()}
     */
    protected function showNotOnBaseInfo(int $chatId, ?array $overview, ?int $editMessageId = null): ServerResponse
    {
        if ($overview === null || $overview['base']['x'] === null || $overview['base']['y'] === null) {
            return $this->sendMessage($chatId, $this->formatter->notOnBaseMapError(), $editMessageId);
        }
        $base = $overview['base'];

        return $this->sendPhoto(
            $chatId,
            self::PHOTO_NOT_ON_BASE,
            $this->formatter->notOnBasePhysically($base['x'], $base['y'], $base['biome']),
            $editMessageId,
        );
    }

    /**
     * Постройки базы (игрок на ней или под сигналом её Вышки) — рендер модели ядра.
     *
     * @param Overview|null $overview {@see BaseScreenService::overview()}
     */
    protected function showBaseBuildings(int $chatId, ?array $overview, ?int $editMessageId = null): ServerResponse
    {
        if ($overview === null) {
            return $this->sendMessage($chatId, ['text' => BaseScopeResolver::TEXT_UNAVAILABLE], $editMessageId);
        }
        $base = $overview['base'];
        if ($base['x'] === null || $base['y'] === null) {
            return $this->sendMessage($chatId, $this->formatter->baseMapNotFoundError(), $editMessageId);
        }

        $list = '';
        foreach ($overview['buildings'] as $row) {
            $list .= "- {$row['name']}
";
        }

        $coverage       = $overview['coverage'];
        $coverageResult = is_array($coverage) ? [
            'isCovered'      => $coverage['covered'],
            'towerLevel'     => $coverage['tower_level'],
            'distanceToBase' => $coverage['distance'],
            'maxCoverage'    => $coverage['max'],
        ] : null;

        $decor        = $base['decor'];
        $decorEnabled = $base['decor_enabled'];

        $payload = $this->formatter->baseBuildings(
            $base['x'],
            $base['y'],
            $base['biome'],
            $base['count'],
            $base['tax_total'],
            $list,
            $coverageResult,
            $decor['name'],
            $decor['flag'],
            $decorEnabled,
            $decorEnabled ? $decor : null, // W22: interior items только при включённом killswitch
            $base['id'],
        );

        // ADR-095 Фаза 2 (DORMANT): остаток срока жизни базы; null при выключенном TTL — строки нет.
        $daysLeft = $base['days_left'];
        if ($daysLeft !== null) {
            // На базе визит уже продлил срок → «свежа». Дистанционно — реальный остаток + напоминание.
            if ($daysLeft <= 0) {
                $payload['caption'] .= "

⏳ *База на грани разрушения!* Срок истёк — она исчезнет при ближайшем обходе.";
            } elseif ($base['on_base']) {
                $payload['caption'] .= "

⏳ База свежа — простоит ещё *{$daysLeft}* дн.";
            } else {
                $payload['caption'] .= "

⏳ База простоит ещё *{$daysLeft}* дн. — загляни, чтобы продлить срок.";
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
