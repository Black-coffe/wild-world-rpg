<?php

namespace App\Controllers\Telegram\Commands\Actions;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;

class InventoryAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        $text = "🎒 *Инвентарь*\n\n"
            . "Вы находитесь у входа в свой огромный склад. \nКуда вы хотите отправиться❓\n\n"
            . "Выберите раздел, чтобы увидеть ресурсы 👇\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔄 Добытые ресурсы', 'callback_data' => 'resourcesGathered'],
                    ['text' => '🔨 Крафтовые ресурсы', 'callback_data' => 'resourcesCrafting'],
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions']
                ],
//                [
//                    ['text' => '🤝 Ресурсы от NPC', 'callback_data' => 'resourcesNpc'],
//                    ['text' => '📦 Прочие', 'callback_data' => 'resourcesOther']
//                ],
            ]
        ];

        $imagePath = base_url('uploads/telegram/warehouse_in_the_forest.png'); // Укажите актуальный путь к изображению
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return \App\Services\Notifications\MediaSender::sendPhotoOrText([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'   => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
