<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class GeneralCraftingAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        $scope = new \App\Services\Tasks\ActionScopeService();
        $text = "*Ты в разделе 🔨 Общий крафт!* 🏭\n\n"
            . "В этом разделе можно крафтить, где угодно и когда угодно.\n\n"
            . $scope->legend(\App\Services\Tasks\ActionScopeService::KIND_CRAFT) . "\n\n"
            . "_Выбирай направление крафта и если у тебя достаточно ресурсов, ты получишь нужную вещь_ 👇\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '💊 Лекарства', 'callback_data' => 'medicinesCraft1'],
                    ['text' => '🛠️ Инструменты', 'callback_data' => 'tools'],
                ],
                [
                    ['text' => '📐 Компоненты', 'callback_data' => 'componentsCraft'],
                    ['text' => '🔬 Верстаки', 'callback_data' => 'WorkbenchChoice'],
                ],
                [
                    ['text' => '🗓 Сезонный крафт', 'callback_data' => 'seasonalCraft'],
                    ['text' => '🔥 Костёр', 'callback_data' => 'cook'],
                ],
                // «🛡 Защита» встаёт в пару к «Очереди»: раздел для предметов,
                // смягчающих мировые события (direction_craft='protection').
                // Заодно уходит одинокая кнопка в ряду — см. правило упаковки рядов.
                [
                    ['text' => '🛡 Защита', 'callback_data' => 'defenseCraft'],
                    ['text' => '📋 Очередь крафта', 'callback_data' => 'craftQueue'],
                ],
            ]
        ];


        $imagePath = base_url('uploads/telegram/craft/general_crafting_img.png'); // Укажите актуальный путь к изображению

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
