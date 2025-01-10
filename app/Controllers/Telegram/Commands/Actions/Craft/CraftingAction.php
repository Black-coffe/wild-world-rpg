<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;

class CraftingAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        $text = "*🛠️ Ты в разделе крафта!* 🏭\n\n"
            . "Здесь куется твое могущество и величие.\n"
            . "Есть 4 направления в возможностях крафта:\n\n"
            . "*№1 Общий крафт*\n"
            . "_Можно крафтить, где угодно и когда угодно._\n\n"
            . "*№2 Стандартный крафт*\n"
            . "_Крафтить можно вещи, имея в наличии 1-й верстак._\n\n"
            . "*№3 Профи крафт*\n"
            . "_Крафтить можно важные вещи, но с наличием 2-го верстака._\n\n"
            . "*№4 Уникальный крафт*\n"
            . "_Крафтить можно уникальные вещи. Требования:_\n"
            . "- _Своя база_\n"
            . "- _3-го уровня верстак (цех)_\n"
            . "- _50 и выше уровень персонажа_";

//        $keyboard = [
//            'inline_keyboard' => [
//                [
//                    ['text' => '🔨 Общий крафт', 'callback_data' => 'generalCraft'],
//                    ['text' => '🔧 Стандарт крафт', 'callback_data' => 'standardCraft'],
//                ],
//                [
//                    ['text' => '⚙️ Профи крафт', 'callback_data' => 'proCraft'],
//                    ['text' => '🏆 Уникальный крафт', 'callback_data' => 'uniqueCraft'],
//                ],
//                [
//                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
//                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
//                ]
//            ]
//        ];

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔨 Общий крафт', 'callback_data' => 'generalCraft'],
                    ['text' => '🔧 Стандарт крафт', 'callback_data' => 'standardCraft'],
                ],
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                ],
            ]
        ];

        $imagePath = base_url('uploads/telegram/craft/crafting_area.png'); // Укажите актуальный путь к изображению

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
