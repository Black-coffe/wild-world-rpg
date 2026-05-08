<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CraftedItemsLogModel;
use App\Services\Player\TeleportUse\TeleportExecutor;
use App\Services\Player\TeleportUse\TeleportUseMessageFormatter;
use App\Services\Player\TeleportUse\TeleportUseValidator;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class TeleportUseAction extends BaseAction
{
    private TeleportUseValidator $validator;
    private TeleportUseMessageFormatter $formatter;
    private TeleportExecutor $executor;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->validator = new TeleportUseValidator();
        $this->formatter = new TeleportUseMessageFormatter();
        $this->executor  = new TeleportExecutor();
    }

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return $this->sendFormatted($this->formatter->userOrCharacterNotFound());
        }

        // Блокируем, если идёт переезд (BaseRelocation)
        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $this->callbackQuery->getMessage()->getChat()->getId()
        )) {
            return Request::emptyResponse();
        }

        // Смотрим, какую кнопку нажали
        $callbackData = $this->callbackQuery->getData();

        switch ($callbackData) {
            case 'TeleportUse_Portable':
                return $this->usePortableTeleport($character);

            case 'TeleportUse_WithExperience':
                return $this->useExperienceTeleport($character);

            case 'TeleportUse_WithGold':
                return $this->useGoldTeleport($character);

            // === Новый кейс для рюкзака ===
            case 'TeleportUse_Backpack':
                return $this->useBackpackTeleport($character);

            default:
                return $this->sendFormatted($this->formatter->unknownTeleportType());
        }
    }

    /**
     * Телепорт рюкзаком (TeleportBackpack), раз в 60 минут.
     */
    private function useBackpackTeleport(array|\App\Entities\CharacterEntity $character): ServerResponse
    {
        $result = $this->validator->validateBackpack($character);
        if (!$result['ok']) {
            return $this->sendError($result['error']);
        }

        $ctx = $result['context'];
        $backpackLog = $ctx['backpackLog'];
        $claimedCell = $ctx['claimedCell'];
        $mapRow      = $ctx['mapRow'];
        $customData  = $ctx['customData'];

        $craftedItemLogModel = new CraftedItemsLogModel();

        // Ставим новое время использования
        $customData['lastUsedAt'] = (new \DateTime())->format('Y-m-d H:i:s');

        // Обновляем запись: уменьшаем durability_count на 1, либо quantity
        $newDurability = (int) $backpackLog['durability_count'] - 1;

        // Если прочность теперь 0, проверяем qty. Если qty>1, можно списать одну штуку...
        if ($newDurability <= 0) {
            if ((int) $backpackLog['quantity'] > 1) {
                $craftedItemLogModel->update($backpackLog['id'], [
                    'quantity'       => (int) $backpackLog['quantity'] - 1,
                    'durability_count' => 0,
                    'custom_setting'   => null,
                ]);
            } else {
                // Если это был последний экземпляр и прочность ушла в 0, удаляем
                $craftedItemLogModel->delete($backpackLog['id']);
            }
        } else {
            // Иначе просто сохраняем новое значение durability_count
            $craftedItemLogModel->update($backpackLog['id'], [
                'durability_count' => $newDurability,
                'custom_setting'   => json_encode($customData),
            ]);
        }

        // Собственно телепортируем на базу
        $this->executor->teleport((int) $character['id'], $claimedCell, $mapRow);

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return $this->sendFormatted($this->formatter->successBackpack($newDurability));
    }

    /**
     * Common error sender helper для validator's pass-through error strings.
     */
    private function sendError(string $text, ?string $parseMode = null): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        $payload = [
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'    => $text,
        ];
        if ($parseMode !== null) {
            $payload['parse_mode'] = $parseMode;
        }
        return Request::sendMessage($payload);
    }

    /**
     * Send pre-built formatter payload (додає chat_id перед відправкою).
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
     * Телепорт за золото
     */
    private function useGoldTeleport(array|\App\Entities\CharacterEntity $character): ServerResponse
    {
        $result = $this->validator->validateGold($character);
        if (!$result['ok']) {
            return $this->sendError($result['error']);
        }

        $ctx = $result['context'];
        $charRow     = $ctx['charRow'];
        $claimedCell = $ctx['claimedCell'];
        $mapRow      = $ctx['mapRow'];
        $cost        = (int) $ctx['cost'];

        // Списываем золото + телепорт (1 SQL)
        $newGold = (int) $charRow['gold'] - $cost;
        $this->executor->teleport((int) $charRow['id'], $claimedCell, $mapRow, ['gold' => $newGold]);

        return $this->sendFormatted($this->formatter->successGold($cost, $newGold));
    }

    /**
     * Телепорт с помощью портативного устройства.
     * Legacy preserved: всі fail-paths (no item, no log, no base, no map) повертають
     * один generic error «У тебя нет портативного телепорта».
     */
    private function usePortableTeleport(array|\App\Entities\CharacterEntity $character): ServerResponse
    {
        $result = $this->validator->validatePortable($character);
        if (!$result['ok']) {
            return $this->sendError("🤖 Это снова я – *Роби*!\n\n" . $result['error'], 'Markdown');
        }

        $ctx = $result['context'];
        $portableItem = $ctx['portableItem'];
        $portableLog  = $ctx['portableLog'];
        $claimedCell  = $ctx['claimedCell'];
        $mapRow       = $ctx['mapRow'];

        $craftedItemLogModel = new CraftedItemsLogModel();

        // Обновляем местоположение персонажа
        $this->executor->teleport((int) $character['id'], $claimedCell, $mapRow);

        // Логика использования портативного телепорта
        if ((int) $portableLog['durability_count'] > 1) {
            $craftedItemLogModel->update($portableLog['id'], [
                'durability_count' => (int) $portableLog['durability_count'] - 1,
            ]);
        } else {
            if ((int) $portableLog['quantity'] > 1) {
                // Уменьшаем количество на 1 и обновляем счетчик прочности
                $craftedItemLogModel->update($portableLog['id'], [
                    'quantity'         => (int) $portableLog['quantity'] - 1,
                    'durability_count' => $portableItem['durability_count'],
                ]);
            } else {
                // Удаляем запись о телепорте, так как он полностью использован
                $craftedItemLogModel->delete($portableLog['id']);
            }
        }

        return $this->sendFormatted($this->formatter->successPortable());
    }

    /**
     * Телепорт за опыт.
     * Legacy preserved: всі fail-paths повертають єдиний error «У тебя недостаточно опыта».
     */
    private function useExperienceTeleport(array|\App\Entities\CharacterEntity $character): ServerResponse
    {
        $result = $this->validator->validateExperience($character);
        if (!$result['ok']) {
            return $this->sendError("🤖 Это снова я – *Роби*!\n\n" . $result['error'], 'Markdown');
        }

        $ctx = $result['context'];
        $charRow     = $ctx['charRow'];
        $claimedCell = $ctx['claimedCell'];
        $mapRow      = $ctx['mapRow'];

        // Обновляем местоположение персонажа + списываем 1 единицу опыта (1 SQL)
        $this->executor->teleport(
            (int) $charRow['id'],
            $claimedCell,
            $mapRow,
            ['experience' => (float) $charRow['experience'] - 1],
        );

        return $this->sendFormatted($this->formatter->successExperience());
    }
}
