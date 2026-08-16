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

        switch ($this->callbackQuery->getData()) {
            case 'TeleportUse_Portable':
                return $this->usePortableTeleport($character);
            case 'TeleportUse_WithExperience':
                return $this->useExperienceTeleport($character);
            case 'TeleportUse_WithGold':
                return $this->useGoldTeleport($character);
            case 'TeleportUse_Backpack':
                return $this->useBackpackTeleport($character);
            default:
                return $this->sendFormatted($this->formatter->unknownTeleportType());
        }
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
    private function useBackpackTeleport(array|\App\Entities\CharacterEntity $character): ServerResponse
    {
        $result = $this->validator->validateBackpack($character);
        if (!$result['ok']) {
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
    private function useGoldTeleport(array|\App\Entities\CharacterEntity $character): ServerResponse
    {
        $result = $this->validator->validateGold($character);
        if (!$result['ok']) {
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
    private function usePortableTeleport(array|\App\Entities\CharacterEntity $character): ServerResponse
    {
        $result = $this->validator->validatePortable($character);
        if (!$result['ok']) {
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
    private function useExperienceTeleport(array|\App\Entities\CharacterEntity $character): ServerResponse
    {
        $result = $this->validator->validateExperience($character);
        if (!$result['ok']) {
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
