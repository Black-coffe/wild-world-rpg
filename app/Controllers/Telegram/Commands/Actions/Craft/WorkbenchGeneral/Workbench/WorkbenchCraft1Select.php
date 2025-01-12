<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Workbench;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class WorkbenchCraft1Select extends BaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        $text = "*Ты в разделе 🔬 Верстаки!* 🏭\n\n"
            . "Этот раздел для крафта одного из трех уникальных верстаков.\n\n"
            . "_Выбирай нужный верстак и приступай к крафту_ 👇\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔬 Верстак 1', 'callback_data' => 'workbenchOne'],
                    ['text' => '🔬 Верстак 2', 'callback_data' => 'workbenchTwo'],
                    ['text' => '🔬 Верстак 3', 'callback_data' => 'workbenchFree'],
                ],
            ]
        ];

        $imagePath = base_url('uploads/telegram/workbench/workbenchSelect.jpg');

        // Ответ на callback запрос, чтобы убрать часики на кнопке
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // Отправляем сообщение с картинкой и клавиатурой
        return Request::sendPhoto([
            'chat_id' => $chatId,
            'photo' => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
