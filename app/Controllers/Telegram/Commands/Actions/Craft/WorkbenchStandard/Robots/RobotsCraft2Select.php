<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Robots;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class RobotsCraft2Select extends BaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        $text = "*Ты в разделе 🤖 Роботы!* 🏭\n\n"
            . "Раздел крафта роботизированных вещей.\n\n"
            . "_Выбирай нужного робота и приступай к крафту_ 👇\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔍 Исследователь', 'callback_data' => 'robotExplorer'],
                    ['text' => '⛏️ Добытчик', 'callback_data' => 'robotGatherer'],
                ],
                [
                    ['text' => '👁️ Наблюдатель', 'callback_data' => 'robotWatcher'],
                    ['text' => '🤖 Помощник', 'callback_data' => 'robotAssistant'],
                ],
                [
                    ['text' => '🔫 Туррель', 'callback_data' => 'robotTurret'],
                    ['text' => '🏗️ Строитель', 'callback_data' => 'robotBuilder'],
                ],
            ]
        ];

        $imagePath = base_url('uploads/telegram/craft/standard/all_robots.jpg');

        // Ответ на callback запрос, чтобы убрать часики на кнопке
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // Отправляем сообщение с картинкой и клавиатурой
        return \App\Services\Notifications\MediaSender::sendPhotoOrText([
            'chat_id' => $chatId,
            'photo' => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
