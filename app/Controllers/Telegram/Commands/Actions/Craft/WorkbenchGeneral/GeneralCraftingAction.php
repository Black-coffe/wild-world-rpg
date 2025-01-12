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

        $text = "*Ты в разделе 🔨 Общий крафт!* 🏭\n\n"
            . "В этом разделе можно крафтить, где угодно и когда угодно.\n\n"
            . "_Выбирай направление крафта и если у тебя достаточно ресурсов, ты получишь нужную вещь_ 👇\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🗡️ Оружие', 'callback_data' => 'weapons'],
                    ['text' => '🛠️ Инструменты', 'callback_data' => 'tools'],
                ],
                [
                    ['text' => '🍲 Еда', 'callback_data' => 'food'],
                    ['text' => '🧥 Одежда', 'callback_data' => 'clothes'],
                ],
                [
                    ['text' => '🏗️ Строительство', 'callback_data' => 'construction'],
                    ['text' => '💊 Лекарства', 'callback_data' => 'medicinesCraft1'],
                ],
                [
                    ['text' => '🚗 Транспорт', 'callback_data' => 'transport'],
                    ['text' => '🎲 Разное', 'callback_data' => 'miscellaneous'],
                ],
            ]
        ];

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
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
