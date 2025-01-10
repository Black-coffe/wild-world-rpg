<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CraftedItemsModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CharacterModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class TeleportAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => '🤖 Это снова я – *Роби*!\n\nПользователь не найден в базе данных или персонаж не определён.',
                'parse_mode' => 'Markdown'
            ]);
        }

        $craftedItemModel = new CraftedItemsModel();
        $craftedItemLogModel = new CraftedItemsLogModel();
        $characterModel = new CharacterModel();

        // Проверяем наличие портативного телепорта
        $portableTeleport = $craftedItemModel->where('name_eng', 'PortableTeleport')->first();

        $hasPortableTeleport = false;
        if ($portableTeleport) {
            $hasPortableTeleport = $craftedItemLogModel
                    ->where('crafted_item_id', $portableTeleport['id'])
                    ->where('character_id', $character['id'])
                    ->countAllResults() > 0;
        }

        $character = $characterModel->find($character['id']);
        $hasEnoughExperience = $character['experience'] > 1.01;

        $keyboard = [];

        if ($hasPortableTeleport && $hasEnoughExperience) {
            // Если есть портативный телепорт и достаточно опыта
            $text = "🤖 Это снова я – *Роби*!\n\n"
                . "Ты можешь использовать как *портативный телепорт* так и *телепорт за опыт*.\n\n"
                . "*Портативный телепорт*\n_отнимет 1 единицу у твоего устройства_\n\n"
                . "*Телепорт за опыт*\n_отнимет 1 единицу опыта у твоего персонажа_\n\nЧто выбираешь?";
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '📡 Портативный телепорт', 'callback_data' => 'TeleportUse_Portable'],
                        ['text' => '🚜 Телепорт за опыт', 'callback_data' => 'TeleportUse_WithExperience'],
                    ],
                    [
                        ['text' => '🏠 База', 'callback_data' => 'Base'],
                        ['text' => '👤 Персонаж', 'callback_data' => 'character'],
                    ],
                ]
            ];
        } elseif ($hasPortableTeleport) {
            // Если есть только портативный телепорт
            $text = "🤖 Это снова я – *Роби*!\n\n"
                . "У тебя есть портативный телепорт. Хочешь использовать его для телепортации?";
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '📡 Портативный телепорт', 'callback_data' => 'TeleportUse_Portable'],
                    ],
                    [
                        ['text' => '🏠 База', 'callback_data' => 'Base'],
                        ['text' => '👤 Персонаж', 'callback_data' => 'character'],
                    ],
                ]
            ];
        } elseif ($hasEnoughExperience) {
            // Если достаточно опыта, но нет портативного телепорта
            $text = "🤖 Это снова я – *Роби*!\n\n"
                . "У тебя достаточно опыта для телепортации. Хочешь использовать опыт для телепортации?";
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🚜 Телепорт за опыт', 'callback_data' => 'TeleportUse_WithExperience'],
                    ],
                    [
                        ['text' => '🏠 База', 'callback_data' => 'Base'],
                        ['text' => '👤 Персонаж', 'callback_data' => 'character'],
                    ],
                ]
            ];
        } else {
            // Если нет ни портативного телепорта, ни достаточно опыта
            $text = "🤖 Это снова я – *Роби*!\n\n"
                . "К сожалению, у тебя нет портативного телепорта и недостаточно опыта для телепортации.";
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🚜 Переехать', 'callback_data' => 'move'],
                        ['text' => '👤 Персонаж', 'callback_data' => 'character'],
                    ],
                ]
            ];
        }

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
