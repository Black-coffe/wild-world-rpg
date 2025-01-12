<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CraftedItemsModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CharacterModel;
use App\Models\ClaimedCellModel;
use App\Models\MapModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class TeleportUseAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => "🤖 Это снова я – *Роби*!\n\nПользователь не найден в базе данных или персонаж не определён.",
                'parse_mode' => 'Markdown'
            ]);
        }

        $callbackData = $this->callbackQuery->getData();

        switch ($callbackData) {
            case 'TeleportUse_Portable':
                return $this->usePortableTeleport($character);
            case 'TeleportUse_WithExperience':
                return $this->useExperienceTeleport($character);
            default:
                Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
                return Request::sendMessage([
                    'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                    'text'    => "🤖 Это снова я – *Роби*!\n\nНеизвестная команда телепортации.",
                    'parse_mode' => 'Markdown'
                ]);
        }
    }

    private function usePortableTeleport($character): ServerResponse
    {
        $craftedItemModel = new CraftedItemsModel();
        $craftedItemLogModel = new CraftedItemsLogModel();
        $characterModel = new CharacterModel();
        $claimedCellModel = new ClaimedCellModel();
        $mapModel = new MapModel();

        // Проверяем наличие портативного телепорта
        $portableTeleport = $craftedItemModel->where('name_eng', 'PortableTeleport')->first();

        if ($portableTeleport) {
            $hasPortableTeleport = $craftedItemLogModel
                ->where('crafted_item_id', $portableTeleport['id'])
                ->where('character_id', $character['id'])
                ->first();

            if ($hasPortableTeleport) {
                // Получаем данные о местоположении базы
                $claimedCell = $claimedCellModel->where('character_id', $character['id'])->first();
                if ($claimedCell) {
                    // Получаем данные о ячейке на карте
                    $mapRow = $mapModel->where('cell_number', $claimedCell['map_cell_id'])->first();

                    if ($mapRow) {
                        // Обновляем местоположение персонажа
                        $characterModel->update($character['id'], [
                            'cell_number' => $claimedCell['map_cell_id'],
                            'biome_id' => $mapRow['biome_id']
                        ]);

                        // Логика использования портативного телепорта
                        if ($hasPortableTeleport['durability_count'] > 1) {
                            // Уменьшаем счетчик прочности на 1
                            $craftedItemLogModel->update($hasPortableTeleport['id'], [
                                'durability_count' => $hasPortableTeleport['durability_count'] - 1
                            ]);
                        } else {
                            if ($hasPortableTeleport['quantity'] > 1) {
                                // Уменьшаем количество на 1 и обновляем счетчик прочности
                                $craftedItemLogModel->update($hasPortableTeleport['id'], [
                                    'quantity' => $hasPortableTeleport['quantity'] - 1,
                                    'durability_count' => $portableTeleport['durability_count']
                                ]);
                            } else {
                                // Удаляем запись о телепорте, так как он полностью использован
                                $craftedItemLogModel->delete($hasPortableTeleport['id']);
                            }
                        }

                        $keyboard = [
                            'inline_keyboard' => [
                                [
                                    ['text' => '🏠 База', 'callback_data' => 'Base'],
                                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                                ],
                            ]
                        ];
                        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
                        return Request::sendMessage([
                            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                            'text'    => "🤖 Это снова я – *Роби*!\n\nТы успешно использовал портативный телепорт и телепортировался на базу.",
                            'parse_mode' => 'Markdown',
                            'reply_markup' => json_encode($keyboard),
                        ]);
                    }
                }
            }
        }
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'    => "🤖 Это снова я – *Роби*!\n\nУ тебя нет портативного телепорта.",
            'parse_mode' => 'Markdown'
        ]);
    }

    private function useExperienceTeleport($character): ServerResponse
    {
        $characterModel = new CharacterModel();
        $claimedCellModel = new ClaimedCellModel();
        $mapModel = new MapModel();

        $character = $characterModel->find($character['id']);

        if ($character['experience'] > 1.01) {
            // Получаем данные о местоположении базы
            $claimedCell = $claimedCellModel->where('character_id', $character['id'])->first();
            if ($claimedCell) {
                // Получаем данные о ячейке на карте
                $mapRow = $mapModel->where('cell_number', $claimedCell['map_cell_id'])->first();

                if ($mapRow) {
                    // Обновляем местоположение персонажа
                    $characterModel->update($character['id'], [
                        'cell_number' => $claimedCell['map_cell_id'],
                        'biome_id' => $mapRow['biome_id']
                    ]);

                    // Списываем 1 единицу опыта
                    $characterModel->update($character['id'], [
                        'experience' => $character['experience'] - 1
                    ]);

                    Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
                    return Request::sendMessage([
                        'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                        'text'    => "🤖 Это снова я – *Роби*!\n\nТы успешно использовал опыт для телепортации и телепортировался на базу.",
                        'parse_mode' => 'Markdown',
                    ]);
                }
            }
        }

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'    => "🤖 Это снова я – *Роби*!\n\nУ тебя недостаточно опыта для телепортации.",
            'parse_mode' => 'Markdown'
        ]);
    }
}
