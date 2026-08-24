<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Player\TeleportUse\TeleportExecutor;
use App\Services\Player\TeleportUse\TeleportItemConsumer;
use App\Services\Player\TeleportUse\TeleportUseMessageFormatter;
use App\Services\Player\TeleportUse\TeleportUseValidator;
use App\Services\Tasks\ActiveTasksService;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

class TeleportUseAction extends BaseAction
{
    private TeleportUseValidator $validator;
    private TeleportUseMessageFormatter $formatter;
    private TeleportExecutor $executor;
    private TeleportItemConsumer $itemConsumer;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->validator    = new TeleportUseValidator();
        $this->formatter    = new TeleportUseMessageFormatter();
        $this->executor     = new TeleportExecutor();
        $this->itemConsumer = new TeleportItemConsumer();
    }

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return $this->sendFormatted($this->formatter->userOrCharacterNotFound());
        }

        // Блокируем, если идёт переезд (BaseRelocation)
        if ((new ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $this->callbackQuery->getMessage()->getChat()->getId()
        )) {
            return Request::emptyResponse();
        }

        // ADR-086 Фаза 1b: телепорт требует Солнечную станцию (dormant killswitch).
        // Только НОВЫЕ телепортации; идущие задачи не трогаются (grandfather).
        $solarGate = new \App\Services\Player\AutomationGateService();
        if ($solarGate->blocksLaunch((int) $character['id'])) {
            $this->logRejected((int) $character['id'], 'TELEPORT_USE', 'no_solarstation');
            return $this->sendFormatted(['text' => $solarGate->lockMessage()]);
        }

        $parsed = $this->parseCallbackData($this->callbackQuery->getData());
        if ($parsed === null) {
            return $this->sendFormatted($this->formatter->unknownTeleportType());
        }
        [$kind, $claimedCellId] = $parsed;

        switch ($kind) {
            case 'Portable':
                return $this->usePortableTeleport($character, $claimedCellId);
            case 'WithExperience':
                return $this->useExperienceTeleport($character, $claimedCellId);
            case 'WithGold':
                return $this->useGoldTeleport($character, $claimedCellId);
            case 'Backpack':
                return $this->useBackpackTeleport($character, $claimedCellId);
            default:
                return $this->sendFormatted($this->formatter->unknownTeleportType());
        }
    }

    /**
     * story backpack-teleport-base-choice-02 — callback теперь несёт опциональный
     * хвост `_<claimedCellId>` (`TeleportUse_Backpack_242`). Роутинг на этот класс
     * не меняется: `CallbackqueryCommand` резолвит по первому сегменту до `_`
     * (`TeleportUse`), хвост разбирает сам handle().
     *
     * @return array{0:string,1:?int}|null [kind, claimedCellId] или null для неизвестного kind.
     */
    private function parseCallbackData(string $data): ?array
    {
        if (!preg_match('/^TeleportUse_(Portable|WithExperience|WithGold|Backpack)(?:_(\d+))?$/', $data, $m)) {
            return null;
        }

        return [$m[1], isset($m[2]) ? (int) $m[2] : null];
    }

    /**
     * story backpack-teleport-base-choice-02 — общая ветка для `reason` из validate*()
     * (`no_base` / `choose_base`), которую все 4 способа обрабатывают одинаково.
     * Ничего не списано ни в одном из этих исходов.
     *
     * @param array<string,mixed> $result
     */
    private function handleReason(string $kind, array $result): ?ServerResponse
    {
        if (!isset($result['reason'])) {
            return null;
        }

        if ($result['reason'] === 'choose_base') {
            $bases = $this->extractBases($result['bases'] ?? null);
            // story backpack-teleport-base-choice-04 (ревью №12) — пустой список после
            // extractBases() не рендерит экран выбора («Активных баз: 0»): choose_base
            // приходит от findBaseLocation() только когда активных баз ≥2, так что пустой
            // $bases — защитный случай (например, повреждённые данные), не рабочий путь.
            if ($bases === []) {
                return $this->sendFormatted($this->formatter->baseNotFound());
            }
            return $this->sendFormatted($this->formatter->chooseBase($kind, $bases));
        }

        // reason === 'no_base'
        return $this->sendFormatted($this->formatter->baseNotFound());
    }

    /**
     * Строго типизирует `$result['bases']` из validate*() (сформирован
     * `TeleportUseValidator::listActiveBases()`, но статически он mixed).
     *
     * @return array<int, array<string,mixed>>
     */
    private function extractBases(mixed $bases): array
    {
        if (!is_array($bases)) {
            return [];
        }

        $typed = [];
        foreach ($bases as $base) {
            if (is_array($base)) {
                $typed[] = $base;
            }
        }

        return $typed;
    }

    /**
     * Send pre-built formatter payload (додає chat_id + answerCallbackQuery).
     *
     * @param array<string,mixed> $payload
     */
    private function sendFormatted(array $payload): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        $payload['chat_id'] = $this->callbackQuery->getMessage()->getChat()->getId();
        return Request::sendMessage($payload);
    }

    /**
     * Телепорт рюкзаком (TeleportBackpack), раз в 60 минут.
     */
    private function useBackpackTeleport(array|\App\Entities\CharacterEntity $character, ?int $claimedCellId = null): ServerResponse
    {
        $result = $this->validator->validateBackpack($character, $claimedCellId);
        if (!$result['ok']) {
            if ($response = $this->handleReason('Backpack', $result)) {
                return $response;
            }
            return $this->sendFormatted($this->formatter->error($result['error']));
        }

        $ctx = $result['context'];
        $newDurability = $this->itemConsumer->consumeBackpack($ctx['backpackLog'], $ctx['customData']);
        $this->executor->teleport((int) $character['id'], $ctx['claimedCell'], $ctx['mapRow']);

        return $this->sendFormatted($this->formatter->successBackpack($newDurability));
    }

    /**
     * Телепорт за золото.
     */
    private function useGoldTeleport(array|\App\Entities\CharacterEntity $character, ?int $claimedCellId = null): ServerResponse
    {
        $result = $this->validator->validateGold($character, $claimedCellId);
        if (!$result['ok']) {
            if ($response = $this->handleReason('WithGold', $result)) {
                return $response;
            }
            return $this->sendFormatted($this->formatter->error($result['error']));
        }

        $ctx     = $result['context'];
        $charRow = $ctx['charRow'];
        $cost    = (int) $ctx['cost'];
        $newGold = (int) $charRow['gold'] - $cost;

        // Списываем золото (атомарной дельтой, fix 2026-07-13) + телепорт
        $this->executor->teleport((int) $charRow['id'], $ctx['claimedCell'], $ctx['mapRow'], ['gold' => -$cost]);

        return $this->sendFormatted($this->formatter->successGold($cost, $newGold));
    }

    /**
     * Телепорт с помощью портативного устройства.
     * Legacy preserved: всі fail-paths повертають один generic error.
     */
    private function usePortableTeleport(array|\App\Entities\CharacterEntity $character, ?int $claimedCellId = null): ServerResponse
    {
        $result = $this->validator->validatePortable($character, $claimedCellId);
        if (!$result['ok']) {
            if ($response = $this->handleReason('Portable', $result)) {
                return $response;
            }
            return $this->sendFormatted($this->formatter->error($result['error'], true));
        }

        $ctx = $result['context'];
        $this->executor->teleport((int) $character['id'], $ctx['claimedCell'], $ctx['mapRow']);
        $this->itemConsumer->consumePortable($ctx['portableItem'], $ctx['portableLog']);

        return $this->sendFormatted($this->formatter->successPortable());
    }

    /**
     * Телепорт за опыт.
     * Legacy preserved: всі fail-paths повертають єдиний error.
     */
    private function useExperienceTeleport(array|\App\Entities\CharacterEntity $character, ?int $claimedCellId = null): ServerResponse
    {
        $result = $this->validator->validateExperience($character, $claimedCellId);
        if (!$result['ok']) {
            if ($response = $this->handleReason('WithExperience', $result)) {
                return $response;
            }
            return $this->sendFormatted($this->formatter->error($result['error'], true));
        }

        $ctx     = $result['context'];
        $charRow = $ctx['charRow'];

        // Обновляем местоположение + списываем 1 единицу опыта (атомарной дельтой, fix 2026-07-13)
        $this->executor->teleport(
            (int) $charRow['id'],
            $ctx['claimedCell'],
            $ctx['mapRow'],
            ['experience' => -1],
        );

        return $this->sendFormatted($this->formatter->successExperience());
    }
}
