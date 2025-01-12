<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\ClaimedCellModel;
use App\Models\MapModel;
use App\Models\BiomeModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class CampingAction extends BaseAction
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

        $claimedCellModel = new ClaimedCellModel();
        $mapModel = new MapModel();
        $biomeModel = new BiomeModel();

        // Проверяем количество лагерей у персонажа
        $claimedCells = $claimedCellModel->where('character_id', $character['id'])->findAll();

        if (count($claimedCells) >= 1 && $character['level'] <= 100) {
            // Если у персонажа уже есть лагерь и уровень меньше или равен 100
            $currentCamp = $claimedCells[0];
            $mapRow = $mapModel->where('cell_number', $currentCamp['map_cell_id'])->first();
            $biomeName = $biomeModel->where('id', $mapRow['biome_id'])->first()['name'];
            $coordinates = [
                'x' => $mapRow['coordinate_x'], // Замените на реальные координаты из таблицы карты
                'y' => $mapRow['coordinate_y']
            ];
            $builtAt = $currentCamp['claimed_at'];

            $text = "🤖 Это снова я – *Роби*!\n\n"
                . "У тебя уже есть разбитый лагерь, который находится в таком месте:\n\n"
                . "📍 *Координаты*: x={$coordinates['x']} y={$coordinates['y']}\n"
                . "🌍 *Биом*: {$biomeName}\n"
                . "📅 *Построен был*: {$builtAt}\n\n"
                . "Что ты хочешь сделать?";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '📡 Телепорт', 'callback_data' => 'TeleportToCamp'],
                        ['text' => '🏠 База', 'callback_data' => 'Base'],
                    ],
                ]
            ];

            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => $text,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        }

        // Если лагерей нет или уровень персонажа больше 100 и у него один лагерь
        $mapRow = $mapModel->where('cell_number', $character['cell_number'])->first();
        $biomeName = $biomeModel->where('id', $mapRow['biome_id'])->first()['name'];
        $coordinates = [
            'x' => $mapRow['coordinate_x'], // Замените на реальные координаты из таблицы карты
            'y' => $mapRow['coordinate_y']
        ];

        $newCampData = [
            'character_id' => $character['id'],
            'map_cell_id' => $character['cell_number'],
            'claimed_at' => date('Y-m-d H:i:s'),
            'status' => 'active',
        ];

        $claimedCellModel->save($newCampData);

        $text = "🤖 Это снова я – *Роби*!\n\n"
            . "Ты успешно разбил лагерь на территории:\n\n"
            . "📍 *Координаты*: x={$coordinates['x']} y={$coordinates['y']}\n"
            . "🌍 *Биом*: {$biomeName}\n\n"
            . "Теперь ты можешь строить все, что доступно в разделе \"постройка\". Что ты хочешь сделать дальше?";

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
            'text'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
