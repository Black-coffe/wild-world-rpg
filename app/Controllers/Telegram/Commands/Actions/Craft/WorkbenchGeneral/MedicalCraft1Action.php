<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class MedicalCraft1Action extends BaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        $text = "*Ты в разделе 💊 Лекарства!* 🏭\n\n"
            . "В этом разделе можно крафтить лекарственные препараты.\n\n"
            . "_Выбирай нужный предмет и приступай к крафту_ 👇\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧴 Антисептик', 'callback_data' => 'antiseptic'],
                    ['text' => '🌡️ Обезболивающий порошок', 'callback_data' => 'painReliefPowder'],
                ],
                [
                    ['text' => '🧪 Укрепляющий эликсир', 'callback_data' => 'strengtheningElixir'],
                    ['text' => '🩹 Повязка', 'callback_data' => 'bandage'],
                ],
                [
                    ['text' => '🫖 Успокоительное', 'callback_data' => 'sedative'],
                    ['text' => '💉 Стимулятор', 'callback_data' => 'stimulator'],
                ],
                [
                    ['text' => '🔋 Регенератор', 'callback_data' => 'regenerator'],
                    ['text' => '🚑 Аптечка базовая', 'callback_data' => 'basicMedKit'],
                ],
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🛠️ Крафтинг', 'callback_data' => 'crafting']
                ]
            ]
        ];

        $imagePath = base_url('uploads/telegram/craft/craft_medications_in_the_field.jpg'); // Укажите актуальный путь к изображению

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
