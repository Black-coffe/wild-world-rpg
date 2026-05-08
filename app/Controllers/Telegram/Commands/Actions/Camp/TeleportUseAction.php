<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterModel;
use App\Models\CraftedItemsLogModel;
use App\Services\Player\TeleportUse\TeleportUseValidator;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class TeleportUseAction extends BaseAction
{
    private TeleportUseValidator $validator;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->validator = new TeleportUseValidator();
    }

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'       => "🤖 Это снова я – *Роби*!\n\nПользователь или персонаж не найден.",
                'parse_mode' => 'Markdown'
            ]);
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
                Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
                return Request::sendMessage([
                    'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
                    'text'       => "🤖 Это снова я – *Роби*!\n\nНеизвестная команда телепортации.",
                    'parse_mode' => 'Markdown'
                ]);
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
        $characterModel      = new CharacterModel();

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
        $characterModel->update($character['id'], [
            'cell_number' => $claimedCell['map_cell_id'],
            'biome_id'    => $mapRow['biome_id'],
        ]);

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // Сообщаем об успехе
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🏠 База',         'callback_data' => 'Base'],
                    ['text' => '🧑‍🌾 Действия 🛠️','callback_data' => 'characterActions'],
                ],
            ]
        ];

        return Request::sendMessage([
            'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'         => "Ты успешно использовал *Рюкзак телепорт*!\nТеперь у тебя осталось зарядов: *{$newDurability}*.\n\nСледующий телепорт будет доступен через 60 минут.",
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Common error sender helper. Будет використано Step 2 формаatter'ом.
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
        $cost        = $ctx['cost'];

        $characterModel = new CharacterModel();

        // Списываем золото + телепорт
        $newGold = (int) $charRow['gold'] - $cost;
        $characterModel->update($charRow['id'], [
            'gold'        => $newGold,
            'cell_number' => $claimedCell['map_cell_id'],
            'biome_id'    => $mapRow['biome_id'],
        ]);

        // Форматируем цифры с разделителями по 3
        $formattedCost = number_format($cost, 0, '.', ' ');
        $formattedGold = number_format($newGold, 0, '.', ' ');

        $text = "Ты успешно телепортировался за золото!\n\n"
            . "Списано: {$formattedCost} 💰\n"
            . "Остаток золота: {$formattedGold} 💰";

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendMessage([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'       => $text,
            'parse_mode' => 'Markdown',
        ]);
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
        $characterModel      = new CharacterModel();

        // Обновляем местоположение персонажа
        $characterModel->update($character['id'], [
            'cell_number' => $claimedCell['map_cell_id'],
            'biome_id'    => $mapRow['biome_id'],
        ]);

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

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🏠 База', 'callback_data' => 'Base'],
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                ],
            ],
        ];
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'         => "🤖 Это снова я – *Роби*!\n\nТы успешно использовал портативный телепорт и телепортировался на базу.",
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
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

        $characterModel = new CharacterModel();

        // Обновляем местоположение персонажа + списываем 1 единицу опыта
        $characterModel->update($charRow['id'], [
            'cell_number' => $claimedCell['map_cell_id'],
            'biome_id'    => $mapRow['biome_id'],
            'experience'  => (float) $charRow['experience'] - 1,
        ]);

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'       => "🤖 Это снова я – *Роби*!\n\nТы успешно использовал опыт для телепортации и телепортировался на базу.",
            'parse_mode' => 'Markdown',
        ]);
    }
}
