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
            . "Этот раздел для крафта верстаков разного тира.\n\n"
            . "_Выбирай нужный верстак и приступай к крафту_ 👇\n";

        // S16 (v0.51.198): «Верстак 3» теперь маршрутизируется на
        // WorkbenchProfessionalAction (T3, ADR-026). T2 entry — отдельный
        // путь через `standardCraft` callback. Кнопка-заглушка `workbenchTwo`
        // удалена (callback не имел handler'а).
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔬 Верстак 1 (T1)', 'callback_data' => 'workbenchOne'],
                    ['text' => '🛠️ Верстак 3 (T3)', 'callback_data' => 'workbenchProfessional'],
                ],
            ]
        ];

        $imagePath = base_url('uploads/telegram/workbench/workbenchSelect.jpg');

        // Ответ на callback запрос, чтобы убрать часики на кнопке
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // Отправляем сообщение с картинкой и клавиатурой
        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'chat_id' => $chatId,
            'photo' => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
