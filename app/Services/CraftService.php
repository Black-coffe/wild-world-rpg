<?php

namespace App\Services;

use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class CraftService
{
    /**
     * Показ «меню крафта», повторяя логику из CraftingAction::handle().
     *
     * @param int $chatId Кому отправляем это сообщение
     */
    public function showCraftMenu(int $chatId): ServerResponse
    {
        // Текст точно такой же, как в CraftingAction
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

        // Клавиатура такая же, как в CraftingAction
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔨 Общий крафт', 'callback_data' => 'generalCraft'],
                    ['text' => '🔧 Стандарт крафт', 'callback_data' => 'standardCraft'],
                ],
//                [
//                    ['text' => '⚙️ Профи крафт', 'callback_data' => 'proCraft'],
//                    ['text' => '🏆 Уникальный крафт', 'callback_data' => 'uniqueCraft'],
//                ],
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                ]
            ]
        ];

        // Картинка та же
        $imagePath = base_url('uploads/telegram/craft/crafting_area.png');

        // Возвращаем результат. Здесь "answerCallbackQuery" не делаем:
        // обычно это делают в CallbackqueryCommand, если нужно.
        return Request::sendPhoto([
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
