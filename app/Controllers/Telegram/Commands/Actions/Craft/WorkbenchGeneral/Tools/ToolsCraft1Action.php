<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Tools;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class ToolsCraft1Action extends BaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        $text = "*Ты в разделе 🛠️ Инструменты!* 🏭\n\n"
            . "В этом разделе можно крафтить инструменты.\n\n"
            . "_Выбирай нужный предмет и приступай к крафту_ 👇\n";

        $keyboard = [
            'inline_keyboard' => [

            ]
        ];

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🪓 Топор дровосека', 'callback_data' => 'lumberjackAxe'],
                    ['text' => '⛏️ Каменная кирка', 'callback_data' => 'stonePickaxe'],
                ],
                [
                    ['text' => '🥄 Железная лопата', 'callback_data' => 'ironShovel'],
                    ['text' => '🎣 Удочка', 'callback_data' => 'fishingRod'],
                ],
                [
                    ['text' => '🌾 Мотыга', 'callback_data' => 'hoe'],
                    ['text' => '🔪 Складной нож', 'callback_data' => 'foldingKnife'],
                ],
                [
                    ['text' => '⛏️ Железная кирка', 'callback_data' => 'ironPickaxe'],
                    ['text' => '🪛 Монтировка', 'callback_data' => 'tireIron'],
                ],
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🛠️ Крафт', 'callback_data' => 'crafting']
                ],
            ]
        ];

        $imagePath = base_url('uploads/telegram/craft/tools_standing_by_an_old_fence_in_the_wild.jpg'); // Укажите актуальный путь к изображению

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
