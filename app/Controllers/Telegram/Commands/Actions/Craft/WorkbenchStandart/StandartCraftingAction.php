<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class StandartCraftingAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        log_message('debug', "StandartCraftingAction handle: " . print_r($chatId, true));
        $text = "*Ты в разделе 🔧 Стандартный крафт* 🏭\n\n"
            . "В этом разделе можно крафтить, находясь на базе.\n\n"
            . "_Выбирай направление крафта и если у тебя достаточно ресурсов, ты получишь нужную вещь_ 👇\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🤖 Роботы', 'callback_data' => 'robotsCraft2'],
                ],
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🛠️ Крафт', 'callback_data' => 'crafting']
                ]
            ]
        ];


        $imagePath = base_url('uploads/telegram/craft/general_crafting_img.png'); // Укажите актуальный путь к изображению

        // Ответ на callback запрос, чтобы убрать часики на кнопке
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // Отправляем сообщение с картинкой и клавиатурой
        return Request::sendPhoto([
            'chat_id' => $chatId,
            'photo' => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
           // 'reply_markup' => json_encode($keyboard),
        ]);
    }
}
